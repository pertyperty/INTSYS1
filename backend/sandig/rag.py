import hashlib
import re
import stat
import unicodedata
from dataclasses import dataclass
from datetime import date
from math import isfinite
from pathlib import Path
from uuid import uuid4

import httpx
from psycopg.types.json import Jsonb

from sandig.catalog import ACTIVE, CatalogError, Manifest


FILE_LIMIT = 512 * 1024
CHUNK_SIZE = 900
CHUNK_LIMIT = 512
BATCH = 32
STOP_WORDS = set('about after before could does during have how into should that their them there these this what when where which will with would your mga para paano dapat iyong kapag tungkol'.split())


@dataclass(frozen=True)
class Chunk:
    section: str | None
    passage: str


def read_regular(path: Path, max_bytes: int) -> bytes:
    if not stat.S_ISREG(path.stat().st_mode):
        raise CatalogError('Use a regular local file.')
    with path.open('rb') as file:
        content = file.read(max_bytes + 1)
    if not content or len(content) > max_bytes:
        raise CatalogError('File is empty or exceeds its byte limit.')
    return content


def extract(path: Path) -> tuple[str, list[Chunk]]:
    if path.suffix.lower() not in {'.txt', '.md'}:
        raise CatalogError('Only UTF-8 TXT and Markdown are supported.')
    raw = read_regular(path, FILE_LIMIT)
    try:
        text = unicodedata.normalize('NFC', raw.decode('utf-8-sig')).replace('\r\n', '\n').replace('\r', '\n')
    except UnicodeDecodeError:
        raise CatalogError('Extraction requires UTF-8 text.') from None
    if any(unicodedata.category(character) in {'Cc', 'Cs'} and character not in '\n\t\f' for character in text):
        raise CatalogError('Document contains unsupported control characters.')
    chunks = []
    section = None
    paragraphs = []

    def flush():
        current = ''
        for word in '\n'.join(paragraphs).split():
            if len(word) > CHUNK_SIZE:
                raise CatalogError('Extraction contains an excessively long unbroken string.')
            if current and len(current) + len(word) + 1 > CHUNK_SIZE:
                chunks.append(Chunk(section, current))
                current = ''
            current = f'{current} {word}' if current else word
        if current:
            chunks.append(Chunk(section, current))
        paragraphs.clear()

    for line in text.splitlines():
        head = re.fullmatch(r'#{1,6}\s+(.+)', line.strip()) if path.suffix.lower() == '.md' else None
        if head:
            flush()
            section = head[1].strip()[:240]
        elif not line.strip():
            flush()
        else:
            paragraphs.append(line)
    flush()
    if not chunks or len(chunks) > CHUNK_LIMIT:
        raise CatalogError('Extraction yielded no passages or too many chunks.')
    return hashlib.sha256(raw).hexdigest(), chunks


def stage(connection, manifest: Manifest, digest: str, chunks: list[Chunk]) -> None:
    connection.execute('''INSERT INTO sandig_source_versions (source_id, version, metadata, content_sha256)
                          VALUES (%s, %s, %s, %s)''',
                       (manifest.source_id, manifest.version, Jsonb(manifest.model_dump(mode='json')), digest))
    with connection.cursor() as cursor:
        cursor.executemany('''INSERT INTO sandig_source_chunks VALUES (%s, %s, %s, %s, %s)''', [
            (manifest.source_id, manifest.version, ordinal, chunk.section, chunk.passage)
            for ordinal, chunk in enumerate(chunks)
        ])


def version(connection, source_id: str, version_id: str) -> dict:
    row = connection.execute('SELECT * FROM sandig_source_versions WHERE source_id = %s AND version = %s',
                             (source_id, version_id)).fetchone()
    if row is None:
        raise CatalogError('Source version does not exist.')
    return row


def passages(connection, source_id: str, version_id: str) -> list[dict]:
    return connection.execute('''SELECT ordinal, section, passage FROM sandig_source_chunks
                                 WHERE source_id = %s AND version = %s ORDER BY ordinal''',
                              (source_id, version_id)).fetchall()


class OllamaEmbedder:
    def __init__(self, client: httpx.Client, model: str, cpu_only: bool):
        self.client = client
        self.model = model
        self.options = {'num_ctx': 2048}
        if cpu_only:
            self.options['num_gpu'] = 0
        self.key = self.fingerprint()

    def fingerprint(self) -> str:
        response = self.client.get('/api/tags')
        response.raise_for_status()
        try:
            digest = next(model['digest'] for model in response.json()['models'] if model['name'] == self.model)
            if re.fullmatch(r'[a-f0-9]{64}', digest) is None:
                raise ValueError
        except (KeyError, StopIteration, TypeError, ValueError):
            raise CatalogError('Configured embedding tag or digest is unavailable.') from None
        return hashlib.sha256(f'{self.model}:{digest}:ctx2048'.encode()).hexdigest()

    def embed(self, texts: list[str]) -> list[list[float]]:
        response = self.client.post('/api/embed', json={'model': self.model, 'input': texts, 'truncate': False,
                                                       'keep_alive': '1m', 'options': self.options})
        response.raise_for_status()
        try:
            vectors = response.json()['embeddings']
            if (not isinstance(vectors, list) or len(vectors) != len(texts) or not vectors
                    or not isinstance(vectors[0], list) or not 1 <= len(vectors[0]) <= 8192
                    or any(not isinstance(vector, list) or len(vector) != len(vectors[0])
                           or not all(type(value) in (int, float) and isfinite(value) for value in vector)
                           or not any(vector) for vector in vectors)):
                raise ValueError
        except (KeyError, TypeError, ValueError, OverflowError):
            raise CatalogError('Embedding service returned invalid vectors.') from None
        return vectors


