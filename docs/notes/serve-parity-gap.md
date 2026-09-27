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
- **No runtime/empirical claims were made in the original audit.** Whether the
  covered routes behave identically to the CLI, and whether the `markdown_anchors`
  flag has any observable effect on a serve response, were deferred to the
  conformance work. The `markdown_anchors` question has since been answered
  empirically — see **Addendum 2026-09-26** below; the route-level behavioural
  parity of the covered routes has since been probed the same way — see
  **Addendum 2026-09-27** below. This file is the route inventory the ADR-1
  amendment rests on.

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
method's contract (shape/behaviour parity probed in **Addendum 2026-09-27** —
parity must not be inferred from the status alone). **GAP** = no serve route
exists. **OPEN** = verdict deliberately not recorded; empirical check pending.

| # | SDK method | CLI subcommand equivalent | Serve route | Status | Source evidence |
|---|---|---|---|---|---|
| 1 | `extract` | `pdftract extract` + `--json` / `--format json` | `POST /extract` | COVERED | CLI: `cli.rs:71`, `--json` `cli.rs:91-93`, `--format` `cli.rs:107-109`, dispatch `main.rs:587`. Serve: `serve.rs:408-411`, handler `serve.rs:531`, body via `result_to_json` `serve.rs:583`, `Content-Type: application/json` `serve.rs:587` |
| 2 | `extractText` | `pdftract extract` + `--text` / `--format text` | `POST /extract/text` | COVERED | CLI: `--text` `cli.rs:99-101`, text arm `main.rs:1400-1405` (`serialize_document_text` at `main.rs:1403`). Serve: `serve.rs:412`, handler `serve.rs:612`, response `serve.rs:665-672` |
| 3 | `extractMarkdown` | `pdftract extract` + `--md` / `--format markdown` (+ `--md-anchors`, `--md-no-page-breaks`) | none — closest is a `markdown_anchors` multipart flag, empirically inert (Addendum 2026-09-26) | **GAP** | CLI: `--md` `cli.rs:95-97`, `--md-anchors` `cli.rs:139-141`, `--md-no-page-breaks` `cli.rs:143-145`, `Format::Markdown` arm `main.rs:1406-1443` (renderer `page_to_markdown_with_links_and_footnotes` at `main.rs:1433`). Serve: flag parsed `serve.rs:807, 879-885`, plumbed `serve.rs:991` — see gap detail below and Addendum 2026-09-26 |
| 4 | `extractStream` | `pdftract extract --ndjson` | `POST /extract/stream` | COVERED | CLI: `--ndjson` `cli.rs:103-105`. Serve: `serve.rs:413`, handler `serve.rs:698`, `extract_pdf_ndjson` `serve.rs:745`, `Content-Type: application/x-ndjson` `serve.rs:768` |
| 5 | `search` | `pdftract grep` (itself feature-gated, non-default) | none | GAP | CLI: `cli.rs:208-210` (`#[cfg(feature = "grep")]`), dispatch `main.rs:692`, feature `grep = ["dep:indicatif"]` `Cargo.toml:134` with `default = []` `Cargo.toml:118`. Serve: absent from `serve.rs:406-414` |
| 6 | `getMetadata` | no dedicated subcommand — metadata rides `extract --json` output | none (metadata only embedded in the `POST /extract` full response) | GAP | CLI: no `Commands` variant other than `Extract` carries metadata (`cli.rs:29-442`); `"metadata"` key in `result_to_json`, `pdftract-core/src/extract.rs:1575`. Serve: `serve.rs:576-583` |
| 7 | `hash` | `pdftract hash` | none | GAP | CLI: `cli.rs:215-227`, dispatch `main.rs:748`. Serve: absent from `serve.rs:406-414`. CLI-side wrapper semantics verified against the real binary — **Addendum 2026-09-27b** |
| 8 | `classify` | `pdftract classify` | none | GAP | CLI: `cli.rs:179-207`, dispatch `main.rs:659`. Serve: absent from `serve.rs:406-414` |
| 9 | `verifyReceipt` | `pdftract verify-receipt` | none | GAP | CLI: `cli.rs:213-214`, dispatch `main.rs:742`. Serve: absent from `serve.rs:406-414` |

