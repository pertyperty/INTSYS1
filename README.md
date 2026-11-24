# SANDIG

**Current review build:** Chat streams from local Ollama and uses prior messages from the active session. Web search defaults to on; eligible query text may be sent to Brave Search or Wikipedia. Optional device coordinates may be sent to Open-Meteo for a weather estimate, not an official warning or a hazard assessment. The [project map](docs/project-map.md) and [OpenAPI contract](docs/openapi.v1.yaml) describe the new chat flow; earlier guides may describe previous behavior. This build is not ready for public deployment.

SANDIG is a local-first disaster preparedness assistant in development for Baguio City. It is not an official service, an emergency responder, or a disaster prediction system. The chat menu can start, switch, and delete chats within one session. Approved indexed passages are preferred when available; generated answers and external snippets still need verification.

- [Codebase and architecture map (presentation reference)](docs/project-map.md)
- [Implementation status and handoff](docs/implementation-status.md)
- [Architecture and implementation sequence](docs/architecture.md)
- [API contract](docs/api-contract.md)
- [Source and provider review](docs/data-sources.md)
- [Windows quickstart: database, backend and browser](docs/guides/01-getting-started.md)
- [Document intake, approval and retrieval](docs/guides/02-document-ingestion.md)
- [Local evaluation and model comparison](docs/evaluation.md)
- [Local demonstration and recovery](docs/guides/07-deployment-and-maintenance.md)
- [Operator source administration](docs/guides/08-administrator-workflows.md)

## Prepare Laravel on Windows

From the repository root in PowerShell:

```powershell
Set-Location main
composer install --no-interaction
if (-not (Test-Path -LiteralPath .env)) {
    Copy-Item .env.example .env
    php artisan key:generate
}
npm.cmd ci --ignore-scripts
npm.cmd run build
php artisan test
```

Follow the [quickstart](docs/guides/01-getting-started.md) to configure PostgreSQL and start both services for local or trusted ZeroTier testing. Keep `APP_DEBUG=false`, follow the guide's firewall restrictions, and do not regenerate an existing `APP_KEY`.
