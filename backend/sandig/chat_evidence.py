import heapq
import re

from sandig.catalog import ACTIVE, CatalogError, Manifest, connect
from sandig.rag import terms


SCAN_LIMIT = 2000
KIT_TERMS = re.compile(r'\b(?:go[\s-]?bags?|emergency (?:supply )?kits?)\b', re.IGNORECASE)


def evidence_terms(text: str) -> set[str]:
    return terms(text) | ({'emergency', 'kit'} if KIT_TERMS.search(text) else set())


def find_passages(database_url: str, question: str, hazard: str, language: str, limit: int) -> list[dict]:
    query_terms = evidence_terms(question)
    if not database_url or len(query_terms) < 2:
        return []
    with connect(database_url, timeout=2) as connection:
        connection.execute('SET LOCAL statement_timeout = 1500')
        rows = connection.execute(
            f'''SELECT source.metadata, chunk.section, chunk.passage
                FROM sandig_source_versions AS source
                JOIN sandig_source_chunks AS chunk USING (source_id, version)
                WHERE {ACTIVE} AND metadata->>'chat_language' = %s
                  AND metadata->>'jurisdiction' = 'philippines'
                  AND (metadata->'hazards') @> jsonb_build_array(%s::text)
                ORDER BY source.source_id, source.version, chunk.ordinal LIMIT %s''',
            (language, hazard, SCAN_LIMIT + 1),
        ).fetchall()
    if len(rows) > SCAN_LIMIT:
        raise CatalogError('Chat evidence scan limit exceeded.')

    def match_score(row):
        return len(query_terms & evidence_terms(row['passage']))

    candidates = heapq.nlargest(limit, (row for row in rows if match_score(row) >= 2), key=match_score)
    citations = []
    for row in candidates:
        manifest = Manifest.model_validate(row['metadata'])
        citations.append({'kind': 'indexed_document', **manifest.model_dump(include={
            'source_id', 'version', 'title', 'publisher', 'url', 'publication_date',
        }), 'section': row['section'], 'passage': row['passage']})
    return citations
