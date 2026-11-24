# Phase 5 public interface review

## What was compared

On November 23, 2026, the frontend thread compared the redesigned local SANDIG interface against Duck.ai and Perplexity in logged-out desktop (1280 by 800) and mobile (390 by 844) captures. The competitor screenshots are genuine browser captures taken earlier that day and indexed in the [Phase 4 screenshot comparison](phase-4-ux-review.md#screenshot-index). The SANDIG captures use Laravel and the real FastAPI barangay catalog. No questions were submitted to competitor sites. This was a visual comparison, not an accessibility conformance or usability study.

| SANDIG capture | Viewport | Local browser artifact under `%USERPROFILE%/.t3/userdata/browser-artifacts/` |
| --- | --- | --- |
| Chat | Desktop | `browser-screenshot-localhost-mwtencrn-09ab9172.png` |
| Chat | Mobile | `browser-screenshot-localhost-mwteqrlb-62217cf0.png` |
| Approved sources | Desktop | `browser-screenshot-localhost-mwtepkid-fe95b0ec.png` |

## Design decisions from the comparison

- Keep the composer visible and the primary Send action prominent, as on the inspected Duck.ai view. Avoid making an optional provider or tracking choice interrupt typing.
- Use the desktop secondary area for location privacy and evidence labels instead of account or conversation history. Keep the mobile composer ahead of optional map content. This is a local-first assistant, not an account-driven chat workspace.
- Use the same navigation and visual hierarchy in chat and the source catalog. Distinguish emergency guidance by wording and live-region semantics as well as color. Distinguish source type and time in the response rather than relying on generic citation links.
- Put the non-official status in the persistent header, not only the footer. Keep general search secondary and explicit, with no claim that a link has been verified as guidance.

After capture, final integration polish added a supplementary USGS prompt chip, direct official agency portal links, and submission progress feedback. The captures document the redesigned layout, not these final controls.

Before an official pilot, validate the interface with local users and agency reviewers, including small screens, keyboard-only navigation, a screen reader, slow or offline connections, urgent code-switched questions, and clear handling of no evidence. Visual polish does not establish operational approval.

## Post-Phase 5 UI refinement (November 23, 2026)

A follow-up pass refined the Laravel UI against common patterns in answer engines (Perplexity, Duck.ai) and public-safety apps (persistent emergency call action, plain-language status). Changes are presentation-only; the API contract, consent rules, and privacy guarantees are unchanged.

- Shared `x-layout` and `x-icon` Blade components now provide one header, nav, skip link, and footer for chat and sources. The self-hosted Instrument Sans font is loaded with `@fonts`, which the pages had not been loading.
- The persistent strip now includes a `tel:911` "Emergency? Call 911" action, using the same national hotline the backend safety module cites. Emergency replies also show a "Call 911" button.
- The chat empty state has a short hero and hazard topic cards, which fill the prompt without sending it. Replies echo the escaped question, add a one-line explanation to each evidence status, show a numbered source overview linked to the citations, and include **Copy answer** and **New question** actions.
- The composer grows with its content, shows a character counter, and sends on Enter on keyboard devices (Shift+Enter adds a new line). Enter never includes `allow_web_search`, so web search still needs an explicit click. A spinner appears while the request is sent. If the backend is unavailable (503), the question stays in the composer so it can be resent.
- The location and map panel starts collapsed below 1024 px. On desktop, the sidebar stays in view while scrolling.
- The sources page uses a two-column card grid with color-coded hazard chips. When JavaScript is available, `resources/js/sources.js` adds keyword and hazard filtering for the current page only.
