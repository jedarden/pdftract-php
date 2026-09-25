# Serve-route parity gap matrix (ADR-1 evidence)

- **Date:** 2026-09-23
- **Upstream revision audited:** `pdftract` @ `eeab77e7d13b579328a8d4b3ff06616cd23f359c`
  (2026-09-10, "fix(pdfswift-6bc41168): drain stderr concurrently in Swift streaming templates")
- **Method:** static source audit only. Upstream paths cited:
  `crates/pdftract-cli/src/serve.rs` (1511 lines), `crates/pdftract-cli/src/cli.rs`,
  `crates/pdftract-cli/src/main.rs`, `crates/pdftract-core/src/extract.rs`,
  `crates/pdftract-core/src/options.rs`, `crates/pdftract-cli/Cargo.toml`.
  The upstream working tree has unrelated in-flight edits, so every citation was
  checked against the pinned SHA: `serve.rs`, `cli.rs`, `main.rs`, `options.rs`
  and `Cargo.toml` are byte-identical to that SHA (`git diff HEAD --stat` empty for
  those files); `pdftract-core/src/extract.rs` is dirty upstream, so its citation
  was verified against `git show HEAD:` content and uses the HEAD line number.
- **No runtime/empirical claims are made here.** Whether the covered routes
  behave identically to the CLI, and whether the `markdown_anchors` flag has any
  observable effect on a serve response, are matters for the conformance work
  (next split child). This file is the route inventory the ADR-1 amendment rests on.

## The commitment being evidenced

ADR-1 (`docs/plan/plan.md`, 2026-07-20) commits `pdftract-php` to an
HTTP-client transport against `pdftract --serve` as the SDK's sole transport,
covering all 9 documented `Client` methods (`extract`, `extractText`,
`extractMarkdown`, `extractStream`, `search`, `getMetadata`, `hash`,
`classify`, `verifyReceipt` — `docs/plan/plan.md:12-14`), and says the generated
model/exception tree will be "regenerated/audited against the actual HTTP
OpenAPI schema `pdftract --serve` exposes" (`docs/plan/plan.md`, ADR-1
"Decision" bullet 5).

## Upstream serve surface — complete inventory

The entire production router is `serve.rs:406-414`. There are exactly five
routes:

| Route | Handler | Evidence |
|---|---|---|
| `GET /` | server info banner | `serve.rs:407`, handler `serve.rs:495-506` |
| `GET /extract` | 404 guard (rejects file-path query params) | `serve.rs:408-411`, handler `serve.rs:521-528` |
| `POST /extract` | full extraction, JSON response | `serve.rs:408-411`, handler `serve.rs:531` |
| `POST /extract/text` | plain-text response | `serve.rs:412`, handler `serve.rs:612` |
| `POST /extract/stream` | streaming NDJSON response | `serve.rs:413`, handler `serve.rs:698` |
| `GET /health` | health check | `serve.rs:414`, handler `serve.rs:509-514` |

Other routers at `serve.rs:1171-1172` and `serve.rs:1258-1260` are inside the
`#[cfg(test)] mod tests` that starts at `serve.rs:1096-1097` — test-only, not
part of the surface. The root handler's self-description
(`serve.rs:499-504`) lists the same three POST routes plus `/health`.

Multipart form fields accepted by every POST route (`receive_pdf`,
`serve.rs:775-791`, struct `ExtractParams` `serve.rs:211-228`): `file`/`pdf`,
`receipts`, `no_cache`, `full_render`, `max_decompress_gb`, `ocr_language`,
`ocr_dpi`, `markdown_anchors`, `pages`.

## The 9-row parity matrix

Status legend: **COVERED** = a serve route exists whose transport matches the
method's contract (shape/behaviour parity still untested — conformance work).
**GAP** = no serve route exists. **OPEN** = verdict deliberately not recorded;
empirical check pending.

