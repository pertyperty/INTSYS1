# Phase 4 browser UX comparison

## Method

On November 23, 2026, the frontend thread captured public, logged-out chatbot landing views in a private browser session at matching desktop and mobile viewport sizes. No questions or personal information were submitted to the benchmark sites. SANDIG's two baseline and two redesign screenshots used the same local no-catalog state for a layout comparison; the subsequent live integration captures show the real catalog separately. This is a small interface review, not a usability study, a formal accessibility audit, or an assessment of competitor answer quality.

## Screenshot index

The PNGs are local T3 browser artifacts under `%USERPROFILE%\.t3\userdata\browser-artifacts\`, not files required to run the repo. Their dimensions were checked against the viewport labels.

| Subject | URL | Desktop (1280 x 800) | Mobile (390 x 844) |
| --- | --- | --- | --- |
| SANDIG before | `http://localhost:8000/` | `browser-screenshot-localhost-mwtdq0b6-a1e5de3b.png` | `browser-screenshot-localhost-mwtdqjtq-2cc78e7c.png` |
| Duck.ai | `https://duck.ai/` | `browser-screenshot-duck-ai-mwtdr96c-195dfabf.png` | `browser-screenshot-duck-ai-mwtdruv3-e2ab0c94.png` |
| Perplexity | `https://www.perplexity.ai/` | `browser-screenshot-www-perplexity-ai-mwtdrb6m-5e2d3cbf.png` | `browser-screenshot-www-perplexity-ai-mwtdrvzg-ab95743c.png` |
| SANDIG after | `http://localhost:8000/` | `browser-screenshot-localhost-mwtdv7jr-fdb6b098.png` | `browser-screenshot-localhost-mwtduqfg-972292e4.png` |

Live Laravel-to-FastAPI catalog and Irisan chat, desktop: `browser-screenshot-localhost-mwte0k8d-80bb5415.png` and `browser-screenshot-localhost-mwte1eb7-ce0c75b5.png`.

## Findings and changes

| Observation | Change in SANDIG |
| --- | --- |
| Duck.ai's mobile composer kept submit visible, while SANDIG's initial stacked controls put the top of Send at y=845 in an 844 px viewport. | Keep the composer and controls together. The recapture puts Send at y=394, above the fold with the starter questions. |
| Duck.ai attached a short privacy notice to its composer. | Keep the optional barangay selector and concise privacy language directly underneath SANDIG's composer. |
| Perplexity's mobile cookie modal obscured its composer in the captured state. | Avoid blocking overlays for optional location and tiles. Give the map and device reading their own disclosure section and show an explicit opt-in for third-party tiles. |
| A blank question field doesn't show a first-time user what SANDIG can answer. | Add English and Filipino starter questions that populate the composer without automatically sending anything. |

Repeat the comparison with logged-out browser views resized to the two viewport sizes; include both the default SANDIG screen and the catalog-unavailable state. On mobile, verify Send is visible before scrolling and optional location controls do not interrupt typing or submitting a question.
