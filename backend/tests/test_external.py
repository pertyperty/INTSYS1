import json
from datetime import UTC, datetime, timedelta

import httpx
import pytest
from fastapi.testclient import TestClient

from sandig.api import create_app
from sandig.external import ExternalInformation
from sandig.rag_chat import RagChatEngine


TOKEN = 'test-token'
AUTH = {'Authorization': f'Bearer {TOKEN}'}


def offline_engine():
    return RagChatEngine(chat_model='llama3.1:8b', transport=httpx.MockTransport(lambda _r: httpx.Response(503)))


def mock_ai_engine(reply_text='AI answer', *, capture: list | None = None):
    def handler(request: httpx.Request):
        if request.url.path == '/api/tags':
            return httpx.Response(200, json={'models': [
                {'name': 'llama3.1:8b', 'size': 4920753328},
                {'name': 'gemma3:4b', 'size': 3350272403},
            ]})
        if request.url.path == '/api/chat':
            body = json.loads(request.read().decode('utf-8'))
            if capture is not None:
                capture.append(body)
            if body.get('stream'):
                lines = [
                    json.dumps({'message': {'content': '<think>skip</think>Hello '}, 'done': False}),
                    json.dumps({'message': {'content': reply_text}, 'done': False}),
                    json.dumps({'message': {'content': ''}, 'done': True}),
                ]
                return httpx.Response(200, text='\n'.join(lines) + '\n')
            return httpx.Response(200, json={'done': True, 'message': {'content': reply_text}})
        return httpx.Response(404)

    return RagChatEngine(chat_model='llama3.1:8b', transport=httpx.MockTransport(handler))


def test_stream_endpoint_streams_tokens_forwards_memory_and_model_parameters():
    captured = []
    engine = mock_ai_engine('from Baguio!', capture=captured)
    ext = ExternalInformation(
        brave_key='test-key',
        transport=httpx.MockTransport(lambda _r: httpx.Response(200, json={'web': {'results': [
            {'url': 'https://baguio.gov.ph/preparedness', 'title': 'Baguio Guide', 'description': 'Baguio emergency tips.'},
        ]}})),
    )

    with TestClient(create_app(TOKEN, '', ext, rag_chat=engine)) as client:
        with client.stream('POST', '/api/v1/chat/stream', headers=AUTH, json={
            'message': 'What did I say earlier?',
            'language': 'auto',
            'allow_web_search': True,
            'history': [
                {'role': 'user', 'content': 'My name is Alex and I live near Session Road.'},
                {'role': 'assistant', 'content': 'Nice to meet you, Alex!'},
            ],
            'model': 'gemma3:4b',
            'temperature': 0.7,
            'top_p': 0.85,
            'max_tokens': 300,
            'num_ctx': 8192,
            'system_prompt': 'Be super concise.',
        }) as res:
            assert res.status_code == 200
            events = [json.loads(line) for line in res.iter_lines() if line.strip()]

    meta = next(e for e in events if e['type'] == 'meta')
    tokens = ''.join(e['text'] for e in events if e['type'] == 'token')
    done = next(e for e in events if e['type'] == 'done')

    assert meta['model'] == 'gemma3:4b'
    assert meta['web_search_used'] is True
    assert meta['citations'][0]['kind'] == 'web_search'
    assert tokens == 'Hello from Baguio!'
    assert '<think>' not in tokens
    assert done['answer'] == 'Hello from Baguio!'

    assert len(captured) == 1
    sent = captured[0]
    assert sent['model'] == 'gemma3:4b'
    assert sent['stream'] is True
    assert sent['options']['temperature'] == 0.7
    assert sent['options']['top_p'] == 0.85
    assert sent['options']['num_predict'] == 300
    assert sent['options']['num_ctx'] == 8192
    assert 'Be super concise.' in sent['messages'][0]['content']
    assert sent['messages'][1] == {'role': 'user', 'content': 'My name is Alex and I live near Session Road.'}
    assert sent['messages'][2] == {'role': 'assistant', 'content': 'Nice to meet you, Alex!'}
    assert sent['messages'][3] == {'role': 'user', 'content': 'What did I say earlier?'}


