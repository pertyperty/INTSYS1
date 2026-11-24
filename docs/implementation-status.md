# Implementation status - November 24, 2026

## Model-backed chat integration

- Eligible preparedness requests can retrieve approved passages with Ollama embeddings and Chroma and generate a cited response locally with Ollama. Deterministic routing still handles emergencies and prevents generation for out-of-scope and unverified local or official-warning claims. If grounded generation fails validation or Ollama is unavailable, the app falls back to excerpts or other existing evidence handling.
- `SANDIG_ENABLE_RAG_CHAT` defaults to `true` in the backend. Semantic retrieval preserves the browser publication gate: an active, unexpired Philippine source must match the hazard and explicitly opt in to display in the requested language. Set the flag to `false` for excerpt-only chat. The model defaults to `gemma3:4b` when not explicitly configured. Restart FastAPI to load code or setting changes. A successful greeting checks connectivity and authentication, not RAG: test model-backed chat separately with a vetted source and verify each claim against the cited passage.
- Laravel now allows up to 75 seconds for the chat API and holds the submitting session lock for up to 90 seconds. A cold local model can be slower than an excerpt-only reply. These limits are not a performance benchmark or public-deployment approval.
- If no matching approved passage or bundled guidance is available, `SANDIG_ENABLE_LLM_FALLBACK=true` permits provisional general preparedness text. Both model flags must be enabled. The API defaults this fallback off if unset; the example environment opts in for local testing. The answer remains `insufficient_evidence`. Brave links, when available, are further reading: SANDIG does not fetch or verify their content or dates. This path is not a substitute for evaluating real safety answers before deployment.

## NEON review follow-up: pre-PR stabilization

- Expanded urgent English and Filipino routing for structure fires, active landslides, entrapment, and shaking. The classifier is still heuristic; these are regression checks, not independent safety validation. Added natural-order variants of comma-inverted barangay names without inferring a hazard or a GPS location.
- Approved kit excerpts now match common short go-bag questions before bundled guidance. Automatic web fallback skips curated guidance and catalog outages; an unconfigured provider no longer adds an automatic search failure notice. External links still do not establish evidence. Laravel rejects search results on routes that must not search.
- Normal Enter submission and explicit search have separate actions. Added mobile drawer focus containment and restored focus on close. Filipino answer text now carries `lang="fil"`; this is not a full translation of the interface. New Laravel setup defaults to encrypted sessions; existing ignored configuration and prior backups are not migrated automatically.

| Verification | Result |
| --- | --- |
| `python -m pytest backend/tests -q --tb=short` with both RAG flags; `python -m pip check` | 92 passed with isolated PostgreSQL/Chroma fixtures and local Ollama; no broken requirements. |
| `php artisan test --compact`; `vendor/bin/pint.bat --test`; `npm.cmd run build` | 65 tests and 512 assertions passed, formatting passed, and assets built. Optional font-fallback warning only. |
| Loopback browser with refreshed FastAPI and Laravel | At 1280 and mobile widths, verified English/Filipino guidance, source expansion, normal Enter, emergency routing, local abstention, scope redirect, guest-session isolation, chat switching/deletion and backend-outage retry without saving the failed turn. At 390 CSS pixels, the drawer contained focus and Escape restored it; at 320 CSS pixels, Send stayed in view without document overflow. Mocked geolocation denial and a successful fix/clear; no location fields were present in the form. |

