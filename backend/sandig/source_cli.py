import argparse
import json
import os
import re
from pathlib import Path

import httpx
import psycopg
from dotenv import load_dotenv
from pydantic import ValidationError

from sandig.catalog import SCHEMA, SOURCE_ID, CatalogError, Manifest, connect
from sandig.rag import OllamaEmbedder, cleanup, deactivate, extract, passages, publish, read_regular, retrieve, stage, version


ROOT = Path(__file__).resolve().parents[2]


def open_collection(connection, embedding_key: str):
    try:
        import chromadb
        from chromadb.config import Settings
    except ImportError:
        raise CatalogError('Install backend/requirements-rag.txt for vector operations.') from None
    identity = connection.execute('SELECT catalog_id FROM sandig_catalog_identity').fetchone()['catalog_id']
    path = Path(os.getenv('SANDIG_CHROMA_DIR') or '.sandig-index')
    if not path.is_absolute():
        path = ROOT / path
    try:
        client = chromadb.PersistentClient(path=str(path), settings=Settings(anonymized_telemetry=False))
        return client.get_or_create_collection(f'sandig-{identity.hex}-{embedding_key}',
                                               embedding_function=None, configuration={'hnsw': {'space': 'cosine'}})
    except (OSError, RuntimeError, ValueError, chromadb.errors.ChromaError):
        raise CatalogError('Local vector store could not be opened.') from None


def slug(value: str) -> str:
    if re.fullmatch(SOURCE_ID, value) is None:
        raise argparse.ArgumentTypeError('Expected a lowercase slug.')
    return value


def bounded_question(value: str) -> str:
    if not value.strip() or len(value) > 1000:
        raise argparse.ArgumentTypeError('Enter a nonempty question of at most 1,000 characters.')
    return value.strip()


def parser() -> argparse.ArgumentParser:
    root = argparse.ArgumentParser(description='Local authenticated-by-OS SANDIG source operations; no public writes.')
    commands = root.add_subparsers(dest='command', required=True)
    commands.add_parser('init')
    intake = commands.add_parser('stage')
    intake.add_argument('manifest', type=Path)
    intake.add_argument('document', type=Path)
    for action in ('inspect', 'publish', 'reindex', 'deactivate'):
        command = commands.add_parser(action)
        command.add_argument('source_id', type=slug)
        command.add_argument('version')
        if action == 'publish':
            command.add_argument('--reviewer', required=True)
            command.add_argument('--approval-ref', required=True)
    collect = commands.add_parser('cleanup')
    collect.add_argument('--embedding-key', help='64-character key printed by inspect; optional with the current embedder.')
    query = commands.add_parser('retrieve')
    query.add_argument('question', type=bounded_question)
    query.add_argument('--jurisdiction', type=slug)
    query.add_argument('--hazard', type=slug)
    return root


def operate(connection, args):
    connection.execute('SET LOCAL statement_timeout = 5000')
    if not connection.execute('SELECT pg_try_advisory_xact_lock(7347242) AS acquired').fetchone()['acquired']:
        raise CatalogError('Another source command is running. Retry after it finishes.')
    if args.command == 'init':
        connection.execute(SCHEMA)
        return {'status': 'initialized'}
    if args.command == 'stage':
        manifest = Manifest.model_validate_json(read_regular(args.manifest, 16 * 1024))
        digest, chunks = extract(args.document)
        stage(connection, manifest, digest, chunks)
        return {'status': 'draft', 'chunks': len(chunks), 'sha256': digest}
    if args.command == 'inspect':
        return {'version': version(connection, args.source_id, args.version),
                'passages': passages(connection, args.source_id, args.version)}
    if args.command == 'deactivate':
        deactivate(connection, args.source_id, args.version)
        return {'status': 'inactive', 'vector_cleanup': 'run cleanup separately'}
    try:
        from chromadb.errors import ChromaError
    except ImportError:
        raise CatalogError('Install backend/requirements-rag.txt for vector operations.') from None
    try:
        return vector_operation(connection, args)
    except ChromaError:
        raise CatalogError('Local vector operation failed; no catalog change was committed.') from None


def vector_operation(connection, args):
    if args.command == 'cleanup' and args.embedding_key is not None:
        if re.fullmatch(r'[a-f0-9]{64}', args.embedding_key) is None:
            raise CatalogError('Invalid embedding key.')
        return {'vectors_deleted': cleanup(connection, open_collection(connection, args.embedding_key), args.embedding_key)}

    with httpx.Client(base_url='http://127.0.0.1:11434', timeout=httpx.Timeout(90, connect=3),
                      trust_env=False) as client:
        embedder = OllamaEmbedder(client, os.getenv('SANDIG_EMBEDDING_MODEL', 'qwen3-embedding:0.6b'),
                                  os.getenv('SANDIG_CPU_ONLY', 'false').lower() == 'true')
        collection = open_collection(connection, embedder.key)
        if args.command == 'retrieve':
            citations = retrieve(connection, collection, embedder, args.question, args.jurisdiction, args.hazard)
            return {'evidence_status': 'partially_supported' if citations else 'insufficient_evidence',
                    'answer': ('Candidate passages for operator review only.' if citations else
                               'No suitable approved passages were retrieved.'), 'citations': citations}
        if args.command == 'cleanup':
            return {'vectors_deleted': cleanup(connection, collection, embedder.key)}
        generation = publish(connection, collection, embedder, args.source_id, args.version,
                             getattr(args, 'reviewer', None), getattr(args, 'approval_ref', None))
        return {'status': 'active', 'generation': generation, 'embedding_key': embedder.key}


def main() -> int:
    args = parser().parse_args()
    load_dotenv(ROOT / '.env')
    dsn = os.getenv('SANDIG_DATABASE_URL', '')
    try:
        if not dsn:
            raise CatalogError('Set the dedicated SANDIG_DATABASE_URL first.')
        with connect(dsn) as connection:
            result = operate(connection, args)
    except CatalogError as error:
        print(f'Error: {error}')
        return 1
    except ValidationError as error:
        fields = ', '.join('.'.join(map(str, entry['loc'])) or 'manifest' for entry in error.errors(include_input=False))
        print(f'Error: Invalid manifest fields: {fields}.')
        return 1
    except psycopg.errors.UniqueViolation:
        print('Error: Source version exists or conflicts with an active version.')
        return 1
    except (psycopg.Error, httpx.HTTPError, OSError, ValueError, RuntimeError):
        print('Error: Source operation failed; check local database, vector storage, file and embedding service.')
        return 1
    print(json.dumps(result, default=str, indent=2))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
