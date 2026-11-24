# Architecture and implementation sequence

Phase 1 implements the loopback chat bridge and separate PostgreSQL roles. Phase 2 implements operator semantic retrieval and a public read-only source catalog. Phase 3 adds deterministic emergency and scope routing and a separate, bounded public evidence lookup; generative answers and browser administration are not enabled. The Laravel app stays in `main/`; `frontend/` denotes the frontend responsibility, not a second app.

## Evidence providers

Chat classifies intent before consulting evidence. Emergency templates bypass retrieval. For eligible preparedness questions, Chroma proposes semantic matches and PostgreSQL supplies the canonical passages; lexical retrieval remains the fallback. Both paths require approved, active, unexpired Philippine sources with the matching hazard and explicit browser display permission for the requested language. With model-backed chat on, Ollama receives these passages as untrusted evidence, not as instructions. Failed generation falls back to an excerpt. The API labels these answers `partially_supported`; valid citation indices do not establish factual accuracy.

For future GIS, add a separate structured geographic evidence adapter behind the intent/evidence boundary, not a raster-to-text ingestion path. The policy should validate provenance, approval, dataset version/date, CRS, classification scheme, spatial method, coverage, and location uncertainty before projecting a result for the UI. Keep raw geometry, analytical values, and precise coordinates out of document passages and model prompts. Emergency routing stays independent of all evidence adapters.

Phase 5 added a separately enabled supplementary USGS snapshot and explicit link-only search. The chat overhaul adds automatic link-only fallback when indexed evidence is missing. Neither route supplies local hazard evidence or feeds a model. Search uses a preset topic and approved domain, not the prompt, place, or transcript. A limited, source-reviewed offline layer answers common go-bag and landslide preparation prompts; it does not establish local safety. Official Philippine agency links are static handoffs, not automated verification. See the [external integration guide](guides/05-external-api-integrations.md).

Compatibility: the browser no longer uses a manual dropdown. The legacy PSA name/code catalog remains available to authorized API clients and helps flag place mentions in the current prompt. The roster is not proof of where someone is or what conditions apply; the Leaflet reference extent is not a jurisdiction polygon. A future spatial adapter must version boundaries separately, reconcile codes and names, and handle splits, mergers and unmatched features. Agree on an additive typed geographic evidence field or a new contract version before emitting spatial results. Do not overload `passage`, `jurisdiction`, or `hazards` with spatial data.

Laravel owns bounded, session-scoped transcripts and serializes requests per session. FastAPI receives the current message only; prior turns are not used for inference. A restored database backup may contain earlier session snapshots. New deployments need a lock-capable cache and server-side sessions; formal retention and deletion across backups are public-release requirements.

| Owner | Scope |
| --- | --- |
| Thread A | `backend/`: FastAPI, Pydantic, Ollama adapter, Python-owned migrations, Chroma, ingestion, providers, GIS, safety and evaluation. |
| Thread B | `main/`: Laravel, Blade, Tailwind, sessions, auth, admin workflows, Leaflet and frontend tests. Use Livewire where state complexity warrants it. |
| Thread A after review by B | Root configuration and `docs/`; propose changes to the shared OpenAPI contract before editing either implementation. |

Laravel owns users and sessions; Python owns source metadata, versions, passages and vector IDs. Laravel reads the catalog through FastAPI and never queries the Python tables. FastAPI can query embedded Chroma for chat; the CLI serializes source writes with a PostgreSQL advisory lock. For local operation, avoid running vector maintenance concurrently with chat. PostgreSQL is authoritative for approval, review expiry, and active generation. Reindex writes a separate generation, switches the catalog pointer after successful indexing, and leaves old vectors for retryable cleanup. The operator keeps immutable original bytes outside the repo; the catalog records the staged input hash and normalized passages.

Decision: use Ollama locally behind separate generation and embedding interfaces. LM Studio can be added as an adapter; AnythingLLM is comparison-only. No hosted LLM, fine-tuning, Redis, or distributed queue for the first slice. Start with one inference at a time, a 2K context budget, one loaded model, and measure GPU offload against CPU-only operation before raising limits. Run evaluation on held-out English, Filipino, and mixed-language cases; keep settings, digests, raw outputs and human scoring, including a no-RAG baseline.

| Phase | Dependency and exit criterion |
| --- | --- |
| 0: Audit | Baseline Laravel, inspect workstation, document gaps, propose shared API. |
| 1: Stabilize | Agree on contract; A implements loopback health, protected routes, Ollama proof and isolated PostgreSQL roles; B implements server-to-server client and tested states using fixtures. Prove a browser-to-backend request, safe Ollama failure, real model smoke, and database authentication. No unsupported answers shown as facts. |
| 2: RAG | Establish source approval and versioning, safe extraction, embeddings, Chroma, citation resolution, reindex/deactivate. Start with approved national/general material, not unapproved local documents. |
| 3: Safety | Gate grounding, live claims and mixed-scope prompts. Validate intent and emergency behavior with bilingual tests and approved deterministic emergency responses. Never use a model confidence score as evidence. |
| 4: Location | Add consent, local-only coordinates and optional Leaflet; display validated catalog/boundaries only. Lack of layers must be explicit. |
| 5: External | Verify provider terms; implement tested fixed-host official/live providers separately from explicit general search. Add guarded fetching, quotas, freshness checks and deterministic outage behavior. |
| 6: CDRRMO | Depends on authorized documents, metadata and official review, not on external search. Validate CRS/legends before publishing layers. |
| 7: Evaluation | Evaluate the prototype without waiting for GIS. Local fixture model comparisons and synthetic recovery can run independently; end-to-end grounded quality, local hazard validation, accessibility review and full recovery remain release gates. Public release is a separate approval gate. |

Write each of the eight required guides alongside the corresponding verified workflow, including commands, expected results, common errors and troubleshooting. No guide should present planned functionality as complete.
