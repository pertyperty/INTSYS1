# 07 - Local demonstration and maintenance

## Purpose and prerequisites

Use the dedicated local PostgreSQL cluster, Ollama and [bootstrap steps](01-getting-started.md) for a local or trusted ZeroTier demonstration. Laravel and FastAPI bind to `0.0.0.0`; follow the [ZeroTier firewall guidance](01-getting-started.md#test-from-a-phone-over-zerotier) before starting them. Keep PostgreSQL and Ollama on loopback. Docker images and a Compose stack are not implemented or validated. Do not expose development services to the public internet.

## Demonstrate and verify

Follow [the browser bridge startup instructions](01-getting-started.md#verify-the-browser-bridge) in separate terminals. Build with `npm.cmd run build` from `main/`. Run both the health check and the authenticated chat probe in the setup guide; health alone will not catch a stale service token. Open `http://127.0.0.1:8000/` and run the [browser acceptance checklist](06-testing-and-evaluation.md#browser-acceptance-checklist). Leave diagnostics and debug output off for shared demos. Stop each test server with `Ctrl+C`. Chat history lives in Laravel sessions, which can be included in Laravel database backups; protect backup access and set a separate retention policy before any real-user rollout.

## Back up and verify

Stop ingestion and writers first. Use a protected backup folder and matching PostgreSQL client tools. Run both dumps with their own roles; enter secrets at the password prompts.

```powershell
$backup = Join-Path $env:LOCALAPPDATA ('SANDIG\backup-' + (Get-Date -Format yyyyMMdd-HHmmss))
New-Item -ItemType Directory $backup | Out-Null
pg_dump -h 127.0.0.1 -p 55432 -U sandig_backend -W -Fc -f "$backup\backend.dump" sandig_backend
pg_dump -h 127.0.0.1 -p 55432 -U sandig_laravel -W -Fc -f "$backup\laravel.dump" sandig_laravel
pg_restore --list "$backup\backend.dump"
pg_restore --list "$backup\laravel.dump"
Get-FileHash "$backup\backend.dump","$backup\laravel.dump"
```

Stop if either dump fails. Expect two nonempty archives with table entries. Copy the configured Chroma directory, originals and approval records separately while writes remain stopped. Secure environment settings separately. For each archive, provision a disposable database and use `pg_restore --exit-on-error --single-transaction --no-owner --no-privileges -h 127.0.0.1 -p <scratch-port> -U <scratch-role> -d <scratch-database> -W <archive-path>` after replacing placeholders. Never restore over the working database to test a backup. Point isolated test instances at the restored copies and verify migrations, source counts, approved retrieval and chat abstention.

## Updates, rollback and troubleshooting

- Record the exact code revision and uncommitted changes, model digests, and index version. Rehearse updates on copies using the [test guide](06-testing-and-evaluation.md).
- If an update fails, stop both apps and reopen the last verified code against compatible database and index copies. Do not roll back only code across incompatible data changes.
- For failed startup, check listener addresses, matching service tokens, `main/storage/logs/laravel.log` and the Uvicorn terminal. Redact credentials and sensitive data before sharing logs.
- Docker release work still needs manifests, pinned builds, private networking, persistent storage, secret handling and a full restore rehearsal. No Docker deployment command is supported.

## Completion checklist

- [ ] The demo is reachable only locally or by trusted ZeroTier peers and handles missing evidence safely.
- [ ] Both databases, approval records and the index are recoverable in disposable storage.
- [ ] Independent safety, accessibility and security reviews remain release gates.

Official references: [Uvicorn settings](https://www.uvicorn.org/settings/), [PostgreSQL dump](https://www.postgresql.org/docs/current/app-pgdump.html), [PostgreSQL restore](https://www.postgresql.org/docs/current/app-pgrestore.html).
