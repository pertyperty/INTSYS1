import json

import httpx
import pytest

from sandig.benchmark import CASES, run_case


@pytest.mark.parametrize('with_evidence', [False, True])
def test_benchmark_preserves_the_evidence_boundary(with_evidence):
    def handler(request):
        if request.url.path == '/api/ps':
            return httpx.Response(200, json={'models': [{'name': 'model:test', 'size': 100, 'size_vram': 0}]})
        body = json.loads(request.content)
        assert body['options']['num_gpu'] == 0
        assert ('orange binder' in body['messages'][1]['content']) is with_evidence
        return httpx.Response(200, json={
            'done': True, 'done_reason': 'stop', 'message': {'content': 'Answer [DRILL-1] [DRILL-99]'},
            'eval_count': 20, 'eval_duration': 2_000_000_000,
        })

    with httpx.Client(base_url='http://127.0.0.1:11434', transport=httpx.MockTransport(handler)) as client:
        result = run_case(client, 'model:test', CASES[0], with_evidence, {'num_gpu': 0})
    assert result['unknown_markers'] == (['DRILL-99'] if with_evidence else ['DRILL-1', 'DRILL-99'])
    assert result['tokens_per_second'] == 10
    assert result['ollama_vram_bytes'] == 0
