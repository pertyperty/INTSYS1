# 08 - Operator source administration

## Purpose and prerequisites

Manage sources through the local Python CLI. An authenticated Laravel admin dashboard, provider-management UI and browser uploads are not implemented. Use the authorized local operator account, the dedicated backend database, Ollama for vector operations, and documented reuse and approval records. The public Sources page is read-only.

## Inspect, approve, publish and retire

From the repository root in PowerShell:

```powershell
$env:PYTHONPATH = 'backend'
.\.venv\Scripts\python.exe -m sandig.source_cli --help
```

Expect the CLI subcommands. Follow the [ingestion guide](02-document-ingestion.md) for `stage`, `inspect`, `publish`, `retrieve`, `reindex`, `deactivate` and `cleanup`. Match extracted passages to the original and confirm permission, publisher, version and review deadline before publishing. Test sample questions with `retrieve`, then verify eligible metadata in Sources. When withdrawing, check that the source disappears from the public catalog and retrieval before vector cleanup. This is withdrawal, not permanent erasure.

## Providers and troubleshooting

Change provider settings only in the ignored root `.env` using the [external integration guide](05-external-api-integrations.md). Restart FastAPI, verify automatic fallback only after a missing indexed match, and confirm that **Web search** off and local/current questions never trigger general search. Leave unapproved providers disabled. If publishing fails, inspect the version and fix the reported cause; do not alter approval metadata to bypass validation. For catalog and model failures, run the smoke probes in [getting started](01-getting-started.md). Redact sensitive details before sharing logs.

## Completion checklist

- [ ] Verify provenance, permitted use and deadline before approval.
- [ ] Verify sample answers or excerpts against the original and check source visibility.
- [ ] Verify withdrawal separately from cleanup and retain the authorization record.

Official references: [PostgreSQL psql](https://www.postgresql.org/docs/current/app-psql.html), [Ollama embeddings](https://docs.ollama.com/api/embed).
