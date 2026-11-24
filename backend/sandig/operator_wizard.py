import argparse
import os
import re
import sys
from datetime import date, timedelta
from pathlib import Path
from typing import Callable

import httpx
import psycopg
from dotenv import load_dotenv
from pydantic import ValidationError

from sandig.catalog import SCHEMA, CatalogError, Manifest, connect
from sandig.rag import OllamaEmbedder, deactivate, extract, publish, stage
from sandig.source_cli import ROOT, open_collection


SETTINGS_SPEC = [
    ('SANDIG_ENABLE_RAG_CHAT', 'bool', 'true', 'Enable local Ollama + Chroma RAG answers in chat'),
    ('SANDIG_ENABLE_LLM_FALLBACK', 'bool', 'true', 'Allow local Ollama general preparedness fallback when no document matches yet'),
    ('SANDIG_ENABLE_WEATHER', 'bool', 'true', 'Enable live weather feed'),
    ('SANDIG_ENABLE_USGS', 'bool', 'false', 'Enable supplementary USGS earthquake feed'),
    ('SANDIG_CHAT_MODEL', 'str', 'llama3.1:8b', 'Local Ollama chat model'),
    ('SANDIG_EMBEDDING_MODEL', 'str', 'qwen3-embedding:0.6b', 'Local Ollama embedding model'),
    ('SANDIG_CPU_ONLY', 'bool', 'false', 'Force CPU-only mode for Ollama'),
    ('SANDIG_CHROMA_DIR', 'str', '.sandig-index', 'Local vector index folder'),
    ('SANDIG_BRAVE_SEARCH_KEY', 'secret', '', 'Optional Brave Search API key (leave blank to disable)'),
]


def slugify(text: str) -> str:
    cleaned = re.sub(r'[^a-z0-9]+', '-', text.strip().lower()).strip('-')
    return cleaned[:64] if cleaned else 'source-document'


def parse_env_lines(text: str) -> list[tuple[str | None, str]]:
    parsed: list[tuple[str | None, str]] = []
    for raw_line in text.splitlines():
        stripped = raw_line.strip()
        if not stripped or stripped.startswith('#') or '=' not in raw_line:
            parsed.append((None, raw_line))
            continue
        key, value = raw_line.split('=', 1)
        parsed.append((key.strip(), value.strip()))
    return parsed


def load_env_map(env_path: Path, example_path: Path | None = None) -> dict[str, str]:
    values: dict[str, str] = {}
    if example_path and example_path.is_file():
        for key, value in parse_env_lines(example_path.read_text(encoding='utf-8')):
            if key:
                values[key] = value
    if env_path.is_file():
        for key, value in parse_env_lines(env_path.read_text(encoding='utf-8')):
            if key:
                values[key] = value
    return values


def save_env_key(env_path: Path, key: str, new_value: str, example_path: Path | None = None) -> None:
    if any(ch in new_value for ch in ('\r', '\n')):
        raise ValueError('Setting values cannot contain newlines.')
    if env_path.is_file():
        lines = env_path.read_text(encoding='utf-8').splitlines()
    elif example_path and example_path.is_file():
        lines = example_path.read_text(encoding='utf-8').splitlines()
    else:
        lines = []

    updated = False
    output: list[str] = []
    for raw_line in lines:
        stripped = raw_line.strip()
        if stripped and not stripped.startswith('#') and '=' in raw_line:
            existing_key = raw_line.split('=', 1)[0].strip()
            if existing_key == key:
                output.append(f'{key}={new_value}')
                updated = True
                continue
        output.append(raw_line)
    if not updated:
        output.append(f'{key}={new_value}')
    env_path.write_text('\n'.join(output) + '\n', encoding='utf-8')


def mask_secret(value: str) -> str:
    if not value:
        return '(not set)'
    if len(value) <= 6:
        return '***'
    return f'{value[:2]}***{value[-2:]} (set)'


def ask(
    prompt: str,
    default: str | None = None,
    *,
    input_fn: Callable[[str], str] = input,
) -> str:
    suffix = f' [{default}]' if default is not None else ''
    raw = input_fn(f'{prompt}{suffix}: ').strip()
    if raw == '' and default is not None:
        return default
    return raw


