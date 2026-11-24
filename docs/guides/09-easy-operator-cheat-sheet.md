# 09 - Easy Operator Cheat Sheet (No RAG Jargon)

Use this 1-page guide when you want to **add a document**, **change a setting**, or **edit a canned response** without memorizing CLI flags.

---

## 1. Open the Easy Operator Helper

From the repository root in PowerShell:

```powershell
$env:PYTHONPATH = 'backend'
.\.venv\Scripts\python.exe -m sandig.operator_wizard
```

You will see a simple numbered menu:

- **1) Add a document** — Guided Q&A. Drag and drop a `.txt` or `.md` file, answer plain questions (title, publisher, URL, hazards), preview the extracted text, and choose whether to publish immediately. No hand-written `manifest.json` needed.
- **2) View or change global settings (`.env`)** — Toggle live weather, USGS earthquake feed, Ollama models, or Brave Search key without editing `.env` by hand. Secrets are masked on screen.
- **3) List or deactivate documents** — See every staged/active document and turn off outdated ones by number.
- **4) Initialize database tables** — Safe one-time setup (can be re-run anytime).

Direct shortcuts:
- `.\.venv\Scripts\python.exe -m sandig.operator_wizard add`
- `.\.venv\Scripts\python.exe -m sandig.operator_wizard settings`
- `.\.venv\Scripts\python.exe -m sandig.operator_wizard list`

---

## 2. Global Settings Quick Reference (`.env`)

All backend settings live in the repo-root `.env` file (template: `.env.example`). Restart FastAPI after changing any setting.

| Setting | Default | What it does |
| --- | --- | --- |
| `SANDIG_ENABLE_RAG_CHAT` | `true` | Turn local Ollama + Chroma RAG answers on/off in chat (emergency safety rules always run first) |
| `SANDIG_ENABLE_LLM_FALLBACK` | `true` | Allow local `gemma3:4b` general preparedness guidance when no approved document matches yet (documents always take priority once published) |
| `SANDIG_ENABLE_WEATHER` | `true` | Turn live weather feed on/off |
| `SANDIG_ENABLE_USGS` | `false` | Turn supplementary USGS earthquake feed on/off |
| `SANDIG_CHAT_MODEL` | `gemma3:4b` | Local Ollama model used for grounded RAG chat answers |
| `SANDIG_EMBEDDING_MODEL` | `qwen3-embedding:0.6b` | Local Ollama model used when publishing documents |
| `SANDIG_CPU_ONLY` | `false` | Set `true` if running Ollama without GPU |
| `SANDIG_CHROMA_DIR` | `.sandig-index` | Folder where local document vectors are stored |
| `SANDIG_BRAVE_SEARCH_KEY` | *(blank)* | Optional API key for one-time external web links |

---

## 3. Where to Edit Canned Responses

If you want to change the wording of built-in answers (instead of uploading a document):

- **General preparedness answers (Go-bag & Landslide, English/Filipino):** `backend/sandig/curated_guidance.py` (`GUIDANCE` dictionary)
- **Emergency alerts, refusal messages, and safety rules:** `backend/sandig/safety.py`

After editing either file, restart FastAPI and run `.\.venv\Scripts\python.exe -m pytest` inside `backend/` to verify everything still passes.
