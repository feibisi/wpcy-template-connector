# WPCY Template Connector — Elementor AI Proxy Plan

Date: 2026-06-08
Owner: wenpai
Agent note: [CX]

## Decision

Elementor AI proxying is feasible, but should be implemented in phases after Kali captures exact request and response schemas.

Do not start Cloudflare Worker or backend infrastructure work until the reverse-engineering dataset includes concrete payloads for each endpoint and confirms the Elementor UI expectations.

## Evidence from local Elementor 4.1.1 code

- Server connector class: `Elementor\Modules\Ai\Connect\Ai`.
- AI base URL: `https://my.elementor.com/api/v2/ai/`.
- PHP request path: `Ai::ai_request()` -> Connect `Base_App::http_request()` -> WordPress HTTP API.
- Frontend path: Elementor AI UI calls WordPress AJAX actions such as `ai_get_completion_text`, `ai_get_custom_code`, `ai_generate_layout`; it does not primarily call the external AI API directly from browser JS.
- Existing WPCY Template Connector already uses the same interception pattern for template library APIs: fake Elementor Connect state + `pre_http_request` interception.

## Proposed architecture

1. Plugin intercepts only `https://my.elementor.com/api/v2/ai/*`.
2. Plugin forwards normalized requests to a dedicated WenPai/WPCY AI bridge endpoint.
3. AI bridge maps Elementor endpoints to provider calls and returns Elementor-compatible schemas.
4. Cloudflare Worker may sit in front for auth, rate limiting, logging, and routing, but should not be mixed into the existing `templates.wpcy.net` template Worker.
5. Keep template library and AI proxy operationally separate.

## MVP scope

Start with text/code because schemas are small and visible in Elementor PHP handlers.

Required endpoints:

- `status/check`
- `remote-config/config`
- `remote-config/frontend-config`
- `status/get-started`
- `status/feedback/{responseId}`
- `text/completion`
- `text/edit`
- `text/get-excerpt`
- `text/custom-code`
- `text/custom-css`
- `text/enhance-image-prompt`
- `text/get-motion-effect`

Expected text/code response shape:

```json
{
  "text": "...",
  "responseId": "...",
  "usage": {}
}
```

`status/check` must at least satisfy Elementor's fallback expectations:

```json
{
  "hasAiSubscription": false,
  "usedQuota": 0,
  "quota": 100
}
```

## Phase 2 scope

Layout generation can be proxied after exact schema capture.

Endpoints:

- `generate/layout`
- `generate/generate-json-variation`
- `generate/html-to-elementor`
- `generate/enhance-prompt`

Required layout response shape must contain an Elementor element tree:

```json
{
  "text": { "elements": [] },
  "responseId": "...",
  "usage": {},
  "baseTemplateId": null,
  "templateType": null
}
```

If `text.elements` is empty or not an array, Elementor throws `unknown_error`.

## Phase 3 scope

Image generation/editing is the highest-risk part and should wait for exact multipart captures.

Endpoints to capture before implementation:

- `image/text-to-image`
- `image/text-to-image/featured-image`
- `image/image-to-image`
- `image/image-to-image/unify-product-images`
- `image/image-to-image/upscale`
- `image/image-to-image/remove-background`
- `image/image-to-image/replace-background`
- `image/image-to-image/outpainting`
- `image/image-to-image/inpainting`
- `image/image-to-image/cleanup`

Risks:

- multipart upload bodies
- attachment file resolution
- image URL or binary return handling
- WordPress media sideload compatibility
- quota/history/favorite side effects

## Kali reverse-engineering task

Capture exact data before infrastructure work:

1. Enumerate all Elementor AI AJAX actions reachable from the UI.
2. Trigger representative actions in local Elementor editor.
3. Capture WordPress AJAX payloads.
4. Capture outbound PHP HTTP requests to `my.elementor.com/api/v2/ai/*` before and after any interception.
5. Capture successful Elementor AI response schemas if a valid upstream connection/token is available.
6. If upstream access is unavailable, capture the UI/PHP error flow and expected local handler return keys from code.
7. Produce endpoint-by-endpoint files with method, URL, headers, content type, request body, response body, Elementor PHP handler, frontend caller, and implementation notes.

Recommended output directory on Kali:

`/home/parallels/Projects/api-reverse/elementor-ai/`

Recommended files:

- `README.md` — summary and reproduction steps
- `ENDPOINTS.md` — endpoint matrix
- `captures/*.json` — normalized request/response examples
- `schemas/*.json` — inferred request/response schemas
- `notes/*.md` — UI flow and error notes

## Implementation guardrails

- Do not patch Elementor core files.
- Do not broadly intercept all `my.elementor.com` traffic for AI; match only `/api/v2/ai/` plus known template-library paths already handled by the connector.
- Keep AI proxy disabled by default behind a Connector setting until captures are verified.
- Log endpoint, status, duration, and sanitized provider error; never log prompts with secrets unless explicit debug mode is enabled.
- Keep provider credentials server-side in WenPai/WPCY infrastructure, not in the WordPress plugin.