def prompt_manifest(
    input_fn: Callable[[str], str] = input,
    output_fn: Callable[[str], None] = print,
) -> tuple[Path, Manifest, str, list[tuple[str | None, str]]]:
    while True:
        raw_path = ask('Path to reviewed .txt or .md file (you can drag & drop it here)', input_fn=input_fn)
        doc_path = Path(raw_path.strip().strip('"').strip("'"))
        try:
            digest, chunks = extract(doc_path)
            break
        except (CatalogError, OSError) as error:
            output_fn(f'  Could not read that file: {error}')

    output_fn(f'  OK: Found {len(chunks)} passages (SHA-256: {digest[:16]}...).')

    today = date.today()
    default_review = (today + timedelta(days=365)).isoformat()

    while True:
        title = ask('Document title', input_fn=input_fn)
        suggested_id = slugify(title)
        source_id = ask('Short ID (lowercase letters, numbers, hyphens)', suggested_id, input_fn=input_fn)
        version = ask('Version label', '1.0', input_fn=input_fn)
        publisher = ask('Publisher / agency name (e.g. PHIVOLCS, PAGASA, NDRRMC)', input_fn=input_fn)
        url = ask('Official HTTPS URL (must start with https://)', input_fn=input_fn)
        jurisdiction = ask('Jurisdiction slug (e.g. philippines or baguio-city)', 'philippines', input_fn=input_fn)
        raw_hazards = ask('Hazards (comma-separated, e.g. landslide, earthquake)', 'preparedness', input_fn=input_fn)
        hazards = [slugify(item) for item in raw_hazards.split(',') if item.strip()]
        usage_restrictions = ask(
            'Public reuse / usage note',
            'Public preparedness reference; verify with original publisher.',
            input_fn=input_fn,
        )
        pub_raw = ask('Publication date YYYY-MM-DD (optional, press Enter to skip)', '', input_fn=input_fn)
        retrieval_raw = ask('Date retrieved (YYYY-MM-DD)', today.isoformat(), input_fn=input_fn)
        review_raw = ask('Review expiry date (YYYY-MM-DD)', default_review, input_fn=input_fn)
        chat_lang_raw = ask(
            'Allow short excerpts in public chat? (none / en / fil)',
            'none',
            input_fn=input_fn,
        ).lower()
        chat_language = chat_lang_raw if chat_lang_raw in ('en', 'fil') else None

        payload = {
            'source_id': source_id,
            'version': version,
            'title': title,
            'publisher': publisher,
            'url': url,
            'publication_date': pub_raw or None,
            'retrieval_date': retrieval_raw,
            'jurisdiction': slugify(jurisdiction),
            'hazards': hazards,
            'usage_restrictions': usage_restrictions,
            'review_until': review_raw,
            'chat_language': chat_language,
        }
        try:
            manifest = Manifest.model_validate(payload)
            return doc_path, manifest, digest, chunks
        except ValidationError as error:
            output_fn('  Some fields need fixing:')
            for entry in error.errors(include_input=False):
                loc = '.'.join(map(str, entry['loc'])) or 'manifest'
                output_fn(f'   - {loc}: {entry["msg"]}')
            output_fn('  Let us try those fields again.\n')


