# 05 - External API integrations

## Purpose and prerequisites

Test supplementary observations and optional search without presenting SANDIG as an official service. Start FastAPI and Laravel using [Getting started](01-getting-started.md). External services are not required for preparedness chat or emergency routing. Keep credentials in the ignored root `.env` only.

## Enable and verify USGS observations

1. Set `SANDIG_ENABLE_USGS=true` in the root `.env` and restart FastAPI. No key is required. Leave it false for offline demos or when outbound access is prohibited.
2. Send `Recent USGS earthquake reports` in chat. Expect labeled supplementary reports or a no-match notice with a checked time. The feed covers M2.5+ reports in the past day; SANDIG filters to 116–128 degrees E and 4–22 degrees N, a broad rectangle, not Philippine jurisdiction or barangay boundaries. Up to three event times are returned in UTC. A matching observation does not establish local shaking or safety; no match does not establish that there were no earthquakes.
3. Send `Latest earthquake bulletin in Baguio` and `Latest typhoon warning in Baguio`. Expect no claim of verification, with directions to PHIVOLCS or PAGASA. For disabled, stale or failed USGS, expect an explicit verification limitation and no checked timestamp. If the network recovers, retry after 60 seconds.

The [USGS summary feed](https://earthquake.usgs.gov/earthquakes/feed/v1.0/geojson.php) is documented as updating every minute. No key or service guarantee is assumed. SANDIG performs at most one refresh attempt per minute per process and uses only a validated snapshot. Follow [USGS attribution guidance](https://www.usgs.gov/information-policies-and-instructions/copyrights-and-credits).

## Check Baguio weather

1. Start your local services and send `Is there a storm happening here?` or `May bagyo ba dito ngayon?`. Expect a Baguio City model estimate with a Philippine time, or an explicit unavailable notice. No location permission is needed; the request uses a fixed city point, never your device coordinates.
2. Check the PAGASA pointer separately. An Open-Meteo estimate cannot confirm that a storm or official warning is active or that an area is safe. `Latest typhoon warning in Baguio` still directs you to PAGASA without claiming to have checked its bulletin.
3. To disable outbound weather lookups, set `SANDIG_ENABLE_WEATHER=false` in the root `.env` and restart FastAPI. If the feed fails or its data is stale, SANDIG does not show an old estimate as current.

## Enable and verify web fallback

1. If approved, create a Brave Search API subscription/key for **Web Search**. Review [pricing and quotas](https://api-dashboard.search.brave.com/documentation/pricing) and the [privacy notice](https://api-dashboard.search.brave.com/documentation/resources/privacy-notice). Add the key as `SANDIG_BRAVE_SEARCH_KEY` to the ignored root `.env`, then restart FastAPI. Keep the key empty to disable search. Never put it in Laravel, browser code, output, or screenshots.
2. With **Web search** on, use **Send with web search (this request only)** for `How do I prepare for earthquakes?`. Expect up to four links from approved publisher domains, with Philippine publishers prioritized, or a notice if none are available. Ordinary **Send** searches automatically only when general preparedness is eligible and neither the approved catalog nor bundled guidance matches. Switching **Web search** off prevents both Brave paths, but does not disable a separately requested weather check. Brave receives a preset topic, a Baguio/Philippines keyword bias and optional language terms, not the message, transcript, barangay, or coordinates.
3. For `My house is on fire` as a simulated question, verify emergency priority with search enabled. Do not dial any number during testing. Find the backend regression tests with `./.venv/Scripts/python.exe -m pytest backend/tests/test_external.py -q`. They use controlled HTTP responses without spending credits.
4. An authenticated live Brave check still requires an approved key. If the check returns no links, wait five seconds, check your subscription/authentication and network access, and leave the feature disabled if verification fails. The per-process throttle is not a distributed budget. Review provider terms and enforce a shared budget before a multi-worker rollout.

## Provider selection and safety

| Provider | Status and limits |
| --- | --- |
| [PAGASA](https://www.pagasa.dost.gov.ph/) and [PHIVOLCS](https://earthquake.phivolcs.dost.gov.ph/) | Authoritative bulletin pointers only. Public pages were reachable; a stable approved warning API or republication agreement was not confirmed. Obtain agency approval and validate coverage/issue timestamps before building a connector. |
| [Brave Web Search](https://api-dashboard.search.brave.com/documentation/services/web-search) | Optional authenticated, prepaid API. SANDIG makes at most one attempt per five seconds per process. Results are transient, unverified links with no snippets, page content, or publication dates. |
| [SearXNG](https://docs.searxng.org/dev/search_api.html) | Deferred. Running a private instance with JSON enabled adds maintenance, engine usage terms, and availability requirements. Public instances need not support JSON. |
| [Open-Meteo Forecast API](https://open-meteo.com/en/docs) | Enabled by default for the local prototype. Supplementary model estimates only, never official warnings. Checks a fixed Baguio City point at most once a minute per process. Review [terms and attribution](https://open-meteo.com/en/terms) before deployment. |

Outbound calls use fixed HTTPS hosts, refuse redirects and never fetch result pages. Do not use search links as evidence for critical or current claims.

## Completion checklist

- [ ] Default chat works with external services disabled and no location selected.
- [ ] USGS shows separate provenance, scope limits and a safe offline fallback.
- [ ] If enabled, authenticated search was verified, indexed evidence took precedence, and the off setting prevented all general search.
- [ ] Keys and personal data are absent from the repository, browser and provider requests.
- [ ] No official data ingestion, deployment approval, or agency endorsement is inferred.