def publish(connection, collection, embedder, source_id: str, version_id: str,
            reviewer: str | None = None, approval: str | None = None) -> str:
    row = version(connection, source_id, version_id)
    reindexing = reviewer is None and approval is None
    if row['state'] != ('active' if reindexing else 'draft'):
        raise CatalogError('Publish a draft or reindex an active version.')
    if date.fromisoformat(row['metadata']['review_until']) < date.today():
        raise CatalogError('Review window expired; register a new reviewed version.')
    if not reindexing and not all(value and 1 <= len(value.strip()) <= 240 for value in (reviewer, approval)):
        raise CatalogError('A reviewer and approval reference are required.')
    chunks = passages(connection, source_id, version_id)
    if not chunks:
        raise CatalogError('Version has no extracted passages.')
    generation = uuid4()
    for start in range(0, len(chunks), BATCH):
        batch = chunks[start:start + BATCH]
        vectors = embedder.embed([chunk['passage'] for chunk in batch])
        collection.add(ids=[f"{generation}:{chunk['ordinal']}" for chunk in batch], embeddings=vectors,
                       metadatas=[{'generation': str(generation)} for chunk in batch])
    if embedder.fingerprint() != embedder.key:
        raise CatalogError('Embedding tag changed during indexing; no version was published.')
    connection.execute("UPDATE sandig_source_versions SET state = 'inactive' WHERE source_id = %s AND state = 'active'",
                       (source_id,))
    connection.execute('''UPDATE sandig_source_versions SET state = 'active', generation = %s, embedding_key = %s,
                          approved_by = COALESCE(%s, approved_by), approval_reference = COALESCE(%s, approval_reference),
                          approved_at = CASE WHEN %s THEN approved_at ELSE now() END
                          WHERE source_id = %s AND version = %s''',
                       (generation, embedder.key, reviewer, approval, reindexing, source_id, version_id))
    return str(generation)


def deactivate(connection, source_id: str, version_id: str) -> None:
    result = connection.execute("UPDATE sandig_source_versions SET state = 'inactive' WHERE source_id = %s "
                                "AND version = %s AND state = 'active'", (source_id, version_id))
    if result.rowcount != 1:
        raise CatalogError('Only an active source version can be deactivated.')


def cleanup(connection, collection, embedding_key: str) -> int:
    active = {str(row['generation']) for row in connection.execute(
        "SELECT generation FROM sandig_source_versions WHERE state = 'active' AND embedding_key = %s",
        (embedding_key,),
    )}
    deleted = 0
    offset = 0
    while True:
        page = collection.get(limit=200, offset=offset, include=['metadatas'])
        if not page['ids']:
            return deleted
        stale = [identifier for identifier, metadata in zip(page['ids'], page['metadatas'], strict=True)
                 if not metadata or metadata.get('generation') not in active]
        if stale:
            collection.delete(ids=stale)
            deleted += len(stale)
            offset = 0
        else:
            offset += len(page['ids'])


def terms(text: str) -> set[str]:
    return set(re.findall(r'[^\W_]{4,}', text.casefold())) - STOP_WORDS


def retrieve(connection, collection, embedder, question: str, jurisdiction: str | None, hazard: str | None) -> list[dict]:
    rows = connection.execute(f'''SELECT generation, metadata FROM sandig_source_versions WHERE {ACTIVE}
                              AND embedding_key = %s''', (embedder.key,)).fetchall()
    eligible = {str(row['generation']): row['metadata'] for row in rows
                if (jurisdiction is None or row['metadata']['jurisdiction'] == jurisdiction)
                and (hazard is None or hazard in row['metadata']['hazards'])}
    query_terms = terms(question)
    if not eligible or len(query_terms) < 2:
        return []
    vectors = embedder.embed([question])
    if embedder.fingerprint() != embedder.key:
        raise CatalogError('Embedding tag changed during retrieval.')
    candidates = collection.query(query_embeddings=vectors, n_results=16,
                                  where={'generation': {'$in': list(eligible)}}, include=['distances'])
    matches = []
    for identifier, distance in zip(candidates['ids'][0], candidates['distances'][0], strict=True):
        generation, _, ordinal = identifier.partition(':')
        metadata = eligible.get(generation)
        if metadata is None or not ordinal.isascii() or not ordinal.isdecimal() or len(ordinal) > 3:
            continue
        if not isfinite(distance) or distance > 0.45:
            continue
        chunk = connection.execute('''SELECT section, passage FROM sandig_source_chunks
                                      WHERE source_id = %s AND version = %s AND ordinal = %s''',
                                   (metadata['source_id'], metadata['version'], int(ordinal))).fetchone()
        if chunk is not None and len(terms(chunk['passage']) & query_terms) >= 2:
            matches.append({'kind': 'indexed_document', **{key: metadata[key] for key in (
                'source_id', 'version', 'title', 'publisher', 'url', 'publication_date',
            )}, **chunk})
        if len(matches) == 3:
            break
    return matches