def run_add_wizard(
    dsn: str,
    *,
    input_fn: Callable[[str], str] = input,
    output_fn: Callable[[str], None] = print,
) -> int:
    output_fn('\n--- Add a Document (Guided Intake) ---')
    output_fn('Tip: Only reviewed UTF-8 .txt or .md files (up to 512 KiB) are supported.\n')
    try:
        _, manifest, digest, chunks = prompt_manifest(input_fn=input_fn, output_fn=output_fn)
    except (EOFError, KeyboardInterrupt):
        output_fn('\nCancelled.')
        return 0

    try:
        with connect(dsn) as connection:
            connection.execute('SET LOCAL statement_timeout = 5000')
            connection.execute(SCHEMA)
            stage(connection, manifest, digest, chunks)
    except psycopg.errors.UniqueViolation:
        output_fn('Error: That source_id and version already exists. Re-run with a new version label (e.g. 1.1).')
        return 1
    except (CatalogError, psycopg.Error) as error:
        output_fn(f'Error while staging document: {error}')
        return 1

    output_fn(f'\nStaged as DRAFT: {manifest.source_id} (version {manifest.version}), {len(chunks)} passages.')
    output_fn('Preview of first passages:')
    for idx, (section, passage) in enumerate(chunks[:3], start=1):
        snippet = passage.replace('\n', ' ')[:120]
        label = f' [{section}]' if section else ''
        output_fn(f'  {idx}.{label} {snippet}...')

    publish_now = ask('\nPublish this document now so it is active in SANDIG? (y/n)', 'n', input_fn=input_fn).lower()
    if publish_now not in ('y', 'yes'):
        output_fn(
            f'Saved as draft. To publish later, run:\n'
            f'  .\\.venv\\Scripts\\python.exe -m sandig.source_cli publish {manifest.source_id} {manifest.version} '
            f'--reviewer <your-id> --approval-ref <ref>'
        )
        return 0

    reviewer = ask('Reviewer name or ID', 'operator', input_fn=input_fn)
    approval_ref = ask('Approval reference', f'review-{date.today().isoformat()}', input_fn=input_fn)

    try:
        with connect(dsn) as connection:
            connection.execute('SET LOCAL statement_timeout = 5000')
            with httpx.Client(base_url='http://127.0.0.1:11434', timeout=httpx.Timeout(90, connect=3), trust_env=False) as client:
                embedder = OllamaEmbedder(
                    client,
                    os.getenv('SANDIG_EMBEDDING_MODEL', 'qwen3-embedding:0.6b'),
                    os.getenv('SANDIG_CPU_ONLY', 'false').lower() == 'true',
                )
                collection = open_collection(connection, embedder.key)
                generation = publish(connection, collection, embedder, manifest.source_id, manifest.version, reviewer, approval_ref)
    except (CatalogError, psycopg.Error, httpx.HTTPError, OSError, ValueError, RuntimeError) as error:
        output_fn(f'Could not publish right now (draft is still saved): {error}')
        output_fn('Make sure Ollama is running locally and requirements-rag.txt is installed, then try again.')
        return 1

    output_fn(f'\nPublished! {manifest.source_id} v{manifest.version} is now ACTIVE (generation {generation}).')
    return 0


def run_settings_helper(
    env_path: Path,
    example_path: Path | None = None,
    *,
    input_fn: Callable[[str], str] = input,
    output_fn: Callable[[str], None] = print,
) -> int:
    while True:
        values = load_env_map(env_path, example_path)
        output_fn(f'\n--- Global Settings ({env_path.name}) ---')
        for idx, (key, kind, default, description) in enumerate(SETTINGS_SPEC, start=1):
            current = values.get(key, default)
            shown = mask_secret(current) if kind == 'secret' else (current or '(empty)')
            output_fn(f'  {idx}) {key} = {shown}  -- {description}')
        output_fn('  0) Back')

        choice = ask('Pick a setting number to change (0 to go back)', '0', input_fn=input_fn)
        if choice in ('0', 'q', 'quit', 'exit', ''):
            return 0
        if not choice.isdigit() or not (1 <= int(choice) <= len(SETTINGS_SPEC)):
            output_fn('  Please enter a valid number.')
            continue

        key, kind, default, description = SETTINGS_SPEC[int(choice) - 1]
        current = values.get(key, default)
        if kind == 'bool':
            toggled = 'false' if current.lower() == 'true' else 'true'
            confirm = ask(f'Set {key} to "{toggled}"? (y/n)', 'y', input_fn=input_fn).lower()
            if confirm in ('y', 'yes'):
                save_env_key(env_path, key, toggled, example_path)
                output_fn(f'  Updated {key}={toggled}. Restart FastAPI for changes to take effect.')
        elif kind == 'secret':
            new_val = ask(f'New value for {key} (type CLEAR to remove, or Enter to cancel)', '', input_fn=input_fn)
            if new_val == '':
                output_fn('  No change made.')
            elif new_val.upper() == 'CLEAR':
                save_env_key(env_path, key, '', example_path)
                output_fn(f'  Cleared {key}. Restart FastAPI for changes to take effect.')
            else:
                save_env_key(env_path, key, new_val, example_path)
                output_fn(f'  Saved {key} ({mask_secret(new_val)}). Restart FastAPI for changes to take effect.')
        else:
            new_val = ask(f'New value for {key} ({description})', current, input_fn=input_fn)
            save_env_key(env_path, key, new_val, example_path)
            output_fn(f'  Updated {key}={new_val}. Restart FastAPI for changes to take effect.')


