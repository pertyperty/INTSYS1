# 02 - Document ingestion and approval

## Purpose and prerequisites

Stage and review local documents, publish approved versions, verify retrieval and citations, and withdraw outdated material. These are workstation CLI operations, not authenticated browser uploads. The CLI trusts the Windows operator and dedicated database credentials; a reviewer field is a recorded human attestation, not a verified login or agency endorsement.

Set up [Windows services](01-getting-started.md) and start the dedicated PostgreSQL cluster and local Ollama. Keep input files outside Git in an access-controlled folder. The CLI accepts UTF-8 TXT and Markdown up to 512 KiB, with up to 512 extracted chunks. Scanned PDFs, direct PDF parsing, DOCX, HTML, and structured-source ingestion are not implemented. For a searchable PDF, use an approved offline conversion utility, review the resulting text against the original, and add Markdown section labels such as `## Original PDF page 7` after verifying them. These are section labels, not machine-read PDF page numbers. Do not index raw OCR output without human review.

## Initialize

From the repository root in PowerShell:

```powershell
.\.venv\Scripts\python.exe -m pip install -r backend/requirements-rag.txt
$env:PYTHONPATH = 'backend'
.\.venv\Scripts\python.exe -m sandig.source_cli init
```

Expect `{"status": "initialized"}` (formatted across lines). This is idempotent. `SANDIG_CHROMA_DIR` defaults to ignored `.sandig-index/`. Use a separate directory for testing and never share one embedded Chroma directory across independent deployments. Python web requests do not open the index. Use one CLI process at a time per catalog.

## Acquire, stage, and inspect

1. Obtain the official original and verify edition, publication date, jurisdiction, hazard, update schedule, and reuse permission. Confirm that the permission covers the eventual catalog and intended passage display. Preserve the original, the derived text if applicable, and the written approval outside the repo. Prefer verified national/general material until CDRRMO authorizes local materials.
2. In the secure intake folder, create `manifest.json` using the fields below. Replace every example value with verified metadata and a real review deadline before running any command. `usage_restrictions` is displayed in the read-only catalog; do not place private review notes there. `publication_date` can be `null` only if it is genuinely unknown and the reviewer accepts that limitation.

```json
{
  "source_id": "example-preparedness-guide",
  "version": "edition-1",
  "title": "REPLACE with verified title",
  "publisher": "REPLACE with verified publisher",
  "url": "https://example.org/replace-with-official-url",
  "publication_date": null,
  "retrieval_date": "YYYY-MM-DD",
  "review_until": "YYYY-MM-DD",
  "jurisdiction": "philippines",
  "hazards": ["landslide"],
  "usage_restrictions": "REPLACE with public reuse terms"
}
```

3. Point the commands at your actual local intake paths, and substitute the real source and version IDs throughout:

```powershell
$sourceId = 'example-preparedness-guide'
$version = 'edition-1'
.\.venv\Scripts\python.exe -m sandig.source_cli stage 'C:\approved-intake\manifest.json' 'C:\approved-intake\reviewed.md'
.\.venv\Scripts\python.exe -m sandig.source_cli inspect $sourceId $version
```

Expect a `draft` with a positive chunk count and SHA-256. Compare that digest with `Get-FileHash -Algorithm SHA256` on the staged document (hex case does not matter). Inspect headings and passages against the original: check reading order, context, units, warnings and exceptions, and agency attribution. Treat directives inside source text as data, not application instructions. Draft metadata and passages are not visible in the browser.

## Publish and verify

After recording the review decision, replace the reviewer and record references with real values. Use an internal record identifier, not a password or a private URL.

```powershell
.\.venv\Scripts\python.exe -m sandig.source_cli publish $sourceId $version --reviewer 'reviewer-id' --approval-ref 'approval-record-id'
.\.venv\Scripts\python.exe -m sandig.source_cli retrieve 'REPLACE with a known relevant non-sensitive question' --jurisdiction philippines --hazard landslide
```

Expect `active`, a generation ID, and an embedding key. Save the key if planning a model change. Retrieval uses the current Ollama embedding digest, Chroma cosine ranking, topic-term overlap, and eligible PostgreSQL source versions. Verify each result's title, publisher, link, version, section, and passage. The operator outcome is `partially_supported` for candidate passages, not an answer or proof of correctness. An unrelated or unsupported question should yield `insufficient_evidence`. Try paraphrases in English and Filipino; this initial overlap gate can miss relevant synonyms. Do not pass real users' questions or personal data as CLI arguments.

