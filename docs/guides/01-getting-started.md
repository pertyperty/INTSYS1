# 01 - Getting started on Windows

## Purpose and prerequisites

Run the local browser prototype with curated emergency guidance, scope enforcement, and approved excerpts. Requires Python 3.12, PowerShell, PostgreSQL, PHP with `pdo_pgsql`, and the Laravel dependencies in the [repository README](../../README.md). Ollama is needed for model-backed browser answers and operator semantic retrieval. Basic chat checks and fallback answers do not require it.

## Prepare the backend

The FastAPI and Laravel commands in this guide bind to `0.0.0.0` so they can accept connections over ZeroTier. This listens on all IPv4 interfaces, not just ZeroTier. Before starting, restrict inbound TCP `8000` and `8001` in Windows Firewall to trusted ZeroTier peers. Do not expose these development servers to the public internet. Use `127.0.0.1` for checks on the laptop and the laptop's ZeroTier IP from your phone; `0.0.0.0` is a bind address, not a browser URL. Keep PostgreSQL and Ollama on loopback.

From the repository root in PowerShell:

```powershell
python -m venv .venv
.\.venv\Scripts\python.exe -m pip install -r backend/requirements-dev.txt
if (-not (Test-Path -LiteralPath .env)) { Copy-Item .env.example .env }
.\.venv\Scripts\python.exe -m pytest backend/tests -q
```