Score: **3 of 9 covered** (extract, extractText, extractStream), **6 of 9 not
covered** (6 absent routes; extractMarkdown's only serve-side gesture — the
`markdown_anchors` flag — is empirically inert on all three POST routes,
Addendum 2026-09-26).

## Gap detail — what the CLI offers that serve does not

### `extractMarkdown` — GAP (empirically settled 2026-09-26)

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

Why the row is GAP, not OPEN: every POST route accepts a `markdown_anchors`
multipart flag (doc `serve.rs:785`; parsed `serve.rs:807, 879-885`; plumbed
into `ExtractionOptions` at `serve.rs:991`; field defined
`pdftract-core/src/options.rs:331`). Statically, the flag's only reader in the
tree is the CLI's markdown arm (`main.rs:1186-1187, 1408`) — `result_to_json`
does not consume it — so no serve response should be markdown-rendered. Static
reading alone was not the evidentiary bar for closing a parity row, and one
premise in the split task needed correcting: the flag is accepted on **all
three POST routes**, not two — `receive_pdf` is called by `extract_handler`
(`serve.rs:536`), `extract_text_handler` (`serve.rs:617`), and
`extract_stream_handler` (`serve.rs:705`). The empirical check has since been
run against all three routes (Addendum 2026-09-26): responses are
byte-identical with the flag omitted, `true`, `false`, or set to a garbage
value, and no serve response ever carries markdown or anchor markup. **Verdict:
the flag is accepted but inert over HTTP — unobservable in any serve response.
The row is GAP. See Addendum 2026-09-26 for the evidence.**

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

## Addendum 2026-09-26 — the `markdown_anchors` serve flag is accepted but inert (empirical)

This records the empirical verdict the original audit deferred. Same upstream
revision (`eeab77e`). Bead: `pdfphp-f16b41ae`.

**Binary provenance.** The probe served from a release build of a tree that is
byte-identical to upstream `eeab77e` (`cli.rs`, `main.rs`, `options.rs`,
`Cargo.toml` compared byte-for-byte against `git show eeab77e:` content) with
**one disclosed deviation**: `serve.rs`'s two `axum::serve` call sites were
changed to `into_make_service_with_connect_info::<std::net::SocketAddr>`.
Without it the `audit_middleware` `ConnectInfo` extractor
(`middleware/audit.rs:102-103`) panics every request into a 500 and no POST is
provable at all; the patch touches only service construction — no extraction,
routing, or serialisation code. The unpatched binary was separately confirmed
to return an identical 500 on every request, i.e. the patch changes nothing
about any successful response other than making them exist.

**Upstream blocker, disclosed.** At `eeab77e` extraction fails for *every*
document on *every* available build: `LazyPageIter`'s first page error is
swallowed at `pdftract-core/src/extract.rs:746-752` (`Some(Err(_)) | None =>
break`) and surfaces as `EXTRACTION_ERROR`/"Document contains no pages". The
with/without comparison therefore runs over the routes' **error** responses.
That limitation is closed by the static half of the verdict: no successful
serve response could consult the flag either, because its only reader in the
tree is the CLI markdown arm (`main.rs:1187` sets it, `main.rs:1408` reads it,
inside `Format::Markdown` — unreachable from `serve.rs`); `result_to_json`,
the text serialiser, and the NDJSON serialiser never touch it.

**What was run.** Vendored conformance fixtures (`invoice/01.pdf`,
`contract/01.pdf`, `misc/01.pdf` from `tests/sdk-conformance/fixtures/`)
POSTed to all three POST routes with `markdown_anchors` omitted, `=true`,
`=false`, and `=banana` (garbage value), plus a repeat-omit for determinism and
a `totally_unknown=1` field as recognition control; byte-compared all pairs.
CLI contrast with the same binary and fixtures: `extract --md -` vs
`extract --md - --md-anchors`.

**Results.**

- 48 serve responses collapse to exactly **2 distinct bodies**: the
  `POST /extract` and `/extract/text` 422 JSON error
  (`{"error":"EXTRACTION_ERROR","message":"Document contains no pages"}`, 67 B)
  and the `/extract/stream` 200 NDJSON error line
  (`{"error":"Document contains no pages"}`, 39 B). Every paired comparison —
  omit vs `true`, `false`, `banana`, repeat-omit, per fixture, per route — is
  **byte-identical**. A garbage value is silently coerced
  (`parse_bool(...).unwrap_or(false)`, `serve.rs:879-885`), never rejected.