| # | SDK method | CLI subcommand equivalent | Serve route | Status | Source evidence |
|---|---|---|---|---|---|
| 1 | `extract` | `pdftract extract` + `--json` / `--format json` | `POST /extract` | COVERED | CLI: `cli.rs:71`, `--json` `cli.rs:91-93`, `--format` `cli.rs:107-109`, dispatch `main.rs:587`. Serve: `serve.rs:408-411`, handler `serve.rs:531`, body via `result_to_json` `serve.rs:583`, `Content-Type: application/json` `serve.rs:587` |
| 2 | `extractText` | `pdftract extract` + `--text` / `--format text` | `POST /extract/text` | COVERED | CLI: `--text` `cli.rs:99-101`, text arm `main.rs:1400-1405` (`serialize_document_text` at `main.rs:1403`). Serve: `serve.rs:412`, handler `serve.rs:612`, response `serve.rs:665-672` |
| 3 | `extractMarkdown` | `pdftract extract` + `--md` / `--format markdown` (+ `--md-anchors`, `--md-no-page-breaks`) | none — closest is a `markdown_anchors` multipart flag | **OPEN** | CLI: `--md` `cli.rs:95-97`, `--md-anchors` `cli.rs:139-141`, `--md-no-page-breaks` `cli.rs:143-145`, `Format::Markdown` arm `main.rs:1406-1443` (renderer `page_to_markdown_with_links_and_footnotes` at `main.rs:1433`). Serve: flag parsed `serve.rs:807, 879-885`, plumbed `serve.rs:991` — see gap detail below |
| 4 | `extractStream` | `pdftract extract --ndjson` | `POST /extract/stream` | COVERED | CLI: `--ndjson` `cli.rs:103-105`. Serve: `serve.rs:413`, handler `serve.rs:698`, `extract_pdf_ndjson` `serve.rs:745`, `Content-Type: application/x-ndjson` `serve.rs:768` |
| 5 | `search` | `pdftract grep` (itself feature-gated, non-default) | none | GAP | CLI: `cli.rs:208-210` (`#[cfg(feature = "grep")]`), dispatch `main.rs:692`, feature `grep = ["dep:indicatif"]` `Cargo.toml:134` with `default = []` `Cargo.toml:118`. Serve: absent from `serve.rs:406-414` |
| 6 | `getMetadata` | no dedicated subcommand — metadata rides `extract --json` output | none (metadata only embedded in the `POST /extract` full response) | GAP | CLI: no `Commands` variant other than `Extract` carries metadata (`cli.rs:29-442`); `"metadata"` key in `result_to_json`, `pdftract-core/src/extract.rs:1575`. Serve: `serve.rs:576-583` |
| 7 | `hash` | `pdftract hash` | none | GAP | CLI: `cli.rs:215-227`, dispatch `main.rs:748`. Serve: absent from `serve.rs:406-414` |
| 8 | `classify` | `pdftract classify` | none | GAP | CLI: `cli.rs:179-207`, dispatch `main.rs:659`. Serve: absent from `serve.rs:406-414` |
| 9 | `verifyReceipt` | `pdftract verify-receipt` | none | GAP | CLI: `cli.rs:213-214`, dispatch `main.rs:742`. Serve: absent from `serve.rs:406-414` |

Score: **3 of 9 covered** (extract, extractText, extractStream), **6 of 9 not
covered** (5 absent routes; extractMarkdown open pending the empirical check).

## Gap detail — what the CLI offers that serve does not

### `extractMarkdown` — OPEN

What the CLI offers: a full markdown rendering pipeline —
`Format::Markdown` (`main.rs:1406-1443`) renders each page through
`page_to_markdown_with_links_and_footnotes` (`main.rs:1433`) with HTML-comment
anchor emission (`--md-anchors`, `cli.rs:139-141` → `main.rs:1186-1187, 1408`)
and page-break control (`--md-no-page-breaks`, `cli.rs:143-145` →
`main.rs:1410`).

What serve offers: no markdown representation of any response. `POST /extract`
returns block/span JSON (`result_to_json`, `pdftract-core/src/extract.rs:1575`
emits `pages[].{index,spans,blocks,tables}`, `fingerprint`, `metadata`,
`signatures`, `form_fields`, `links`, `attachments` — no markdown field), and
`POST /extract/text` concatenates raw span text (`serve.rs:657-663`), never
calling any `page_to_markdown*` renderer.

