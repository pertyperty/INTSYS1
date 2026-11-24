# Evaluation evidence

## Phase 7: local offline comparison

The [November 24, 2026 Manila-time run](evaluation-runs/phase-7-local.json) contains five synthetic questions, expectations, exact model digests, settings and 20 responses. Both local tags report Q4_K_M in `ollama show`. Each model ran on the same fixtures and options (2,048 context, 160 output tokens, temperature 0, seed 42, `think=false`). Models ran sequentially with default GPU offload and were unloaded between models. The first request of each model is reported separately; this was one development-laptop run, not statistical or workload-isolated performance testing.

| Model | First request | Median later request | Decode range | Ollama loaded / VRAM |
| --- | ---: | ---: | ---: | ---: |
| `qwen3:4b` | 9.87 s | 5.64 s | 14.99-34.71 tok/s | 3.23 / 2.34 GB |
| `gemma3:4b` | 41.29 s | 2.20 s | 19.34-22.21 tok/s | 3.49 / 1.61 GB |

These are Ollama-reported allocation snapshots, not independently measured RAM or VRAM peaks. Gemma also completed a separate CPU-only smoke check; its latency is not in the table.

- **Qwen:** all ten outputs hit the length cap and surfaced deliberative text rather than a reliable final answer. Markers in those outputs are not valid answer citations. The behavior was observed even though the request sent `think=false`; the cause was not isolated.
- **Gemma:** three straightforward grounded cases gave the expected answer and exact marker, including Filipino and mixed-language prompts. It also abstained on the house-specific claim. It identified the checklist label but repeated the injected sentinel, failing the injection criterion. All five no-evidence answers abstained, but also produced `[ID]` or `[ID: None]` placeholders; the exact-marker detector catches `[ID]` only.
- **Decision:** prefer Gemma for further offline experiments with these settings; neither model is approved for public generation. Compare end-to-end retrieval plus generation against a no-RAG baseline on held-out and approved material before changing that decision. The preselected-context run measures generation behavior, not retrieval quality or production safety. The integration suite separately checks semantic retrieval and citation resolution on synthetic data.

Reproduce with the [testing guide](guides/06-testing-and-evaluation.md). Keep the raw synthetic output private if adapting this benchmark to any nonpublic material.

## Regression coverage

Phase 3 tests emergency priority and simulation phrasing in English/Filipino, explicit-search input without network lookup, scope and injection redirection, local/current abstention, generic database failure, public excerpt eligibility, exact citation text, deactivation, and Laravel's escaped and accessible rendering. The deterministic classifier has no measured real-world recall; synthetic tests do not establish deployment readiness. Add independently reviewed Filipino and code-switched emergency, negation, and ambiguous cases before any public pilot.

Phase 2 tests synthetic fixture relevance, exact source/version/section/passage resolution, approval expiry, invalid manifests, an interrupted vector write, an unrelated query, and removal before vector cleanup. The Ollama integration case uses an intentionally simple synthetic retrieval question; it validates plumbing, not disaster-answer quality, Filipino performance, or generative factuality.

From the repository root, with the initialized development PostgreSQL cluster running:

```powershell
.\.venv\Scripts\python.exe -m pytest backend/tests -q
$env:SANDIG_RAG_INTEGRATION = '1'
$env:SANDIG_RAG_OLLAMA = '1'
.\.venv\Scripts\python.exe -m pytest backend/tests -q
Remove-Item Env:SANDIG_RAG_INTEGRATION, Env:SANDIG_RAG_OLLAMA
```

The second run requires `backend/requirements-rag.txt`, a role that can create a temporary schema in the dedicated backend database, and the local `qwen3-embedding:0.6b` tag. It creates synthetic rows and a separate Chroma directory; it does not publish them to the application catalog. See the [phase report](implementation-status.md) for observed results.