- **No response body contains anchor markup** (`<!-- pdftract:`) or mentions
  the flag in any variant.
- Recognition control: `totally_unknown` produces the
  `Unknown multipart field 'totally_unknown' ignored` WARN
  (`serve.rs` logs the known-field list, which names `markdown_anchors`);
  `markdown_anchors` itself never WARNs. So the flag *is* parsed and accepted —
  the only observable trace of sending it, and even that trace is in the
  server log, not the response.
- CLI contrast: `--md-anchors` demonstrably flows on the CLI surface — the
  binary prints `Markdown anchors enabled` to stderr only when the flag is set
  (`main.rs:1187`→`1408` path is reached) — but output is `rc=1`, 0 bytes at
  this revision for every fixture, from the same upstream extraction bug. The
  flag demonstrably *does* something on the CLI markdown path and demonstrably
  has *no* consumer on any serve path.

**Verdict.** The `markdown_anchors` multipart field is **accepted but inert
over HTTP**: no serve response varies with it at the pinned revision, and no
serve response could, statically, at any revision with this code shape. The
matrix row 3 status OPEN → **GAP** is thereby evidenced: there is no markdown
representation of any response available over serve at `eeab77e`.

**Consequence for option-forwarding callers** (the `pdfphp-e27d7b93`
forwarded-fields pins): a client that forwards `markdown_anchors` as a
multipart field gets it accepted (no error, no warning surfaced in the
response) and nothing else — it is a dead option over HTTP until upstream adds
a markdown serve representation. The SDK should not promise, document, or test
any behavioural effect of `markdown_anchors` on a serve response; the
`Client::extract()` forwarding docblock records this.

## Addendum 2026-09-27 — route-level behavioural parity of the three COVERED routes (empirical, failure domain)