Why the row is OPEN, not GAP: every POST route accepts a `markdown_anchors`
multipart flag (doc `serve.rs:785`; parsed `serve.rs:807, 879-885`; plumbed
into `ExtractionOptions` at `serve.rs:991`; field defined
`pdftract-core/src/options.rs:331`). Statically, the flag's only reader in the
tree is the CLI's markdown arm (`main.rs:1186-1187, 1408`) — `result_to_json`
does not consume it — so no serve response should be markdown-rendered. But
static reading is not the evidentiary bar for closing a parity row, and one
premise in the split task needs correcting: the flag is accepted on **all three
POST routes**, not two — `receive_pdf` is called by `extract_handler`
(`serve.rs:536`), `extract_text_handler` (`serve.rs:617`), and
`extract_stream_handler` (`serve.rs:705`). **The empirical check (next split
child) should exercise all three routes with `markdown_anchors=true` and
compare against CLI `--md --md-anchors` output. No verdict is recorded here.**

### `search` — GAP

What the CLI offers: `pdftract grep` — pattern search across PDFs returning
NDJSON with bounding-box results, with its own published output schema
(`docs/schema/v1.0/grep-jsonl.schema.json`). Note the CLI surface itself is
conditional: the subcommand is `#[cfg(feature = "grep")]` (`cli.rs:208-210`)
behind the non-default feature `grep` (`Cargo.toml:118` `default = []`,
`Cargo.toml:134`) — a default `pdftract` build has no `search` equivalent
anywhere, CLI or serve.

### `getMetadata` — GAP

What the CLI offers: no dedicated metadata subcommand exists (`cli.rs:29-442`
has no such variant); metadata arrives embedded in `extract --json` output
(`"metadata"` key in `result_to_json`, `pdftract-core/src/extract.rs:1575`).
Serve mirrors that by embedding metadata in the `POST /extract` response
(`serve.rs:576-583`). The gap for the SDK: **no lightweight metadata-only route
exists on either surface**, so under ADR-1 every `getMetadata()` call must pay
for a full extraction plus the full PDF upload. An amendment should either
accept that cost or add a metadata route upstream.

### `hash` — GAP

What the CLI offers: standalone structural fingerprint computation without
extraction (`pdftract hash`, `cli.rs:215-227`, dispatch `main.rs:748`). On
serve the fingerprint only appears embedded in the `POST /extract` response
(`serve.rs:580`); there is no `/hash` route (`serve.rs:406-414`).

### `classify` — GAP

What the CLI offers: document-type classification from metadata + signals
without full text extraction (`pdftract classify`, `cli.rs:179-207`), with
`--top-k`, `--exit-on-unknown`, and custom `--profiles` dirs
(`cli.rs:192-206`); dispatch `main.rs:659`. Serve has no classification route.

### `verifyReceipt` — GAP

What the CLI offers: receipt verification against a PDF file
(`pdftract verify-receipt`, `cli.rs:213-214`, dispatch `main.rs:742`).
Serve has no route.

## OpenAPI finding — there is nothing to regenerate against

ADR-1's Decision bullet 5 commits to regenerating/auditing the model tree
"against the actual HTTP OpenAPI schema `pdftract --serve` exposes". **No such
schema exists upstream at the pinned revision.** The serve API is a
hand-routed axum `Router` (`serve.rs:406-414`) with ad-hoc `serde_json!`
response bodies (`serve.rs:494-514` for root/health; `result_to_json` for the
extract payload); no OpenAPI-generating machinery is attached to it.

Search performed (all at the pinned revision; `target/` and `.beads/`
excluded):

1. Content grep, case-insensitive, for patterns `openapi`, `swagger`, `utoipa`,
   `rapidoc`, `redoc` across `*.rs`, `*.toml`, `*.yaml`, `*.yml`, `*.json`,
   `*.md` — zero real hits. The only matches are substring false positives:
   `CompareDocumentMeta` (`crates/pdftract-cli/src/inspect/api.rs:80` and its
   test consumers) contains the letters "redoc" inside "compa**redoc**ument",
   and two files under `notes/` match the same way.
2. Filename search for `*openapi*` and `*swagger*` — no files.
3. Dependency scan of `crates/pdftract-cli/Cargo.toml` for OpenAPI/Swagger
   generator crates (`utoipa`, `aide`, `okapi`, `swagger`) — none.
   `schemars` **is** a dependency (`Cargo.toml:97`) but its only use is the
   MCP tool registry's per-tool *input* JSON Schema
   (`crates/pdftract-cli/src/mcp/tools/args.rs:6`;
   `crates/pdftract-cli/src/mcp/tools/registry.rs:554, 616, 673, 740, 785,
   896, 976, 1011, 1045, 1079`) — a stdio JSON-RPC surface, not HTTP.
