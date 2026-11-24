from pathlib import Path

from sandig.operator_wizard import (
    load_env_map,
    mask_secret,
    prompt_manifest,
    run_settings_helper,
    save_env_key,
    slugify,
)


def test_slugify_and_secret_masking():
    assert slugify('Baguio Landslide Guide (2026)!') == 'baguio-landslide-guide-2026'
    assert slugify('   ') == 'source-document'
    assert mask_secret('') == '(not set)'
    assert mask_secret('short') == '***'
    assert mask_secret('secret-api-key-1234') == 'se***34 (set)'


def test_settings_helper_toggles_and_preserves_comments(tmp_path: Path):
    env_file = tmp_path / '.env'
    example_file = tmp_path / '.env.example'
    example_file.write_text(
        '# SANDIG settings\nSANDIG_ENABLE_WEATHER=true\nSANDIG_ENABLE_USGS=false\nSANDIG_BRAVE_SEARCH_KEY=\n',
        encoding='utf-8',
    )

    answers = iter(['4', 'y', '9', 'super-secret-brave-key', '0'])
    outputs: list[str] = []

    rc = run_settings_helper(
        env_file,
        example_file,
        input_fn=lambda _: next(answers),
        output_fn=outputs.append,
    )

    assert rc == 0
    saved = load_env_map(env_file)
    assert saved['SANDIG_ENABLE_USGS'] == 'true'
    assert saved['SANDIG_ENABLE_WEATHER'] == 'true'
    assert saved['SANDIG_BRAVE_SEARCH_KEY'] == 'super-secret-brave-key'
    assert '# SANDIG settings' in env_file.read_text(encoding='utf-8')
    joined_output = '\n'.join(outputs)
    assert 'super-secret-brave-key' not in joined_output


def test_save_env_key_rejects_newlines(tmp_path: Path):
    env_file = tmp_path / '.env'
    try:
        save_env_key(env_file, 'SANDIG_CHAT_MODEL', 'bad\nINJECTED=1')
    except ValueError:
        pass
    else:
        raise AssertionError('Expected ValueError for newline in value')


def test_prompt_manifest_builds_valid_manifest_with_defaults(tmp_path: Path):
    doc = tmp_path / 'guide.md'
    doc.write_text('# Landslide Safety\n\nPrepare an emergency go-bag and monitor official advisories.\n', encoding='utf-8')

    answers = iter([
        f'"{doc}"',
        'Baguio Landslide Guide',
        '',  # accept auto-slug
        '',  # accept version 1.0
        'PHIVOLCS',
        'https://www.phivolcs.dost.gov.ph/landslide-guide',
        '',  # accept philippines
        'landslide, earthquake',
        '',  # accept default usage note
        '',  # skip publication date
        '',  # accept today retrieval date
        '',  # accept +1 year review date
        'en',
    ])
    outputs: list[str] = []

    doc_path, manifest, digest, chunks = prompt_manifest(
        input_fn=lambda _: next(answers),
        output_fn=outputs.append,
    )

    assert doc_path == doc
    assert manifest.source_id == 'baguio-landslide-guide'
    assert manifest.version == '1.0'
    assert manifest.publisher == 'PHIVOLCS'
    assert manifest.hazards == ['landslide', 'earthquake']
    assert manifest.chat_language == 'en'
    assert len(digest) == 64
    assert len(chunks) >= 1