These steps do not start a server. Use [Verify the browser bridge](#verify-the-browser-bridge) to start and check both services. Do not create a separate smoke-test token or use `Start-Job` to launch Uvicorn.

## Check Ollama separately

```powershell
Invoke-RestMethod http://127.0.0.1:11434/api/version
(Invoke-RestMethod http://127.0.0.1:11434/api/tags).models | Select-Object name
$env:PYTHONPATH = 'backend'
.\.venv\Scripts\python.exe -m sandig.smoke ollama
$env:SANDIG_CPU_ONLY = 'true'
.\.venv\Scripts\python.exe -m sandig.smoke ollama
Remove-Item Env:SANDIG_CPU_ONLY
```

Expect a completed text response and a nonempty finite embedding; the default embedder returned 1024 dimensions on the development laptop. The smoke check unloads each model after use and is not an accuracy or speed benchmark. No real chat question is sent to Ollama.

## Configure PostgreSQL

On this laptop, the dedicated SCRAM-authenticated PostgreSQL cluster is already provisioned. Start it in PowerShell:

```powershell
$cluster = Join-Path $env:LOCALAPPDATA 'SANDIG\postgres-phase1'
pg_ctl -D "$cluster\data" -l "$cluster\postgres.log" -w start
pg_isready -h 127.0.0.1 -p 55432
```

Expect `accepting connections`. If already running, use `pg_ctl -D "$cluster\data" status`. This cluster is separate from the existing Windows service on port 5432. Its administrator recovery credential is DPAPI-protected in `$cluster\admin-password.xml` for the same Windows user and machine; do not use it in either application.

If `pg_ctl` is not on `PATH` on this laptop, run `$env:Path = 'C:\Program Files\PostgreSQL\18\bin;' + $env:Path` in the current PowerShell terminal and retry. On another machine, substitute the path to its installed PostgreSQL binaries.

On another machine, have the PostgreSQL administrator connect using `psql -h 127.0.0.1 -p 55432 -U postgres -d postgres -W` (substitute the verified port and role) and provision separate roles and databases. Use a distinct password per role:

```sql
CREATE ROLE sandig_backend LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;
\password sandig_backend
CREATE ROLE sandig_laravel LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;
\password sandig_laravel
CREATE DATABASE sandig_backend OWNER sandig_backend;
CREATE DATABASE sandig_laravel OWNER sandig_laravel;
REVOKE CONNECT ON DATABASE sandig_backend, sandig_laravel FROM PUBLIC;
\q
```

In ignored root `.env`, set `SANDIG_DATABASE_URL=postgresql://sandig_backend:<percent-encoded-password>@127.0.0.1:55432/sandig_backend`. In ignored `main/.env`, set `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=55432`, `DB_DATABASE=sandig_laravel`, `DB_USERNAME=sandig_laravel`, and `DB_PASSWORD` to the Laravel role's password. Leave `DB_URL` unset or empty. Use your actual port. These values are already configured on this laptop. Keep passwords out of chat and shell history.

From the repo root:

```powershell
$env:PYTHONPATH = 'backend'
.\.venv\Scripts\python.exe -m sandig.smoke postgres
Set-Location main
php artisan config:clear --no-interaction
php artisan migrate --no-interaction
php artisan migrate:status --no-interaction
```

Expect `PostgreSQL OK` and all three Laravel migrations marked `Ran`.

For catalog setup, return to the repo root, set `PYTHONPATH=backend`, and run `.\.venv\Scripts\python.exe -m sandig.source_cli init`. The [ingestion guide](02-document-ingestion.md) covers approval for browser excerpts. Laravel stores recent chats in its session; FastAPI gets only the current message and does not store a transcript. Use a server-side session driver and a lock-capable cache (the local configuration uses PostgreSQL for both).

## Verify the browser bridge

In VS Code, open **Terminal > New Terminal** and select PowerShell. The repository root contains `backend`, `main`, and `.venv`. Before starting either server, confirm both ignored `.env` files exist. Run this from the root; it keeps an existing matching token and only creates one if the pair is missing or mismatched. It never prints the token:

```powershell
@'
from secrets import token_hex
from dotenv import dotenv_values, set_key
from pathlib import Path

paths = ('.env', 'main/.env')
key = 'SANDIG_SERVICE_TOKEN'
if not all(Path(path).is_file() for path in paths):
    raise SystemExit('Create both .env files before continuing.')
backend, frontend = (dotenv_values(path).get(key) for path in paths)
if backend and backend == frontend:
    print('Shared token already matches.')
else:
    token = token_hex(32)
    for path in paths:
        set_key(path, key, token)
    print('Shared token saved to both files. Restart any running servers.')
'@ | .\.venv\Scripts\python.exe -
```

Set `APP_DEBUG=false`, `BOOST_BROWSER_LOGS_WATCHER=false`, and `SESSION_ENCRYPT=true` in `main/.env`. If switching from unencrypted sessions, use a new private browser window to test; existing sessions can become unreadable. Encryption does not retroactively remove old rows or backups. Stop any existing copies of the servers before restarting. In the first terminal, from the repo root:

```powershell
Remove-Item Env:SANDIG_SERVICE_TOKEN -ErrorAction SilentlyContinue
.\.venv\Scripts\python.exe -m uvicorn sandig.api:create_app --factory --app-dir backend --host 0.0.0.0 --port 8001 --env-file .env --no-proxy-headers
```

In a second terminal from the repo root:

```powershell
Set-Location main
Remove-Item Env:SANDIG_SERVICE_TOKEN -ErrorAction SilentlyContinue
php artisan config:clear --no-interaction
php artisan serve --host=0.0.0.0 --port=8000 --tries=1 --no-interaction
```

Keep both terminals open. In a third PowerShell terminal at the repository root, check liveness **and** authenticated chat:

```powershell
Invoke-RestMethod http://127.0.0.1:8001/api/v1/health
@'
import httpx
from dotenv import dotenv_values

token = dotenv_values('.env').get('SANDIG_SERVICE_TOKEN')
if not token:
    raise SystemExit('Shared token missing from root .env.')
with httpx.Client(trust_env=False, timeout=12) as client:
    response = client.post('http://127.0.0.1:8001/api/v1/chat',
        headers={'Authorization': f'Bearer {token}'},
        json={'message': 'Hello', 'allow_web_search': False, 'auto_web_search': False})
    print(f'Authenticated chat: HTTP {response.status_code}')
    response.raise_for_status()
'@ | .\.venv\Scripts\python.exe -
```

Expect `status: ok` and `Authenticated chat: HTTP 200`. Health alone does not verify the shared token. `Hello` does not exercise Ollama. To try model-backed chat, follow the [model guide](03-model-management.md) and [document ingestion guide](02-document-ingestion.md). Restart FastAPI after changing a model setting or updating backend code.

Open `http://127.0.0.1:8000`. Switch **Web search** off in the composer and send `What goes in a go-bag?`. With no eligible indexed excerpt, expect a short answer and **Curated general guidance** under its sources. Select **New chat** and send `Ano ang laman ng go-bag?`; expect Filipino without changing settings. Open **Recent chats** (use the menu button on mobile), switch back to the first chat, then delete the second. History is local to the browser session, limited to ten chats and twenty messages per chat; conversations are pruned after 24 hours without a new exchange or can disappear sooner if the Laravel session expires. Do not enter private information on shared devices. Deletion removes the chat from the active Laravel session, not from any database backup already made.

Try `Hello`, `123`, and `test` in chat. Expect a friendly reply, not an evidence warning. Then ask `Is there a storm happening here?` and follow the [weather and web-search checks](05-external-api-integrations.md). Weather checks use a fixed Baguio City point, not your GPS location.

Use the [browser acceptance checklist](06-testing-and-evaluation.md#browser-acceptance-checklist) to check emergency, location, and offline behavior. Stop Uvicorn with `Ctrl+C` and submit again; expect **SANDIG cannot answer right now** (503). When finished, stop both web servers with `Ctrl+C`. To stop only the dedicated database, use `pg_ctl -D "$env:LOCALAPPDATA\SANDIG\postgres-phase1\data" -w stop`.

## Test from a phone over ZeroTier

Start both servers using the browser bridge commands above. Laravel still calls FastAPI at `127.0.0.1:8001` on the laptop. Verify authenticated chat locally before testing from the phone.

On a phone joined to the same ZeroTier network, open `http://<laptop-ZeroTier-IP>:8000` and submit `Hello`. Use trusted HTTPS to test browser geolocation on your phone.

For a direct API health check, open `http://<laptop-ZeroTier-IP>:8001/api/v1/health` from the phone; expect HTTP 200. If you only need to test the website, keep inbound `8001` blocked: phone chat only needs `8000`. Do not put a service token in the phone browser.

## Common errors and troubleshooting

- HTTP connection refused: check the FastAPI terminal for startup errors and confirm the root `.env` exists.
- `WinError 10048` on port 8001: another process is listening. Run `Get-NetTCPConnection -State Listen -LocalPort 8001 | Select-Object LocalAddress,OwningProcess`. If you ran an older guide's `$api` job, stop it with `Stop-Job $api; Remove-Job $api` in the terminal that started it. If you do not recognize the PID, identify it before stopping anything.
- Authenticated chat returns 401 despite healthy FastAPI: confirm the `.env` tokens match, clear the environment override in the FastAPI terminal, and restart Uvicorn. An already-running process does not pick up `.env` changes.
- Ollama unreachable or missing model: check `/api/version` and `/api/tags`; start Ollama on loopback and pull only the missing configured tags.
- PostgreSQL probe fails: check `pg_isready -h 127.0.0.1 -p 55432` (or your actual port), the role/database names, and URL encoding. Listening does not prove authentication.
- Browser returns 503: run the authenticated probe. If it returns 401, fix FastAPI first. If it returns 200, clear the environment override in the Laravel terminal, run `php artisan config:clear --no-interaction` from `main`, and restart Laravel. A missing Vite manifest requires `npm.cmd run build` in `main/`.
- Health is OK but every chat fails after updating: run `(Invoke-RestMethod http://127.0.0.1:8001/openapi.json).components.schemas.ChatRequest.properties.PSObject.Properties.Name`. If `auto_web_search` is missing, stop the older FastAPI process in its terminal and restart from this checkout. Restart Laravel as well so both sides use the same build.

## Completion checklist

- [ ] Laravel submits a question and reports backend outages safely.
- [ ] Anonymous calls are rejected; authenticated chat shows grounded guidance or clearly states when evidence is insufficient.
- [ ] Ollama chat and embeddings work with default offload and CPU mode.
- [ ] Dedicated PostgreSQL login passes the smoke probe.
- [ ] Both development servers are stopped after testing; the shared token remains in the ignored `.env` files.

Official references: [FastAPI](https://fastapi.tiangolo.com/), [Uvicorn settings](https://www.uvicorn.org/settings/), [Ollama on Windows](https://docs.ollama.com/windows), [Ollama chat](https://docs.ollama.com/api/chat), [Ollama embeddings](https://docs.ollama.com/api/embed), and [PostgreSQL psql](https://www.postgresql.org/docs/current/app-psql.html).