4. Human docs: `docs/user-docs/src/cli/serve.md` is a 5-line stub
   ("**Draft** — This page is a placeholder for future content."), so there is
   not even a complete prose description of the serve API, let alone a machine
   schema.
5. Closest existing artifacts, and why they do not satisfy ADR-1:
   `docs/schema/v1.0/pdftract.schema.json` (JSON Schema `$defs` of the CLI
   JSON output model — no `openapi` key, no HTTP paths) and
   `docs/schema/v1.0/grep-jsonl.schema.json`. These describe exactly the
   CLI-output shape ADR-1 says the models must *stop* being derived from.

Consequence for the amendment: either author the schema by hand from
`serve.rs` source (this matrix is the route inventory for that), or add an
OpenAPI surface to `pdftract --serve` upstream first. The ADR-1 amendment
should not cite a schema that does not exist.

## Addendum 2026-09-23 — the extract fingerprint is not the `hash` subcommand's value

Follow-up static finding, same upstream revision (`eeab77e`, still HEAD on
2026-09-23). It sharpens the `hash` row above from "no route" to "no route,
and the closest embedded value is a *different fingerprint*":

- What `POST /extract` embeds as `fingerprint` (`serve.rs:580`) is computed
  by `compute_fingerprint_lazy` (`pdftract-core/src/document.rs:695`,
  definition `document.rs:1597-1635`), which hashes **catalog-level data
  only**: its `FingerprintInput` carries `page_count: 0` and
  `pages: vec![]` (`document.rs:1617-1618`) — "The full fingerprint
  computation requires page content streams" (`document.rs:1594-1595`).
- What the CLI's `pdftract hash` prints (`hash.rs:300-327`) is computed from
  `build_fingerprint_input`, which materializes the page tree and hashes
  per-page data with a real `page_count` (`hash.rs:250-300`).

Both call `pdftract_core::fingerprint::compute_fingerprint`
(`fingerprint/mod.rs:140`), but over different inputs, so the two values
diverge for any document with pages. Consequence for ADR-1: an SDK `hash()`
derived from the `POST /extract` response would silently return a different
fingerprint than the CLI method it replaced — the parity question for `hash`
is not only "no route" but "no route *and* no equivalent value in any serve
response". A `hash()` port would additionally need upstream to expose the
full fingerprint (or the SDK to ship a PHP reimplementation of the page-tree
hash, which is out of scope for a client whose ADR-1 charter is transport,
not crypto/renderer, reimplementation).

Side finding: the retired CLI wrapper's `hash()` docblock advertised
`'hash'`/`'fast_hash'` keys (`src/Pdftract/Client.php:726-728`), but
`run_hash` prints a single bare fingerprint line (`hash.rs:308, 323`) —
one more entry for bead `bf-1s2`'s catalogue of wrapper contracts that do
not match the real binary.

## Ancillary finding — serve cannot process encrypted PDFs

`ExtractParams` (`serve.rs:211-228`) and the `receive_pdf` field list
(`serve.rs:775-791`) accept no password field, while the CLI's
`extract`/`hash`/`classify` all accept `--password`/`--password-stdin`
(`cli.rs:75-81, 184-190, 220-222`). The serve error path even advises
`"Supply the correct password via --password"` (`serve.rs:1037`) — advice that
is impossible to follow over HTTP. Under ADR-1 this constrains every SDK
method's signature (no password parameter can be honoured by any route) and
should be surfaced in the amendment rather than discovered during
implementation.

## Ancillary note — where the SDK method names do exist upstream

The method taxonomy ADR-1 documents (`extract`, `extractText`,
`extractMarkdown`, `search`, `getMetadata`, `hash`, `classify`, …) matches the
MCP tool registry's tool set (`ExtractArgs`, `ExtractTextArgs`,
`ExtractMarkdownArgs`, `SearchArgs`, `GetMetadataArgs`, `HashArgs`,
`ClassifyArgs`, plus `GetTableArgs`/`GetFormFieldsArgs`/`GetAttachmentsArgs`;
registry `crates/pdftract-cli/src/mcp/tools/registry.rs:554-1079`). That is a
stdio JSON-RPC surface feature-gated behind `mcp = []` (`Cargo.toml:130`), not
the HTTP serve API, and it has no `VerifyReceipt` tool. It is evidence that the
SDK's method names are upstream's own taxonomy — but it is not a transport
ADR-1 can target.
