import json

import httpx
import psycopg
import pytest

from sandig.smoke import SmokeFailure, ollama_smoke, postgres_smoke


def test_cpu_smoke_uses_fixed_inputs_and_unloads_models():
    requests = []

    def respond(request):
        payload = json.loads(request.content)
        requests.append((request.url.path, payload))
        if request.url.path == '/api/chat':
            return httpx.Response(200, json={'done': True, 'message': {'content': 'ready'}})
        return httpx.Response(200, json={'embeddings': [[0.1, 0.2]]})

    with httpx.Client(base_url='http://127.0.0.1:11434', transport=httpx.MockTransport(respond)) as client:
        assert ollama_smoke(client, 'chat-tag', 'embed-tag', True) == (5, 2)
    assert [path for path, _ in requests] == ['/api/chat', '/api/embed']
    assert [payload['model'] for _, payload in requests] == ['chat-tag', 'embed-tag']
    assert all(payload['options']['num_gpu'] == 0 and payload['keep_alive'] == 0 for _, payload in requests)


def test_ollama_timeout_is_sanitized():
    def respond(request):
        raise httpx.ReadTimeout('internal details', request=request)

    with httpx.Client(base_url='http://127.0.0.1:11434', transport=httpx.MockTransport(respond)) as client:
        with pytest.raises(SmokeFailure, match='^Ollama chat failed') as failure:
            ollama_smoke(client, 'chat-tag', 'embed-tag', False)
    assert 'internal details' not in str(failure.value)


def test_postgres_missing_configuration_fails():
    with pytest.raises(SmokeFailure, match='Set SANDIG_DATABASE_URL'):
        postgres_smoke('')


def test_postgres_connection_error_does_not_print_credentials(monkeypatch):
    def fail(_database_url, **_options):
        raise psycopg.OperationalError('private connection details')

    monkeypatch.setattr(psycopg, 'connect', fail)
    with pytest.raises(SmokeFailure, match='^PostgreSQL connection failed') as failure:
        postgres_smoke('private connection details')
    assert 'private connection details' not in str(failure.value)