With Laravel and FastAPI running, select **Approved sources** from the chat page or open `/sources` on the Laravel website. Verify metadata appears only after publishing and the original link opens the approved edition. Review expiry automatically hides the version from the catalog and retrieval after the recorded date.

**Optional browser excerpts:** Leave `chat_language` absent unless the reviewer has authorized public display and checked every excerpt for safe standalone use, language accuracy, and permitted reuse. For a new version containing only general Philippine preparedness material, add `"chat_language": "en"` or `"chat_language": "fil"` to its manifest, then stage and publish normally. Do not opt in local assessments, time-sensitive warnings, personal data, or material with restrictions that disallow excerpts. Ask a general single-hazard question in the excerpt's language, then try a paraphrase. Expect **Partially supported** with the original source, version, and passage available in the citations. If it is missing, check active status, review expiry, `jurisdiction`, hazard, `chat_language`, and the embedding model used to index it. Language is inferred per message. Lexical fallback works best with at least two meaningful matching terms. Weak matches may show curated guidance or insufficient evidence. Verify that specific local hazard and current warning requests do not use this material as evidence and that deactivation removes it on a **new** request; old conversation entries are saved snapshots.

## Update, reindex, deactivate, or clean up

- **Update:** stage a new version with updated metadata and repeat review and publish. Existing versions are not overwritten; successful publication supersedes the previous active version. If a draft is wrong, do not publish it; register a corrected version ID. Draft retention and permanent purge are not yet implemented.
- **Reindex:** run `.\.venv\Scripts\python.exe -m sandig.source_cli reindex $sourceId $version` for an active, unexpired version. Run retrieval again. After changing embedding tags, reindex each active version; versions from the old embedding namespace are not mixed with the new one.
- **Withdraw:** run `.\.venv\Scripts\python.exe -m sandig.source_cli deactivate $sourceId $version`. Verify `inactive` in `inspect`, disappearance from `/sources`, and that retrieval does not return that version even before cleanup. This preserves the review record and passages, not a permanent erasure mechanism.
- **Clean up:** run `.\.venv\Scripts\python.exe -m sandig.source_cli cleanup`. For the old model or when Ollama is offline, use `cleanup --embedding-key '<saved-key>'`. Inspect `vectors_deleted`. Cleanup is safe to repeat and does not remove active generations. Deactivate expired versions explicitly when retiring them.

## Common errors and troubleshooting

- Invalid manifest or no passages: fix the reported fields, use UTF-8, check extracted text is not empty and retain only complete, reviewed excerpts. Split large documents along real section boundaries rather than cutting safety instructions mid-step.
- Duplicate version: inspect the existing record; use a new version ID for corrections. If indexing failed after writing some vectors, the previous active version stays visible and the new version remains a draft. Run `cleanup` to remove orphaned vectors, then retry the publish command.
- Embedding unavailable or changed: check `ollama list` and `python -m sandig.smoke ollama` in the project venv; keep the runtime loopback-only. Do not retag models during indexing. Retry only after verifying the configured tag and available RAM/VRAM.
- Catalog 503: run the [authenticated chat probe](01-getting-started.md#verify-the-browser-bridge) to check the shared token, then verify PostgreSQL, the dedicated URL, and the `init` step. The web catalog does not require Chroma or Ollama to be online. Never expose FastAPI or Ollama publicly to work around a local connectivity issue.

## Completion checklist

- [ ] Original, permission, review deadline, and edition verified.
- [ ] Draft digest, normalized passages, and section labels checked against the original.
- [ ] Reviewer decision recorded before publication.
- [ ] Known and unsupported queries checked; visible citations verified against the original.
- [ ] Replacement/deactivation and cleanup verified when retiring a source.

Official references: [Ollama embeddings](https://docs.ollama.com/api/embed), [Chroma collection queries](https://docs.trychroma.com/docs/querying-collections/query-and-get), and [PostgreSQL advisory locks](https://www.postgresql.org/docs/current/explicit-locking.html#ADVISORY-LOCKS).