def run_list_and_deactivate(
    dsn: str,
    *,
    input_fn: Callable[[str], str] = input,
    output_fn: Callable[[str], None] = print,
) -> int:
    output_fn('\n--- Stored Documents ---')
    try:
        with connect(dsn) as connection:
            rows = connection.execute(
                '''
                SELECT source_id, version, state,
                       metadata->>'title' AS title,
                       metadata->>'review_until' AS review_until
                FROM sandig_source_versions
                ORDER BY source_id, version
                '''
            ).fetchall()
    except (CatalogError, psycopg.Error) as error:
        output_fn(f'Could not read document catalog: {error}')
        return 1

    if not rows:
        output_fn('  No documents have been staged or published yet.')
        return 0

    for idx, row in enumerate(rows, start=1):
        output_fn(
            f"  {idx}) [{row['state'].upper()}] {row['source_id']} v{row['version']} "
            f"- {row['title']} (review until {row['review_until']})"
        )

    choice = ask('Enter a document number to DEACTIVATE it (or 0 to go back)', '0', input_fn=input_fn)
    if choice in ('0', ''):
        return 0
    if not choice.isdigit() or not (1 <= int(choice) <= len(rows)):
        output_fn('  Invalid selection.')
        return 1

    selected = rows[int(choice) - 1]
    confirm = ask(
        f"Deactivate {selected['source_id']} v{selected['version']}? (y/n)",
        'n',
        input_fn=input_fn,
    ).lower()
    if confirm not in ('y', 'yes'):
        output_fn('  Cancelled.')
        return 0

    try:
        with connect(dsn) as connection:
            connection.execute('SET LOCAL statement_timeout = 5000')
            deactivate(connection, selected['source_id'], selected['version'])
    except (CatalogError, psycopg.Error) as error:
        output_fn(f'Error deactivating document: {error}')
        return 1

    output_fn(f"  Deactivated {selected['source_id']} v{selected['version']}.")
    return 0


def run_menu(
    env_path: Path,
    example_path: Path,
    *,
    input_fn: Callable[[str], str] = input,
    output_fn: Callable[[str], None] = print,
) -> int:
    while True:
        output_fn('\n=== SANDIG Easy Operator Helper ===')
        output_fn('  1) Add a document (guided intake & optional publish)')
        output_fn('  2) View or change global settings (.env)')
        output_fn('  3) List or deactivate documents')
        output_fn('  4) Initialize database tables')
        output_fn('  0) Exit')

        choice = ask('Choose an option', '0', input_fn=input_fn)
        if choice in ('0', 'q', 'quit', 'exit'):
            return 0
        if choice == '2':
            run_settings_helper(env_path, example_path, input_fn=input_fn, output_fn=output_fn)
            continue

        load_dotenv(env_path, override=True)
        dsn = os.getenv('SANDIG_DATABASE_URL', '')
        if not dsn:
            output_fn('Error: SANDIG_DATABASE_URL is not set in .env yet. Check docs/guides/01-getting-started.md.')
            continue

        if choice == '1':
            run_add_wizard(dsn, input_fn=input_fn, output_fn=output_fn)
        elif choice == '3':
            run_list_and_deactivate(dsn, input_fn=input_fn, output_fn=output_fn)
        elif choice == '4':
            try:
                with connect(dsn) as connection:
                    connection.execute(SCHEMA)
                output_fn('  Database tables initialized.')
            except (CatalogError, psycopg.Error) as error:
                output_fn(f'  Could not initialize database: {error}')
        else:
            output_fn('  Please enter 0, 1, 2, 3, or 4.')


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description='Interactive SANDIG operator helper for documents and global settings.')
    ap.add_argument('action', nargs='?', choices=('menu', 'add', 'settings', 'list'), default='menu')
    args = ap.parse_args(argv)

    env_path = ROOT / '.env'
    example_path = ROOT / '.env.example'
    load_dotenv(env_path)

    if args.action == 'settings':
        return run_settings_helper(env_path, example_path)
    if args.action == 'menu':
        return run_menu(env_path, example_path)

    dsn = os.getenv('SANDIG_DATABASE_URL', '')
    if not dsn:
        print('Error: Set SANDIG_DATABASE_URL in .env first.')
        return 1
    if args.action == 'add':
        return run_add_wizard(dsn)
    return run_list_and_deactivate(dsn)


if __name__ == '__main__':
    raise SystemExit(main(sys.argv[1:]))
