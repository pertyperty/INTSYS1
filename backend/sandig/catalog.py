import re
from datetime import date
from typing import Annotated, Literal
from urllib.parse import urlsplit

import psycopg
from psycopg.rows import dict_row
from pydantic import BaseModel, ConfigDict, Field, StringConstraints, field_validator, model_validator


SOURCE_ID = r'[a-z0-9][a-z0-9-]{0,63}'
Slug = Annotated[str, StringConstraints(pattern=f'^{SOURCE_ID}$')]
Label = Annotated[str, StringConstraints(strip_whitespace=True, min_length=1, max_length=240)]
HttpsUrl = Annotated[str, Field(pattern=r'^https://', json_schema_extra={'format': 'uri'})]


class CatalogError(Exception):
    pass


class Source(BaseModel):
    source_id: str
    title: str
    publisher: str
    url: HttpsUrl | None = None
    publication_date: date | None = None
    retrieval_date: date | None = None
    jurisdiction: str | None = None
    hazards: list[str] = Field(default_factory=list)
    version: str
    verification_status: Literal['approved']
    usage_restrictions: str | None = None


class Manifest(BaseModel):
    model_config = ConfigDict(extra='forbid')

    source_id: Slug
    version: Annotated[str, StringConstraints(pattern=r'^[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}$')]
    title: Label
    publisher: Label
    url: Annotated[HttpsUrl, Field(max_length=2048)]
    publication_date: date | None = None
    retrieval_date: date
    jurisdiction: Slug
    hazards: Annotated[list[Slug], Field(min_length=1, max_length=12)]
    usage_restrictions: Label
    review_until: date
    chat_language: Literal['en', 'fil'] | None = None

    @field_validator('url')
    @classmethod
    def valid_source_url(cls, value: str) -> str:
        parts = urlsplit(value)
        if (not parts.hostname or not re.fullmatch(r'[a-zA-Z0-9.-]+', parts.hostname)
                or parts.username is not None or any(character.isspace() or ord(character) < 32 for character in value)
                or '\\' in value or parts.port not in (None, 443)):
            raise ValueError('Specify an HTTPS publisher URL without credentials.')
        return value

    @model_validator(mode='after')
    def valid_dates(self):
        if self.retrieval_date > date.today() or self.review_until < self.retrieval_date:
            raise ValueError('Invalid retrieval or review dates.')
        if self.publication_date and self.publication_date > self.retrieval_date:
            raise ValueError('Publication date cannot follow retrieval.')
        return self


SCHEMA = '''
CREATE TABLE IF NOT EXISTS sandig_catalog_identity (
    singleton boolean PRIMARY KEY DEFAULT true CHECK (singleton),
    catalog_id uuid NOT NULL DEFAULT gen_random_uuid()
);
INSERT INTO sandig_catalog_identity DEFAULT VALUES ON CONFLICT DO NOTHING;
CREATE TABLE IF NOT EXISTS sandig_source_versions (
    source_id text NOT NULL,
    version text NOT NULL,
    metadata jsonb NOT NULL,
    content_sha256 text NOT NULL,
    state text NOT NULL DEFAULT 'draft' CHECK (state IN ('draft', 'active', 'inactive')),
    generation uuid UNIQUE,
    embedding_key text,
    approved_by text,
    approval_reference text,
    approved_at timestamptz,
    PRIMARY KEY (source_id, version),
    CHECK (state <> 'active' OR (generation IS NOT NULL AND embedding_key IS NOT NULL
        AND approved_by IS NOT NULL AND approval_reference IS NOT NULL AND approved_at IS NOT NULL))
);
CREATE UNIQUE INDEX IF NOT EXISTS sandig_one_active_version
    ON sandig_source_versions (source_id) WHERE state = 'active';
CREATE TABLE IF NOT EXISTS sandig_source_chunks (
    source_id text NOT NULL,
    version text NOT NULL,
    ordinal integer NOT NULL CHECK (ordinal >= 0),
    section text,
    passage text NOT NULL,
    PRIMARY KEY (source_id, version, ordinal),
    FOREIGN KEY (source_id, version) REFERENCES sandig_source_versions (source_id, version)
);
'''


ACTIVE = "state = 'active' AND (metadata->>'review_until')::date >= CURRENT_DATE"


def connect(database_url: str, *, timeout: int = 5):
    return psycopg.connect(
        database_url, connect_timeout=timeout, row_factory=dict_row,
    )


def public_source(metadata: dict) -> Source:
    manifest = Manifest.model_validate(metadata)
    return Source.model_validate({**manifest.model_dump(), 'verification_status': 'approved'})


def list_sources(database_url: str, after: str, limit: int) -> list[Source]:
    with connect(database_url) as connection:
        connection.execute('SET LOCAL statement_timeout = 3000')
        rows = connection.execute(
            f'''SELECT metadata FROM sandig_source_versions WHERE {ACTIVE} AND source_id COLLATE "C" > %s
                ORDER BY source_id COLLATE "C" LIMIT %s''', (after, limit),
        ).fetchall()
    return [public_source(row['metadata']) for row in rows]
