# 03 - Manage and compare local models

## Purpose and prerequisites

Use the local Ollama runtime for eligible model-backed chat, operator retrieval, and offline generation experiments. Start Ollama, finish [getting started](01-getting-started.md), and allow disk space for the models. When `SANDIG_ENABLE_RAG_CHAT` is enabled, the chat path defaults to `gemma3:4b`; an explicit `SANDIG_CHAT_MODEL` in the root `.env` takes precedence. Restart FastAPI after changing these settings. Set `SANDIG_ENABLE_RAG_CHAT=false` for excerpt-only chat. Do not run benchmarks concurrently with ingestion or another model workload.

## Install, switch and verify

From the repository root in PowerShell, use `ollama` on PATH or the default Windows executable:

```powershell
$ollama = Join-Path $env:LOCALAPPDATA 'Programs\Ollama\ollama.exe'
& $ollama --version
& $ollama list
& $ollama pull qwen3:4b
& $ollama pull gemma3:4b
& $ollama pull qwen3-embedding:0.6b
& $ollama show qwen3:4b
& $ollama show gemma3:4b
$env:PYTHONPATH = 'backend'
.\.venv\Scripts\python.exe -m sandig.smoke ollama
```

Verify exact tags, quantization and a successful smoke result. `SANDIG_CHAT_MODEL` selects the model for enabled browser generation and the smoke probe; the benchmark selects its tags independently. Changing `SANDIG_EMBEDDING_MODEL` changes the index namespace: follow the [reindexing guide](02-document-ingestion.md) before relying on the new index. Keep the benchmark at a 2,048-token context for comparison; bigger windows can change memory and latency. Do not install the optional Qwen 3.5 candidate without separately validating its exact tag and footprint.

## Benchmark or troubleshoot

Run the [two-model comparison](06-testing-and-evaluation.md) and inspect exact tag digests in the JSON. If VRAM is tight, close competing model workloads and repeat with `--cpu-only` and a new output path. Run `& $ollama ps`, `nvidia-smi`, and use Task Manager's Details and Performance tabs during the run; capture peak process working set and GPU usage separately. Reported Ollama loaded bytes are not process RSS or peak system RAM. A missing tag, 404, or timeout is not a model score; confirm the runtime is reachable on `127.0.0.1:11434`, check the exact tag, and rerun rather than mixing results from different settings.

## Completion checklist

To test the no-document fallback, set `SANDIG_ENABLE_RAG_CHAT=true` and `SANDIG_ENABLE_LLM_FALLBACK=true` in the root `.env`, then restart FastAPI. With both servers running, switch **Web search** off and ask `How should I prepare for a typhoon?` when there is no matching approved material. Expect general AI guidance labeled **Insufficient evidence** if Ollama returns a usable answer; otherwise expect the existing insufficient-evidence reply. Turn web search back on to test optional Brave links separately. Set `SANDIG_ENABLE_LLM_FALLBACK=false` and restart FastAPI to disable only the provisional path while keeping approved-document RAG.

- [ ] Exact tags and quantization verified locally; smoke completes.
- [ ] Comparison records identical options, digests and CPU/GPU mode.
- [ ] Enabled chat generation is verified against a vetted source, and embedding changes are reindexed and verified.

Official references: [Ollama CLI](https://docs.ollama.com/cli), [chat API](https://docs.ollama.com/api/chat), [running models API](https://docs.ollama.com/api/ps).