**Still open:** Physical-device GPS permission testing, screen-reader and real 200% zoom acceptance, authenticated live Brave, independent bilingual safety validation, and official local guidance and data. Do not use the prototype for an official public pilot. Follow the [browser acceptance checklist](guides/06-testing-and-evaluation.md#browser-acceptance-checklist) for the remaining checks. No commit, PR, or deployment was started.

## Chat overhaul after Phase 7

Implemented a responsive chat workspace, separate recent-chat and settings controls, expandable sources and optional location tools. Laravel creates, switches, and deletes bounded session chats without forwarding prior turns or device coordinates. FastAPI supports automatic English/Filipino selection, recognizes known barangay names as local context, and adds source-reviewed general guidance for narrowly matched go-bag and landslide preparation questions. Approved indexed excerpts still take precedence. Automatic web fallback sends only preset queries, never runs for urgent/local/current/unrelated requests, and never promotes links to evidence. No GIS imports, generated public answers, public deployment, or PR.

**Files:** `backend/sandig/{api,safety,curated_guidance}.py`, `backend/tests/{test_api,test_external}.py`, the Laravel chat controller, session store, routes, UI assets and feature tests, the API contract/OpenAPI file, and the affected operating guides.

| Verification | Result |
| --- | --- |
| `python -m pytest backend/tests -q --tb=short` with both RAG flags; `python -m pip check` | 81 passed with dedicated PostgreSQL, Chroma and Ollama; no broken dependencies reported. |
| `php artisan test --compact`; `vendor/bin/pint.bat --test`; `npm.cmd run build` | 60 tests and 501 assertions passed, formatting passed, production assets built. Optional font-fallback warning only. |
| Fresh Uvicorn on an isolated loopback port with local PostgreSQL | Health/catalog passed, anonymous chat rejected, English and Filipino guidance returned, named local question abstained, emergency and out-of-scope routing worked. External providers disabled during this smoke. |

**Earlier limitation:** Port 8001 served the old contract during the first smoke check. It was restarted for the browser checks reported above. Automatic language and place detection are heuristic. GPS cannot assign a barangay without approved boundary data.

**Developer handoff:** Use the [Windows quickstart](guides/01-getting-started.md#verify-the-browser-bridge) for restarts. If both services are already running, open `http://127.0.0.1:8000` in a private window and run the remaining browser checks. CI troubleshooting starts only once a PR exists.

## Phase 7: local evaluation and deployment preparation

The developer authorized proceeding with evaluation while deferring Phase 6. Public chat still uses deterministic safety routing and reviewed excerpts; no generative answers, GIS evidence or deployment were enabled.

Added `backend/sandig/benchmark.py`, `backend/tests/test_benchmark.py`, a [raw benchmark report](evaluation-runs/phase-7-local.json), [evaluation findings](evaluation.md), and the guides for model management, testing, maintenance and operator workflows. All eight guides now exist; they distinguish supported workflows from planned ones.

| Command or check | Observed result |
| --- | --- |
| `python -m pytest backend/tests -q --tb=short` with both RAG flags; `python -m pip check` | 72 passed with dedicated PostgreSQL, Chroma and Ollama exercised. Dependency consistency check passed; it is not a Python vulnerability scan. |
| `npm.cmd run build`, `php artisan test`, `vendor/bin/pint.bat --test` | Build and formatting passed; 57 tests with 394 assertions. Optional font-fallback warning remains. |
| `npm.cmd audit --omit=dev`; `composer audit --locked --no-interaction` | No advisories reported in those scopes at run time. Not a security audit of the application or dev-only npm packages. |
| `python -m sandig.benchmark` for `qwen3:4b` and `gemma3:4b`; `python -m sandig.smoke ollama` with Gemma and CPU-only | 20 offline fixture outputs; Gemma CPU-only chat/embedding smoke returned a 5-character response and a 1,024-dimensional embedding. |
| `pg_dump -Fc` and `pg_restore --exit-on-error --single-transaction` | Restored a disposable synthetic PostgreSQL schema and verified the exact test row. The working catalog was not dumped or replaced; full PostgreSQL/Chroma recovery remains unverified. |

**Release blockers:** The small, preselected-context benchmark is not end-to-end RAG quality evaluation. Qwen exhausted its output budget and Gemma repeated an injected directive. No measured peak process RAM, reviewed official evaluation corpus, real-world emergency recall, manual accessibility sign-off, complete restore rehearsal, or public-infrastructure security review. Do not use these results to authorize public deployment.

**Developer handoff:** Use the [testing guide](guides/06-testing-and-evaluation.md) to reproduce the comparison, then follow the [local demonstration and recovery guide](guides/07-deployment-and-maintenance.md). Before a pilot, arrange independent bilingual safety and accessibility review, verify a combined recovery on a disposable instance, and resume Phase 6 when authorized local evidence is ready.

## Phase 6: intake checked; integration deferred

The UI refinement edits are present in the shared checkout. The UI thread stopped on a provider quota after saving the edits and running checks, but before sending its planned notifications. A fresh run from `main/` confirmed `php artisan test` (57 passed, 394 assertions), `vendor\bin\pint.bat --test` (passed), and `npm.cmd run build` (passed with a nonblocking optional font-fallback warning). These checks do not establish accessibility or public deployment readiness.

The repository check found no validated QGIS package or documented reuse permission for the five city map products. No maps were processed, no classifications were added, and no GIS endpoints or dependencies changed. Follow the [Phase 6 intake handoff](guides/04-gis-data-integration.md#phase-6-intake-handoff) when resuming. Validate one approved layer and its spatial results before proposing the typed evidence contract.

## Phase 5: external information and public interface

Implemented an optional USGS supplementary earthquake lookup, explicit per-request Brave Search for further reading, official agency portal links, and a redesigned mobile/desktop chat and source catalog. The app never upgrades evidence based on a search result, substitutes USGS for a Philippine bulletin, or calls an external service during emergency handling. The public interface clearly identifies itself as an academic prototype and keeps the local-only location reading and map opt-in. See the [real screenshot comparison](phase-5-ux-review.md).

Changed: `backend/sandig/{api,external,safety}.py`, `backend/tests/test_external.py`, `.env.example`, Laravel chat controller/view, source view, JavaScript and feature tests, README, OpenAPI, architecture, decisions, data sources, risks, and the [external integration guide](guides/05-external-api-integrations.md). No GIS dependencies or public deployment were added.

| Verification | Observed result |
| --- | --- |
| `SANDIG_RAG_INTEGRATION=1` and `SANDIG_RAG_OLLAMA=1` with `.venv/Scripts/python.exe -m pytest backend/tests -q --tb=short`, plus `pip check` | 70 passed using real PostgreSQL, Chroma and Ollama; clean dependency check. Initial run had three connection timeouts while the dedicated PostgreSQL cluster was stopped; after startup, all passed. |
| From `main/`: `npm run build`, `vendor/bin/pint --test`, `php artisan test` | Build and style checks passed; 55 tests, 382 assertions. |
| Loopback PHP and Uvicorn, CSRF-protected form requests | HTTP 200 for a real fresh USGS snapshot with no regional matches, Philippine bulletin abstention, explicit search with key absent, normal follow-up, and simulated emergency with opt-in. Source and barangay catalogs loaded. |
| Logged-out Chromium screenshot comparison | Compared real Duck.ai and Perplexity desktop/mobile captures with the redesigned SANDIG chat. Captured the redesigned source catalog separately. Not a formal accessibility or field test. |

Known gaps: no authorized machine-readable PAGASA warning or PHIVOLCS bulletin connector; authoritative pages were reachable but are not ingested. Optional Brave search is mock-tested, not authenticated against a paid/approved account. No shared multi-worker budget, formal accessibility audit, or public deployment readiness claim. The browser continues to use deterministic emergency text and curated excerpts, not generated answers.

Developer handoff: follow the [Phase 5 verification guide](guides/05-external-api-integrations.md), review the screenshot comparison, and leave Brave disabled until the account and terms are approved. Proceed to Phase 6 only with approval and validated QGIS outputs, permitted use and source metadata; do not infer classifications from the original JPGs.

## Phase 4 complete: optional location and reference map

The Laravel chat now offers manual selection from the 129-entry PSA city roster dated July 31, 2025, and a separately optional one-shot device reading. Only a manually selected catalog ID can enter the chat request; precise coordinates and accuracy stay in browser memory. Missing or unrecognized selections do not block chat. The Leaflet basemap is opt-in, attributed and constrained to a Baguio reference extent; it does not pan or zoom to the device position. Map tile failures are announced without disabling chat or the selector. The frontend thread compared real desktop/mobile screenshots with Duck.ai and Perplexity and recaptured SANDIG after simplifying the composer, moving Send above the mobile viewport fold, and adding bilingual starters. See the [screenshot index and comparison](phase-4-ux-review.md).

Changed files: `backend/sandig/{api,safety,locations}.py`, `backend/sandig/baguio_barangays.tsv`, `backend/tests/test_api.py`, `backend/tests/test_rag.py`, `main/app/Services/BarangayCatalog.php`, `main/app/Http/Controllers/ChatController.php`, `main/resources/js/app.js`, `main/resources/views/chat.blade.php`, the Laravel feature tests, and Leaflet package manifests. The README, OpenAPI contract, architecture, data sources, decisions, and GIS workflow guide were updated.

| Check | Observed result |
| --- | --- |
| Set `SANDIG_RAG_INTEGRATION=1` and `SANDIG_RAG_OLLAMA=1`; run `.\.venv\Scripts\python.exe -m pytest backend/tests -q --tb=short`; run `.\.venv\Scripts\python.exe -m pip check` | 61 tests passed including real PostgreSQL, Chroma and Ollama; no broken requirements. |
| From `main/`: `npm run build`, `.\vendor\bin\pint --test`, `php artisan test` | Vite and Pint passed, 52 tests passed with 352 assertions. |
| Loopback Uvicorn and PHP, incognito Chromium | Loaded 129 real roster entries and submitted a chat with Irisan selected. DevTools checks saw no geolocation or tile requests on startup. Deterministic device stubs covered successful, denied, low-accuracy and cleared readings; no coordinate form fields or browser storage writes. Simulated tile HTTP failures announced the error and left chat and selection usable. |
| Browser capture at 1280x800 and 390x844 | Eight comparison captures present at the expected dimensions; two separate desktop captures verify real backend integration. |

Known limits and risks: the roster is a dated lookup, not a boundary or hazard layer; name matching is not comprehensive. No validated hazard layers, area polygons, official live data, reverse geocoder, or parcel-level inference are included. OSM tiles are best-effort and reveal the client's IP address and requested map extent to the provider. The browser checks used simulated location/failure cases, not a field GPS trial or formal accessibility audit. A catalog timeout can still delay the rendered page because the form includes the roster. Do not deploy publicly on the basis of these checks.

Developer handoff: start both services using [getting started](guides/01-getting-started.md#verify-the-browser-bridge), then verify no-location chat, manual Irisan selection, the location prompt and map attribution with the [Phase 4 checklist](guides/04-gis-data-integration.md#completion-checklist). Continue preparing the validated QGIS materials independently. Recommended next phase, only after approval: Phase 5, verify official live-data options and implement explicit web-search opt-in separately from official data.

## Phase 3 complete: safety routing and evidence gates

FastAPI prioritizes rule-based English/Filipino emergency handling before scope and evidence checks. It uses curated general guidance and references from DILG, PHIVOLCS, FEMA, and the U.S. Fire Administration; it does not dispatch responders or provide official live alerts. Public preparedness excerpts require an active, unexpired, language-matched, explicitly opted-in Philippine source and a bounded lexical match. Laravel presents accessible emergency states and escaped citation details. No new packages, storage of questions, generative answers, or search calls were added. The main changes are in `backend/sandig/safety.py`, `backend/sandig/chat_evidence.py`, `backend/sandig/api.py`, `backend/sandig/catalog.py`, `backend/tests/`, `main/app/Http/Controllers/ChatController.php`, `main/resources/views/chat.blade.php`, and `main/tests/Feature/ChatTest.php`.

| Check | Observed result |
| --- | --- |
| `SANDIG_RAG_INTEGRATION=1` and `SANDIG_RAG_OLLAMA=1` with `python -m pytest backend/tests -q --tb=short`; `python -m pip check` | 57 passed, including real PostgreSQL, Chroma and Ollama integration. No broken requirements. |
| `php artisan test --compact`, `npm.cmd run build`, `vendor/bin/pint --test` in `main/` | 44 tests passed with 282 assertions, build and style checks passed; optional font fallback warning persists. |
| Loopback Laravel and FastAPI, HTTPX form requests with CSRF and session | HTML rendered the Filipino emergency alert and curated references, the out-of-scope redirect, current/local abstention, and the empty-source fallback. No emergency call was placed. |

**Known limits and security:** No official corpus is indexed; `supported` is reserved, and `partially_supported` means excerpts only. The classifier can miss or misclassify emergencies, especially negation, indirect language, and code-switching, and has no measured field recall. Do not deploy publicly or rely on the prototype for an emergency. An initial failed lifecycle assertion exposed the dedicated backend database credential in the test output. The fixture now redacts its connection string, and the local credential was rotated and verified; the separate Laravel role was untouched. No other action is needed for this workstation.

**Developer verification:** Follow the [browser smoke test](guides/01-getting-started.md#verify-the-browser-bridge). When approved materials are available, follow the [publish and verify guide](guides/02-document-ingestion.md#publish-and-verify) before opting them into public excerpts. Check emergency contact and instructions with local reviewers before a public pilot. **Recommended next phase:** optional location selection and Leaflet, with no hazard classifications until approved datasets are ready.

## Upcoming GIS intake (not started)

The developer received five Baguio City government JPG map products: **Forest Fire Hazard**, **Landslide Hazard**, **Physical Environmental Vulnerability**, **Seismic Hazard**, and **Sinkhole Hazard**. Their legends, grids and projection information are inputs to the developer's QGIS workflow, not textual barangay classifications. The handoff is **original JPG ? georeferenced GeoTIFF ? validated layer ? barangay spatial analysis ? SANDIG integration**. No maps have been ingested, interpreted, or classified by SANDIG. No PostGIS, raster dependencies, or GIS routes were introduced.

At the future GIS phase, provide validated GeoTIFFs, source and reuse metadata, legends/class dictionaries, documented CRS/control-point residuals, NoData convention, and any classified raster or versioned barangay boundaries and results. An RGB GeoTIFF preserves appearance, not necessarily class codes: anti-aliasing, grids/labels, resampling, and overlay tolerances can affect classifications. Confirm the published class method, alignment, boundary vintage, coverage, and handling for mixed or boundary cases. Keep hazard, vulnerability, and risk distinct; do not convert vulnerability into hazard or infer parcel-level safety. See [evidence architecture](architecture.md#evidence-providers) for contract compatibility and privacy constraints. Manual barangay selection must work without device geolocation.

## Phase 2 complete: operator retrieval and source catalog

The Python CLI (`backend/sandig/source_cli.py`) stages bounded TXT/Markdown, exposes extracted passages for review, records explicit approval, and publishes or reindexes a model-specific vector generation. It can withdraw a source immediately and remove orphaned or inactive vectors separately. PostgreSQL holds canonical source metadata and passages (`backend/sandig/catalog.py`); Ollama embeddings and Chroma cosine search propose passages (`backend/sandig/rag.py`). The bearer-protected FastAPI catalog and Laravel `/sources` only show approved, active, unexpired metadata. Laravel's catalog is implemented in `main/app/Http/Controllers/SourceCatalogController.php` and `main/resources/views/sources.blade.php`; public chat remains unchanged. Added dependency/environment settings, backend and frontend regression tests, and the document-ingestion guide.

| Command or check | Observed result |
| --- | --- |
| `pip install -r backend/requirements-rag.txt`; `python -m sandig.source_cli init`; `python -m pip check` | Chroma 1.5.9 installed; dedicated backend schema initialized; no broken requirements. |
| `SANDIG_RAG_INTEGRATION=1` and `SANDIG_RAG_OLLAMA=1` with `python -m pytest backend/tests -q` | 43 passed; real PostgreSQL, local Chroma, and the installed Ollama embedding model exercised in an isolated synthetic catalog. Covered approval, expiry, pagination tampering, interruption, supersession, reindex, unrelated queries and cleanup. |
| `php artisan test --compact`; `npm.cmd run build` in `main/` | 31 passed, 182 assertions; assets built. Optional font fallback warning remains nonblocking. |
| Live loopback HTTP and CLI smoke in a temporary test schema and vector directory | Separate CLI processes staged, inspected, published, retrieved an exact section citation, reindexed, deactivated and cleaned vectors. Real Laravel returned 200 with the test item, hid it immediately after deactivation, and returned 503 with FastAPI stopped. Test schema and directory were removed. |

**Known limits and security:** No approved official corpus was loaded. Retrieval is operator-only and returns candidate excerpts, not safety advice or verified answers. Lexical gating can miss paraphrases and is not calibrated for Baguio or Filipino. Direct PDF/OCR ingestion, citation rendering in public chat, browser administration, permanent purge, and generative RAG are not implemented. Keep operator access and original files restricted. Do not use this for real emergencies or public deployment.

**Developer action:** Once actual documents and reuse approvals are available, follow [the ingestion guide](guides/02-document-ingestion.md) to stage, inspect and publish them. Verify each citation against the original and confirm that unrelated prompts produce insufficient evidence. The local catalog remains empty pending that review. **Recommended next phase:** scope, bilingual emergency handling, and evidence-aware generation gates. Phase 3 has not started.

## Phase 1 complete: local stabilization verified

Added the FastAPI API and dependency probes under `backend/sandig/`, tests, root `.env.example`, and secret exclusions. The Laravel bridge changes `main/routes/web.php`, `main/config/services.php` and `main/.env.example`, and adds `main/app/Http/Controllers/ChatController.php`, `main/resources/views/chat.blade.php` and `main/tests/Feature/ChatTest.php`. The bridge uses Laravel request-forgery protection and throttling, a server-only bearer token, a fixed loopback endpoint, bounded timeouts, and generic failures. No question is written to the databases, no location is requested, and no provider is called. The contract, architecture, README and Windows quickstart record the current behavior.

| Command or check | Result |
| --- | --- |
| `python -m venv .venv`; `.venv/Scripts/python.exe -m pip install -r backend/requirements-dev.txt`; `pip check` inside the venv | Install succeeded; no broken requirements. |
| `.venv/Scripts/python.exe -m pytest backend/tests -q` | 19 passed; Starlette emits a nonblocking TestClient/httpx deprecation warning. |
| Uvicorn loopback HTTP smoke | Health 200, anonymous chat 401, authenticated chat 200 with insufficient evidence and no web search even when requested, sources 200 empty. Temporary process stopped. |
| `GET /api/version`; `GET /api/tags`; `python -m sandig.smoke ollama` with default offload and with `SANDIG_CPU_ONLY=true` | Ollama 0.40.17; both planned initial tags installed. Both modes returned a completed 64-character response and a 1024-dimensional embedding. The probe does not judge the response or measure hardware residency. |
| `initdb`, `pg_ctl`, `psql`, `python -m sandig.smoke postgres` | Provisioned a dedicated loopback SCRAM cluster on 55432 without changing the existing service on 5432. Backend authenticated and ran `SELECT 1`; `has_database_privilege` returned false for both cross-database `CONNECT` checks. |
| `php artisan migrate --no-interaction`; `php artisan migrate:status --no-interaction` | All three starter migrations ran in the dedicated Laravel PostgreSQL database. |
| `vendor/bin/pint --dirty --format agent`; `php artisan test --compact`; `npm run build` | Formatting applied; 11 Pest tests / 44 assertions passed. Vite built with a nonblocking optional font fallback warning. |
| Loopback browser check at 390 CSS pixels; `Invoke-WebRequest` without token or browser-origin evidence | Browser POST returned 200 with insufficient evidence. With Uvicorn stopped, native browser form submission returned 503 and a generic accessible alert; preview click automation timed out before the native submission. A request without a token or browser-origin evidence returned 419. |

**Limits and security:** Ollama and PostgreSQL were probed separately from chat. No RAG, citation rendering, emergency triage, approved corpus, model benchmark, or public deployment exists. Keep debug and Boost browser logging disabled in the local application, keep the credentials in ignored files, and keep both listeners on loopback. The preview is not an accessibility or public security audit.

**Developer verification:** No new credentials or installs are required on this laptop. Follow [the quickstart](guides/01-getting-started.md) to restart PostgreSQL, Uvicorn and Laravel, submit a question, and verify the 200 fallback and 503 outage states. Stop services when finished. **Recommended next phase:** approved-source intake and retrieval, with ingestion failures, citations and source deactivation tested before adding generated answers. Phase 2 has not started.

## Phase 0 report (historical)

At the end of Phase 0, the shared contract was validated as OpenAPI and pending review from the frontend owner. T3 showed only the main development thread in this project; no parallel work or contract approval was assumed.

## What exists and what does not

The baseline (`2f8113e`) is the Laravel starter under `main/`. The pre-existing Markdown files (`main/README.md`, `main/AGENTS.md`, `main/CLAUDE.md`) describe Laravel setup, not SANDIG. The starter has Blade, Tailwind/Vite, framework migrations, and two Pest checks. There is no SANDIG chat or backend, service auth bridge, admin UI, RAG, corpus, GIS, provider wiring, or evaluation harness. The eight workflow guides remain deliverables in the phases that implement them. The only versioned changes in this phase are `README.md` and the contract, source review, architecture and this report in `docs/`.

## Verification performed

| Check | Observed result |
| --- | --- |
| `composer validate`; `composer check-platform-reqs --lock`; `composer install --no-interaction --prefer-dist --no-progress` | Valid manifest, locked platform checks passed, and dependencies installed without a lockfile update. PHP 8.4.27, Composer 2.11.2; PostgreSQL and SQLite PHP drivers present. |
| `php artisan test` | Initially blocked by missing Composer dependencies, then failed 1/2 without `APP_KEY`. After creating the ignored local `.env` and running `php artisan key:generate`: 2/2 passed (2 assertions); starter coverage only. |
| `npm.cmd ci --ignore-scripts`; `npm.cmd run build` | Passed on Node 22.20.0 / npm 11.15.0; npm reported zero known vulnerabilities. Nonblocking Vite warning about optional `fontaine` fallback optimization. |
| `php artisan migrate --no-interaction`; `php artisan serve --host=127.0.0.1 --port=18080`; HTTP GET `/` | Three starter migrations applied to ignored local SQLite file. HTTP 200 on loopback; temporary server stopped. |
| `composer audit --locked` | No advisories reported; not a security certification. |
| `pg_isready -h 127.0.0.1 -p 5432`; `docker info --format '{{.ServerVersion}}'` | PostgreSQL 18.6 accepts connections; credentials and migrations on PostgreSQL are untested. Docker daemon responds; no deployment built. |
| `python --version`; `python -m openapi_spec_validator docs/openapi.v1.yaml` | Python 3.12.10 present; OpenAPI validation returned `OK` using a temporary tool install. No Python app or tests yet. |
| `Get-Command ollama`; loopback probe on 11434; Windows installed-app inspection | Ollama command not detected on PATH or at checked default paths; port closed. LM Studio and AnythingLLM appeared in Start Apps; not exercised. |

## Laptop constraints and model gate

Windows reports Ryzen 5 **5600H** (the brief says 5600U), 31.34 GiB visible RAM, and RTX 3050 Ti Laptop GPU with 4096 MiB VRAM (1376 MiB in use at one idle snapshot). No measured inference result yet.

Ollama catalog artifacts: [qwen3:4b](https://ollama.com/library/qwen3:4b) 2.5 GB, [gemma3:4b](https://ollama.com/library/gemma3:4b) 3.4 GB, [qwen3.5:4b](https://ollama.com/library/qwen3.5:4b) 3.3 GB for the listed llama.cpp variant, [qwen3-embedding:0.6b](https://ollama.com/library/qwen3-embedding:0.6b) 639 MB, and [bge-m3](https://ollama.com/library/bge-m3) 1.2 GB. These are download sizes, not VRAM budgets. Local compatibility is not verified. Start with Qwen3 and its embedder, one resident model at a time; defer Gemma and Qwen3.5 pulls until the first model works. Match runtime settings and record model digests when benchmarking. Test CPU fallback rather than assuming full GPU residency.

## Risks and limitations

- Do not expose the Laravel starter outside loopback with debug enabled. Laravel may contain environment details in debug responses. No user-facing authentication, retention policy, or boundary testing exists yet.
- Source licensing and emergency directory verification are unresolved. Do not ship local safety claims, maps, contact numbers, or routing until the relevant authority and any reuse conditions are verified. A public source page does not establish an integrated, permitted feed.
- A missing model or stale external information must never trigger silent general web search or invented advice. Add explicit failure behavior and regression tests before real users see generated responses.

## Human handoff and next phase

1. In PowerShell run `Get-Command ollama`. If absent, check the Start menu for Ollama; open it if installed, or install using [Ollama for Windows](https://docs.ollama.com/windows), then reopen PowerShell. Run `ollama --version` and `Invoke-RestMethod http://127.0.0.1:11434/api/version`; expect a CLI version and a JSON server version, respectively. Do not open firewall access for Ollama.
2. After verifying the runtime, run `ollama pull qwen3:4b` and `ollama pull qwen3-embedding:0.6b`, then `ollama list`; expect both tags. Run `ollama run qwen3:4b 'Reply with the single word ready'` as a local inference smoke test, not as an accuracy test. If it fails, collect the Ollama version and error before pulling comparison models. Use `ollama ps` and `nvidia-smi` during inference to inspect residency.
3. Have the PostgreSQL owner verify local admin access with `psql -h 127.0.0.1 -U postgres -d postgres -W -c 'SELECT current_user;'` (substitute the local admin role if different). Expect the admin role printed. Approve two dedicated app databases/roles during Phase 1; do not use the admin login in app configuration or share credentials in chat.
4. Give Thread B `docs/api-contract.md`, `docs/openapi.v1.yaml`, and its `main/` ownership from `docs/architecture.md`. Ask it to confirm the fields and failure states before both threads implement against the contract. If it already exists in another project, send the thread link for coordination.
5. Start Phase 1 with the smallest verified service path: health, authenticated chat boundary, database connection, local Ollama smoke, and a Laravel smoke request. Run with no official corpus and return `insufficient_evidence` rather than portraying a model-only answer as verified. In parallel, request approved CDRRMO materials and reuse terms; don't wait for them to start Phase 1.