def test_wikipedia_free_web_search_fallback_works_without_brave_key():
    def fake_wiki(request: httpx.Request):
        assert request.url.host == 'en.wikipedia.org'
        return httpx.Response(200, json={'query': {'search': [
            {'title': 'Baguio', 'snippet': 'Baguio is a <span>mountain</span> city in Benguet.'},
        ]}})

    ext = ExternalInformation(brave_key='', transport=httpx.MockTransport(fake_wiki))
    engine = mock_ai_engine('Baguio is a mountain city.')
    with TestClient(create_app(TOKEN, '', ext, rag_chat=engine)) as client:
        res = client.post('/api/v1/chat', headers=AUTH, json={'message': 'Tell me about Baguio', 'allow_web_search': True})
    assert res.status_code == 200
    body = res.json()
    assert body['web_search_used'] is True
    assert body['citations'][0]['publisher'] == 'Wikipedia'
    assert body['citations'][0]['url'] == 'https://en.wikipedia.org/wiki/Baguio'


def test_browser_location_is_used_for_open_meteo_weather_and_model_context():
    weather_calls = []
    captured_llm = []
    ts = (datetime.now(UTC) - timedelta(minutes=3)).strftime('%Y-%m-%dT%H:%M')

    def fake_ext(request: httpx.Request):
        if request.url.host == 'api.open-meteo.com':
            weather_calls.append(request)
            return httpx.Response(200, json={
                'utc_offset_seconds': 0,
                'current': {'time': ts, 'temperature_2m': 21.5, 'precipitation': 0.5, 'wind_speed_10m': 12},
                'current_units': {'temperature_2m': '\u00b0C', 'precipitation': 'mm', 'wind_speed_10m': 'km/h'},
            })
        return httpx.Response(503)

    ext = ExternalInformation(weather_enabled=True, transport=httpx.MockTransport(fake_ext))
    engine = mock_ai_engine('It is 21.5 C at your location.', capture=captured_llm)

    with TestClient(create_app(TOKEN, '', ext, rag_chat=engine)) as client:
        res = client.post('/api/v1/chat', headers=AUTH, json={
            'message': 'What is the weather here near me?',
            'location': {'latitude': 16.4123, 'longitude': 120.5912, 'accuracy': 25},
        })
    assert res.status_code == 200
    body = res.json()
    assert len(weather_calls) == 1
    assert weather_calls[0].url.params['latitude'] == '16.4100'
    assert weather_calls[0].url.params['longitude'] == '120.5900'
    assert body['citations'][0]['kind'] == 'supplementary_live'
    assert '16.4123' in captured_llm[0]['messages'][0]['content']
    assert '21.5' in captured_llm[0]['messages'][0]['content']


@pytest.mark.parametrize('age,unit,status', [(5, 'km/h', 200), (90, 'km/h', 200), (5, 'mph', 200), (5, 'km/h', 503)])
def test_baguio_weather_bounds_and_offline_fallback(age, unit, status):
    timestamp = (datetime.now(UTC) - timedelta(minutes=age)).strftime('%Y-%m-%dT%H:%M')

    def reply(request):
        if request.url.host == 'api.open-meteo.com':
            return httpx.Response(status, json={
                'utc_offset_seconds': 0,
                'current': {'time': timestamp, 'temperature_2m': 17, 'precipitation': 0, 'wind_speed_10m': 8},
                'current_units': {'temperature_2m': '\u00b0C', 'precipitation': 'mm', 'wind_speed_10m': unit},
            })
        return httpx.Response(503)

    providers = ExternalInformation(weather_enabled=True, transport=httpx.MockTransport(reply))
    with TestClient(create_app(TOKEN, '', providers, rag_chat=offline_engine())) as client:
        result = client.post('/api/v1/chat', headers=AUTH, json={'message': 'Is there a storm happening here?'}).json()
    if age == 5 and unit == 'km/h' and status == 200:
        assert result['citations'][0]['kind'] == 'supplementary_live' and result['checked_at']
        assert 'PAGASA' in result['answer']
    else:
        assert result['evidence_status'] == 'insufficient_evidence' and not result['citations']


def test_web_search_disabled_skips_external_search_and_instructs_model_not_to_link():
    search_called = False
    captured_llm = []

    def fail_if_searched(_request):
        nonlocal search_called
        search_called = True
        return httpx.Response(200, json={'web': {'results': []}})

    ext = ExternalInformation(brave_key='test-key', transport=httpx.MockTransport(fail_if_searched))
    engine = mock_ai_engine('Local offline answer.', capture=captured_llm)

    with TestClient(create_app(TOKEN, '', ext, rag_chat=engine)) as client:
        res = client.post('/api/v1/chat', headers=AUTH, json={
            'message': 'Tell me about Baguio City history',
            'allow_web_search': False,
            'auto_web_search': False,
        })

    assert res.status_code == 200
    body = res.json()
    assert not search_called
    assert body['web_search_used'] is False
    assert body['citations'] == []
    assert 'External web search is OFF' in captured_llm[0]['messages'][0]['content']
