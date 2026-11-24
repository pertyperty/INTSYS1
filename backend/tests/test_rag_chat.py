from datetime import UTC, datetime, timedelta

import httpx
import pytest
from fastapi.testclient import TestClient

from sandig.api import create_app
from sandig.external import ExternalInformation
from sandig.rag_chat import RagChatEngine
from sandig.safety import classify


TOKEN = 'test-service-token'
AUTH = {'Authorization': f'Bearer {TOKEN}'}


@pytest.mark.parametrize('message', [
    'Can you look for weather updates',
    'weather updates please',
    'Check the weather today',
    'What is the weather forecast?',
    'How is the weather in Baguio?',
    'Lagay ng panahon ngayon',
    'Ano ang panahon sa Baguio?',
    'May bagyo po ba ngayon?',
])
def test_natural_weather_queries_route_to_weather_now(message):
    intent = classify(message)
    assert intent.route == 'weather_now'
    assert intent.hazard == 'typhoon'


def test_approved_documents_are_injected_as_primary_source_in_ai_chat(monkeypatch):
    captured = []

    def fake_ollama(request: httpx.Request):
        if request.url.path == '/api/tags':
            return httpx.Response(200, json={'models': [{'name': 'llama3.1:8b', 'size': 4920753328}]})
        if request.url.path == '/api/chat':
            captured.append(request.read().decode('utf-8'))
            return httpx.Response(200, json={
                'done': True, 'done_reason': 'stop',
                'message': {'content': 'Keep your whistle in the bright blue pouch [1].'},
            })
        return httpx.Response(404)

    doc_citations = [{
        'kind': 'indexed_document',
        'source_id': 'baguio-eq-guide',
        'version': '1.0',
        'title': 'Baguio Earthquake Guide',
        'publisher': 'PHIVOLCS',
        'url': 'https://www.phivolcs.dost.gov.ph/guide',
        'publication_date': None,
        'section': 'Go-Bag',
        'passage': 'Keep your whistle in the bright blue pouch.',
    }]

    engine = RagChatEngine(chat_model='llama3.1:8b', transport=httpx.MockTransport(fake_ollama))
    monkeypatch.setattr(engine, 'retrieve_semantic', lambda *_a, **_kw: doc_citations)
    ext = ExternalInformation(weather_enabled=False, transport=httpx.MockTransport(lambda _r: httpx.Response(503)))

    with TestClient(create_app(TOKEN, 'postgresql://fake/db', ext, rag_chat=engine)) as client:
        res = client.post('/api/v1/chat', headers=AUTH, json={'message': 'Where should I put my whistle?'})

    assert res.status_code == 200
    body = res.json()
    assert body['evidence_status'] == 'supported'
    assert body['model'] == 'llama3.1:8b'
    assert 'bright blue pouch [1]' in body['answer']
    assert body['citations'][0]['source_id'] == 'baguio-eq-guide'
    assert 'PRIMARY APPROVED REFERENCE DOCUMENTS' in captured[0]


def test_semantic_retrieval_strict_gate_excludes_ineligible_docs(monkeypatch):
    today = datetime.now(UTC).date()
    base_meta = {
        'version': '1.0',
        'title': 'Approved Guide',
        'publisher': 'PHIVOLCS',
        'url': 'https://www.phivolcs.dost.gov.ph/guide',
        'publication_date': None,
        'retrieval_date': today.isoformat(),
        'review_until': (today + timedelta(days=30)).isoformat(),
        'usage_restrictions': 'Public preparedness use',
    }
    rows = [
        {'generation': 'gen-no-lang', 'metadata': {**base_meta, 'source_id': 'no-lang', 'jurisdiction': 'philippines', 'hazards': ['earthquake'], 'chat_language': None}},
        {'generation': 'gen-wrong-lang', 'metadata': {**base_meta, 'source_id': 'wrong-lang', 'jurisdiction': 'philippines', 'hazards': ['earthquake'], 'chat_language': 'fil'}},
        {'generation': 'gen-wrong-jur', 'metadata': {**base_meta, 'source_id': 'wrong-jur', 'jurisdiction': 'baguio-city', 'hazards': ['earthquake'], 'chat_language': 'en'}},
        {'generation': 'gen-wrong-haz', 'metadata': {**base_meta, 'source_id': 'wrong-haz', 'jurisdiction': 'philippines', 'hazards': ['flood'], 'chat_language': 'en'}},
        {'generation': 'gen-ok', 'metadata': {**base_meta, 'source_id': 'eligible-eq', 'jurisdiction': 'philippines', 'hazards': ['earthquake'], 'chat_language': 'en'}},
    ]

    class FakeCursor:
        def __init__(self, result):
            self._result = result

        def fetchall(self):
            return self._result

        def fetchone(self):
            return self._result

    class FakeConn:
        def __enter__(self):
            return self

        def __exit__(self, *_args):
            return False

        def execute(self, sql, params=None):
            if 'FROM sandig_source_versions' in sql:
                return FakeCursor(rows)
            if 'FROM sandig_source_chunks' in sql:
                return FakeCursor({'section': 'Before Shaking', 'passage': 'Secure heavy furniture.'})
            return FakeCursor(None)

    class FakeCollection:
        def query(self, *, query_embeddings, n_results, where, include):
            allowed = set(where['generation']['$in'])
            ids = [f'{gen}:0' for gen in ('gen-no-lang', 'gen-wrong-lang', 'gen-wrong-jur', 'gen-wrong-haz', 'gen-ok') if gen in allowed]
            return {'ids': [ids], 'distances': [[0.1] * len(ids)]}

    digest = 'a' * 64

    def fake_ollama(request):
        if request.url.path == '/api/tags':
            return httpx.Response(200, json={'models': [{'name': 'qwen3-embedding:0.6b', 'digest': digest}]})
        if request.url.path == '/api/embed':
            return httpx.Response(200, json={'embeddings': [[0.1, 0.2, 0.3]]})
        raise AssertionError(f'Unexpected path {request.url.path}')

    monkeypatch.setattr('sandig.rag_chat.connect', lambda *_a, **_kw: FakeConn())
    monkeypatch.setattr('sandig.rag_chat.open_collection', lambda *_a, **_kw: FakeCollection())

    engine = RagChatEngine(transport=httpx.MockTransport(fake_ollama))
    matches = engine.retrieve_semantic('postgresql://fake/db', 'Earthquake safety tips', 'earthquake', 'en', 3, strict_gate=True)
    assert [m['source_id'] for m in matches] == ['eligible-eq']
