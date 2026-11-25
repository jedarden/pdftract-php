# Model/exception-tree audit against the real serve payloads (ADR-1 bullet 5)

- **Date:** 2026-11-25
- **Bead:** `pdfphp-087173a2`
- **What this closes:** the surviving half of ADR-1 Decision bullet 5. The
  original bullet committed the generated model/exception tree to being
  "regenerated/audited against the actual HTTP OpenAPI schema `pdftract
  --serve` exposes"; § "OpenAPI finding" of `docs/notes/serve-parity-gap.md`
  established no such schema exists, and the 2026-09-27 amendment (commit
  `4781193`, bead `pdfphp-65f3444d`) redirected the bullet to real evidence —
  but the evidence it named (the parity matrix, its addenda, and the
  request-shape pins) covers *routes, request shapes, and error statuses*,
  not the *response bodies the shipped model tree would have to represent*.
  This file is that audit, and plan.md's ADR-1 bullet 5 now names it.
- **Upstream revisions.** Static reading at both ends of the audited
  lineage: `eeab77e` (the route inventory's pin) and current `origin/main`
  (`f34fc6ca`). Empirical capture against the serve-capable binary already
  recorded in `docs/notes/serve-parity-gap.md` Addendum 2026-11-25c
  (sha256 `c2841aa50ac7249521582c44e0e50c07e24c439892f184327fa82fa858af3ea5`,
  upstream `origin/main` build; smoke-gated provenance there). The binary's
  outputs corroborate the pinned lineage: every extraction below returned
  fingerprint `pdftract-v1:ab24a95f44ceca5d…`, the control value recorded on
  beads `pdfphp-2aa50d7e`/`pdfphp-08e4c83c` for the eeab77e-built binaries.
- **Method.** Every route's body captured live from a running server
  (ephemeral loopback port, `--no-cache`, health-gated, listener ownership
  confirmed via `ss`; a second server at `--max-upload-mb 1` to reach 413).
  Transcript retained under `.probe-parity/transcript-model-audit/`
  (gitignored, the earlier addenda's precedent) with one body file per
  request. One runner defect is retained in that transcript: the row
  labelled "POST /extract tiny non-pdf (400 BAD_REQUEST)" passed curl an
  invalid flag (`curl rc=2`) and its shown body is the previous request's;
  the follow-up probe rows (`followup-*.body`) re-captured that shape and
  the 422 properly.

## The five routes' actual response bodies (captured 2026-11-25)

| Route | Status | Body (captured) |
|---|---|---|
| `GET /health` | 200 | `{"status":"ok","version":"0.1.0"}` — 31 B |
| `GET /` | 200 | `{"endpoints":[4 free-text strings],"service":"pdftract","version":"0.1.0"}` |
| `POST /extract` | 200 | the 10-key envelope below |
| `POST /extract/text` | 200 | plain text, span text + `\n` per span (`Code Sample\n`, 12 B) |
| `POST /extract/stream` | 200 `application/x-ndjson` | one JSON page record per line: `{"blocks":[…],"index":0,"spans":[…],"tables":[]}` (321 B for one content-bearing page) |

The `POST /extract` success envelope (`code/code.pdf` fixture; field sets
also verified on `invoice/01.pdf`, whose `metadata` additionally carries
`diagnostics` and `diagnostics_detailed`):

- top level (10 keys): `attachments, fingerprint, form_fields,
  javascript_actions, links, metadata, pages, schema_version, signatures,
  threads`
- `metadata`: `page_count, span_count, block_count, cache_status,
  cache_age_seconds, reading_order_algorithm?`, plus `diagnostics?` /
  `diagnostics_detailed?` when the extractor emitted any
- `pages[]`: `index, spans, blocks, tables`
- `pages[].spans[]`: `bbox, color, confidence, confidence_source, font,
  rendering_mode, size, text`
- `pages[].blocks[]`: `bbox, kind, spans, text`

Error domain (all captured live; the 422/400 shapes are byte-identical to
the eeab77e transcripts already cited in serve-parity-gap.md Addendum
2026-09-27d):

| Failure | Status | Captured body |
|---|---|---|
| no `file`/`pdf` part | 400 | `{"error":"MISSING_FIELD","message":"No PDF file uploaded","hint":"Supply the 'file' multipart field"}` (101 B) |
| upload < 5 bytes | 400 | `{"error":"BAD_REQUEST","message":"Uploaded file is too small to be a valid PDF","hint":"Upload a valid PDF file (must start with %PDF-)"}` (137 B) |
| upload lacks `%PDF-` | 400 | `{"error":"BAD_REQUEST","message":"Uploaded file is not a PDF (missing %PDF- header)","hint":"Upload a valid PDF file (must start with %PDF-)"}` (142 B) |
| body over `--max-upload-mb` | 413 | `{"error":"REQUEST_TOO_LARGE","message":"Request body exceeds the configured limit"}` (83 B, hint omitted) |
| unparseable PDF (`%PDF-` prefix, no `startxref`) | 422 | `{"error":"EXTRACTION_ERROR","message":"Failed to find startxref offset\n\nCaused by:\n    startxref not found in PDF"}` (118 B) |
| malformed multipart body | 500 | `{"error":"INTERNAL","message":"Internal error during extraction","hint":"Reference tag <hex-uuid> for debugging"}` (135 B) |
| `GET /extract?…` file-path guard | 404 | the serve API's only JSON 404, 199 B, verbatim as pinned in Addendum 2026-09-27d |
| unknown path | 404 | bodyless, no Content-Type |
| wrong method on known path | 405 | bodyless, no Content-Type |
| stream route, extraction failure | **200** | in-band final record `{"error":"Failed to find startxref offset\n\nCaused by:\n    startxref not found in PDF"}` (90 B) — no code, no hint, no non-2xx |

`401/403/429/503` were not captured because serve cannot produce them (no
auth or rate limiting by design; Addendum 2026-09-27d, reinforced by
Addendum 2026-11-25c) — they remain proxy-only contracts.

**Fixture note:** the vendored `broken/corrupt.pdf` now *extracts
successfully* at `origin/main` (upstream `pdftract-0defe5b7` startxref
recovery; the fixture yields one "Broken PDF" page, still carrying the
pinned control fingerprint), so this probe re-captured the 422 row with a
crafted `%PDF-`-prefixed no-`startxref` file instead of that fixture. The
fixture's reclassification is an upstream-extractor change, not a
serve-contract change; the 422 vocabulary is unchanged (static check
below).

## Static cross-check across the audited lineage

- `result_to_json` (`pdftract-core/src/extract.rs`) emits the same top-level
  ten keys at `eeab77e` (line 1575) and `origin/main` (line 1607); the one
  delta is at `origin/main`, where `pages[]` gains an optional `type` key
  when the upstream classifier sets `page_type` — never observed on these
  fixtures. Upstream's `PageResult` carries `page_number`, `page_label`,
  `width`, `height`, `rotation`, and `annotations`, but the JSON writer
  emits none of them at either revision.
- The serve error vocabulary and status picks are unchanged
  `eeab77e` → `origin/main` (`REQUEST_TOO_LARGE` 413; `MISSING_FIELD`/
  `BAD_REQUEST` 400; `ENCRYPTED`/`WRONG_PASSWORD`/`DECOMPRESSION_LIMIT`/
  `CORRUPT_PDF`/`EXTRACTION_ERROR` 422; `INTERNAL`/`INTERNAL_PANIC` 500;
  guard `NOT_FOUND` 404), matching Addendum 2026-09-27d's inventory.
- New upstream, outside the audited router: the `--metrics` listener's
  `GET /metrics` and `GET /ready` (served only on that separate port, per
  the binary's own `serve --help`). The five-route main router the SDK
  targets is unchanged; the client consumes neither new route.

## Audit 1 — `src/Models/` against the actual bodies

The live `src/Models/` tree is two classes, both originating in the
abandoned 2026-05 WIP (preserved by commit `10a5ee5`, "wip(needle):
preserve uncommitted worker changes from interrupted beads"), not in the
d0b9b59 exception port or any serve-evidence pass.

**`Document` (`id`, `pageCount`, `title`, `pages`) — divergent on every
field.**

| Field | Verdict against the captured envelope |
|---|---|
| `id` (string, required) | no such key at any level of any response of any route |
| `pageCount` (int) | no such key; the actual count is `metadata.page_count` (snake_case, nested) |
| `title` (string) | no such key anywhere, metadata included |
| `pages` (array) | the key exists, but holds raw assoc arrays (`index/spans/blocks/tables`), never `Page` instances — nothing constructs one |
| *unrepresented* | 9 of the 10 actual top-level keys (`fingerprint`, `schema_version`, `metadata`, `signatures`, `form_fields`, `links`, `attachments`, `threads`, `javascript_actions`) have no field here |

**`Page` (`pageNumber`, `markdown`, `width`, `height`, `elements`) —
divergent on every field.**

| Field | Verdict against the captured page record |
|---|---|
| `pageNumber` (int) | the actual key is `index`, 0-based; the JSON emits no page number at either revision |
| `markdown` (string) | no markdown exists anywhere on the serve surface — the empirically settled GAP-row finding (addenda 2026-09-26, 2026-11-25, 2026-11-25b), reconfirmed by this capture (0 of the 19 captured bodies, 14 of them POST-route, carry any anchor/markup) |
| `width`, `height` (float) | absent from the page JSON; upstream computes them but the writer drops them |
| `elements` (array) | no such key; the actual content arrays are `spans`, `blocks`, `tables` |
| *unrepresented* | `index`, `spans`, `blocks`, `tables` (and origin/main's optional `type`) |

**Usage:** nothing references either class. `src/Client.php` returns the
decoded response array verbatim (`extract(): array`, `health()`,
`serverInfo()`), `extractText(): string`, `extractStream()` yields arrays —
no model hydration anywhere (`grep` over `src/`, `tests/`, `README.md`,
`composer.json`: the only hits are the two files themselves). ADR-1
bullet 5's original premise that the tree is "a reasonable representation
of the API's data shapes" is therefore false as shipped: these two classes
represent no actual surface — not the serve API audited here, and not even
the retired CLI JSON output (the wrapper's 25-class tree at
`src/Pdftract/Models/` is a different, richer snake_case shape derived from
the CLI schema document).

**Verdict: DIVERGENT — every field of both classes.** No `src/` change is
made under this bead: the client's raw-array contract is correct (it
mirrors the bodies exactly), and reshaping or deleting the two classes is
owned by the model port (`pdfphp-6b9a3bdb`) and the dead-tree deletion
(`pdfphp-ce2bbc56`), which this audit's findings now bound — a port that
targets the CLI schema shapes instead of the captured serve shapes would
reproduce this divergence at scale.

## Audit 2 — the status→exception mapping against the captured statuses

`Client::exceptionClassForStatus()` (`src/Client.php:704`) and the
hierarchy ported by `d0b9b59` (bead `pdfphp-92801363`), checked row by row
against the capture above:

| Status (source) | Class raised | Verdict |
|---|---|---|
| 400 `MISSING_FIELD` / both `BAD_REQUEST` variants | `ValidationException` | correct — all three bodies captured; code+hint travel on the exception |
| 404 guard JSON; 404 bodyless | `NotFoundException` | correct for both — picked by status alone; the bodyless variant carries no code/hint (pinned) |
| 405 bodyless | base `PdftractException` | correct — nothing to specialise |
| 413 `REQUEST_TOO_LARGE` (hint omitted) | `ValidationException` | correct |
| 422 `EXTRACTION_ERROR` (and the other four DiagCodes) | `ValidationException` | correct — the document-rejection family |
| 500 `INTERNAL` (tag hint) | base | correct — server-side |
| 200 + in-band stream `{"error": …}` | base via `decodeRecord()`, null status/code/hint | correct — the pinned buffered/stream divergence |
| 200 non-JSON on a buffered route | base via `undecodableResponse()` | correct |
| 401/403/429/503 (proxy-only) | `AuthenticationException` / `RateLimitException` / base | correct — retained deliberately as proxy contracts (Addendum 2026-11-25c cross-reference) |

Hierarchy disposition, now pinned in one place: of the ten subclasses at
the `src/` root, **four are status-mapped** (`AuthenticationException`
401/403, `NotFoundException` 404, `RateLimitException` 429,
`ValidationException` 400/413/422), **three are raised client-side**
(`TimeoutException`, `ConnectionException`, `ConfigurationException`),
and **three are reserved, never raised or mapped**
(`EncodingException`, `IOException`, `ParseException` — zero throw sites;
their docblocks and the base class's say so). Every status the serve API
can produce maps to the class the client already raises.

**Verdict: zero divergences**, independently re-established at
`origin/main` on top of the eeab77e-evidence reconciliation (Addendum
2026-09-27d, bead `pdfphp-58c8a99d`); no `src/` change warranted.

## Consequence for plan.md

ADR-1 Decision bullet 5 now (a) drops the "reasonable representation"
premise this audit falsified, and (b) names this file — not the
request-shape pins — as the evidence that the response/model half of the
commitment was actually discharged. The shipped-state section's "ten
status-mapped subclasses" phrasing is corrected to the audited
classification.