This records the serve-vs-CLI behavioural probe the original audit deferred —
the question behind the three COVERED rows. Upstream revision: the same probe
build as Addendum 2026-09-26 (`eeab77e` plus its disclosed patches), with two
additional **stderr-only PROBE diagnostics** (one printing the error chain in
`main.rs`'s extract dispatch, one printing a first-page error inside
`pdftract-core/src/extract.rs`'s page-collect loop if one ever arrives). The
two commits after `eeab77e` (`69b8ba851`, then `a2ed4c96` — current upstream
HEAD, 2026-09-26) touch only `templates/sdk-skeleton/swift/*` — 5 files across
the whole range, nothing under `crates/` — so `eeab77e` and HEAD are
behaviourally identical for every route and CLI mode probed here.
Bead: `pdfphp-5f2310e5`.

**What was run.** Twelve vendored fixtures
(`invoice/01.pdf`, `contract/01.pdf`, `misc/01.pdf`, `scientific_paper/01.pdf`,
`code/code.pdf`, `fillable-form/form.pdf`, `mixed/mixed.pdf`,
`vertical/vertical.pdf`, `xmp/xmp-metadata.pdf`, `large/50pages.pdf`,
`encrypted/encrypted.pdf`, `broken/corrupt.pdf`), each driven through:

- the CLI, exactly as a terminal user runs it: `pdftract extract <file>
  --json -`, `… --text -`, and `… --ndjson` (note `--ndjson` is a **boolean
  flag** writing NDJSON to stdout — it takes no `-` PATH argument; passing one
  is a clap usage error, rc=2, which is how a first pass of this probe ran it);
- the serve routes, raw multipart POSTs with curl and no SDK:
  `POST /extract`, `POST /extract/text`, `POST /extract/stream` — 36
  responses;
- the same three routes a third time through the canonical HTTP client, as
  executable PHPUnit pins: `tests/ClientServeParityTest.php` (group
  `serve-parity`, env `PDFTRACT_SERVE_BIN=<binary>`), which asserts the
  serve↔CLI, SDK↔serve, and SDK↔CLI layers per fixture and route and skips
  when no binary is configured. Executed against the probe binary on
  2026-09-27: **36 tests, 468 assertions, green** — every failure-domain
  assertion live; the success-domain branches cannot fire at this revision
  and remain the forward pin.

**The two failure classes.** Every fixture fails on both surfaces at this
revision, in exactly two classes that correlate with fixture structure, not
with route or mode:

- **Class A — catalog parses, page iteration ends empty** (the four
  1.8–2.5 KB fixtures: `invoice/01`, `contract/01`, `misc/01`,
  `scientific_paper/01`): CLI rc=1 with the root cause `Document contains no
  pages` on stderr and 0 bytes on stdout.
- **Class B — catalog-level defect, parse fails before pages** (the other
  eight, all failing identically): root cause `No /Root reference in trailer`.
  None of the twelve fixtures carries `/Encrypt`; the fixture vendored as
  `encrypted/encrypted.pdf` is a 582-byte stub whose `/Root` points at a
  missing object, so it lands in this class rather than exercising any
  password path (consistent with the ancillary finding that serve could not
  honour one anyway).

**Results — buffered routes (`POST /extract` ↔ `--json -`,
`POST /extract/text` ↔ `--text -`).** The 24 buffered responses collapse to
exactly 2 distinct bodies — the 422
`{"error":"EXTRACTION_ERROR","message":"<root cause>"}` per class. For **12 of
12 fixtures the serve `message` is byte-identical to the CLI's stderr root
cause**, both surfaces fail together, and the CLI's failure surface is
identical across its own output modes (rc=1, empty stdout, root cause on
stderr). **Verdict: equivalent on the failure domain.** The one asymmetry is
vocabulary, not behaviour: the CLI has no machine-readable error-code channel,
so `EXTRACTION_ERROR` exists only on the serve side.

**Results — stream route (`POST /extract/stream` ↔ `--ndjson`).** Root-cause
parity is exact here too (12/12), but the channel **diverges structurally**,
and this is pinned rather than smoothed over:

- the CLI fails identically in every mode: rc=1, 0 bytes on stdout, root
  cause on stderr;
- serve answers **HTTP 200** (not 422) with a single newline-terminated
  in-band NDJSON record `{"error":"<root cause>"}` — no `message` field, no
  error code;
- consequently the SDK surfaces differ: the buffered 422 maps to
  `ValidationException` carrying the status and the `EXTRACTION_ERROR` code,
  while the in-band record maps to the base `PdftractException` with a null
  status and a null error code (`Client::decodeRecord()`).

**Verdict: equivalent in root cause, deliberately divergent channel** — a
client branching on exception type or error code behaves differently on the
stream route than a CLI script branching on exit status. The PHPUnit pins
assert the divergence explicitly so an upstream change to it (say, the stream
route adopting the buffered error body) fails loudly here first.

**Success domain: unreachable at the pinned revision.** Extraction fails for
every fixture in every mode, so successful-response parity (the JSON payload,
the text body, the NDJSON record sequence) remains unpinned. The PHPUnit
success branches are the forward pin: they execute and enforce byte-level
serve↔CLI equality the moment a binary that can actually extract is supplied
(upstream defect — see the mechanism refinement below — not a serve-side
property; nothing in the route code suggests a success-domain divergence, but
that stays a static impression until the forward pin can run).

**Mechanism refinement to Addendum 2026-09-26.** That addendum attributed the
uniform failure to `LazyPageIter`'s first page error being swallowed at
`extract.rs:746-752` (`Some(Err(_)) | None => break`). With the PROBE
diagnostic inside that match arm, **no `Err` ever arrives for the Class A
fixtures: the iterator yields `None` cleanly**. The swallow site is real — it
does not distinguish "end of pages" from "error", so upstream still hides the
underlying cause — but the observed mechanism is "the page iterator ends
empty without surfacing an error", not "an error is swallowed". The
uniform-failure conclusion of Addendum 2026-09-26 is unaffected.

**Consequence for the matrix.** Rows 1, 2, and 4 stay COVERED, now with
demonstrated failure-domain behaviour behind the claim instead of route
existence alone: rows 1 and 2 equivalent (serve message ≡ CLI root cause,
12/12), row 4 equivalent-in-cause with the in-band 200/error-record divergence
pinned. Success-domain parity is the residual gap, gated on the upstream
page-iterator defect (bf-4bd's territory), with executable forward pins
waiting in `tests/ClientServeParityTest.php`.

## Addendum 2026-09-27b — the hash row, CLI side: the wrapper's hash() verified against the real binary (empirical)

This records the empirical verification of the `hash` row's CLI half. Upstream
revision: the same probe build as Addendum 2026-09-26 (byte-identical to
`eeab77e` plus its disclosed `serve.rs` service-construction patch, which
touches no hash code). Beads: `pdfphp-ee8d6dbd` (implementation + wrapper
contract pins, commit `8fff818`) and `pdfphp-fb9eb723` (sweep-harness pins +
this record).

**What the row claimed before.** `hash` is GAP on serve (no route), and — the
2026-09-23 addendum — the fingerprint embedded in `POST /extract` is computed
over catalog-only input, so it is a *different value* than `pdftract hash`
prints. That finding was static. The CLI-side semantics themselves had no
empirical pin in the sweep harness, unlike the other eight methods.

**What was run.** The CLI-subprocess Client's `hash()`
(`src/Pdftract/Client.php`, the repaired implementation) driven against the
probe binary through the sweep harness
(`tests/ClientBinaryConformanceTest.php`, group `binary-conformance`, env
`PDFTRACT_BIN`), over the vendored fixtures `scientific_paper/11.pdf` and
`12.pdf`. Pins, all green (43 tests, 143 assertions, 5 skips across the
whole `binary-conformance` group — the same run classifies the other
methods' still-unfixed cases as before and exercises pdfphp-ee8d6dbd's
four real-binary wrapper pins too):

- **INV-13 exact.** The returned fingerprint matches
  `^pdftract-v1:[0-9a-f]{64}$` — upstream's own invariant
  (fingerprint/mod.rs:23). The wrapper's validation deliberately accepts the
  version-prefixed family (a future `pdftract-v2:` passes); the harness holds
  today's binary to v1.
- **Determinism.** The same file hashes identically across runs.
- **Structural, not content-addressing — the 2026-09-23 addendum confirmed
  empirically.** 11.pdf and 12.pdf are bytewise distinct (md5
  `33fa571e…` vs `9e89aada…`, 2534 vs 2553 bytes) yet the binary prints the
  identical `pdftract-v1:ab24a95f…ea8a8` line for both. The naive pin
  (fingerprints differ across distinct documents) *fails* against the real
  binary and is deliberately inverted: "different documents never collide"
  is not pinnable, the collision is.
- **Return shape.** Exactly one `hash` key. The vendored suite's hash-case
  `expected` blocks (`fast_hash`, `page_count`, `content_hash_stable`) were
  authored against the wrapper's retired fictional contract and stay
  unenforced; the harness pins the opposite shape.
- **Byte equality with the raw subcommand.** `hash()`'s value equals
  `pdftract hash <fixture>` stdout, byte for byte.
- **Failure domain.** A missing input fails *inside* the binary — exit code
  2, `Failed to compute fingerprint from file: …` on stderr, no clap parse
  marker — which is what distinguishes hash's argv (accepted end-to-end, the
  bf-1s2 known-good control) from the seven parse_error rows the fix beads
  are flipping.

**Mutation check** (the sibling beads' protocol, on a copy of the tree):
mutating `hash()`'s argument construction — `['hash']` → `['hashs']` (wrong
subcommand), or dropping the source positional entirely (missing argument) —
flips every hash-asserting pin to failure: 43 tests, 11 failures under each
mutation (all seven sweep-harness pins plus pdfphp-ee8d6dbd's four
real-binary wrapper pins in `tests/ClientHashConformanceTest.php`), with
clap's `unrecognized subcommand` /
`required arguments were not provided` markers on stderr. The pins bind the
argument construction, not just the happy path.

**Bearing on the bf-4bd hash row.** The verdict is unchanged and now
two-sided: `hash` stays **GAP** on serve — no route, and the closest embedded
value is a different fingerprint (static, 2026-09-23 addendum), while the
value the CLI prints is structural over the full page tree (now demonstrated:
the bytewise-distinct twins share it). A serve-derived `hash()` would still
silently diverge from the CLI method it replaced, and at `eeab77e` the
extract-side value is unobtainable empirically anyway (every fixture fails
extraction). The method-surface decision itself remains `bf-4bd`'s; what this
addendum settles is that the CLI-side wrapper — the only hash() that can
exist today — matches the real binary on format, determinism, structure,
shape, bytes, and failure domain.



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
