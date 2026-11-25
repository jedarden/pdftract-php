# ADR-1 amendment memo — the six serve-unreachable Client methods

- **Date:** 2026-11-25
- **Status:** DRAFT FOR OPERATOR RULING — **not decided.** This memo presents
  options and one drafter's recommendation. The decision itself is recorded
  by bead `pdfphp-ddcdd874` (bf-4bd child 4 of 5) verbatim in its Decision
  section, and applied to `docs/plan/plan.md` by `pdfphp-b3788e75` (child 5
  of 5). Nothing here binds the SDK until that ruling lands.
- **Prepared under:** `pdfphp-89fd846b` (bf-4bd split child 3 of 5).
- **Evidence base:** `docs/notes/serve-parity-gap.md` (the parity matrix and
  its addenda of 2026-09-23, 2026-09-26, 2026-09-27, 2026-09-27b/c/d/e and
  2026-11-25), `docs/notes/codegen-stubs-resolution.md`, ADR-1 in
  `docs/plan/plan.md` (as amended on the OpenAPI bullet, bead
  `pdfphp-65f3444d`), and the current committed source
  (`src/Client.php`, `tests/`, `README.md`). Every claim below is grounded in
  those records; where a claim rests on an empirical probe, the addendum is
  named.

## The question the amendment must answer

ADR-1 (2026-07-20) commits the SDK to an HTTP-client transport against
`pdftract --serve` "as the SDK's sole transport, covering all 9 documented
`Client` methods". The audited upstream serve surface exposes exactly five
route paths — the root banner, `/extract` (a GET 404 guard plus the POST
handler), `/extract/text`, `/extract/stream`, and `/health` — which cover
**3 of 9** methods (`extract`, `extractText`, `extractStream`). Six have no
serve
transport: `extractMarkdown`, `search`, `getMetadata`, `hash`, `classify`,
`verifyReceipt` (matrix § "The 9-row parity matrix", score line; upstream
revisions audited: `eeab77e` statically, `4481482e7` empirically, static
cross-check of `origin/main` `f34fc6ca` in Addendum 2026-11-25).

The operator must pick the amendment's transport-scope rule. This memo
presents the three options bf-4bd names, a fourth the matrix itself dangles,
and one recommendation.

## What kind of gap each method is — the dispositions are not uniform

The six uncovered methods do not share one gap shape, and no single-option
disposition treats them well without noticing this. From the matrix:

| Method | Gap class | What the wire actually has today |
|---|---|---|
| `extractMarkdown` | **P — representation gap** | Content extracts identically on both surfaces (Addendum 2026-11-25: stripped markdown wrapper equals serve span texts for all 3 fixtures), but no serve response carries markdown: zero `page_to_markdown*` references in `serve.rs`, the `markdown_anchors` multipart flag is byte-inert on error bodies (Addendum 2026-09-26) **and** on successful extractions (Addendum 2026-11-25), while the CLI's `--md` is alive and flag-sensitive (36 → 115 B with `--md-anchors`). Verdict line: "DIVERGENT … the representation — the thing `extractMarkdown()` contracts for — is unreproducible over HTTP at `4481482e7` and at current `origin/main`." |
| `hash` | **V — value gap** | No route, **and** the closest embedded value is a different fingerprint: `POST /extract`'s `fingerprint` is computed over catalog-only input (`page_count: 0`, `pages: vec![]`, Addendum 2026-09-23, `document.rs:1617-1618`), while the CLI's `pdftract hash` hashes the full page tree — empirically structural, not content-addressing (bytewise-distinct twins share one fingerprint, Addendum 2026-09-27b). A serve-derived `hash()` would silently return the wrong value. |
| `getMetadata` | **E — embedded-value gap** | The metadata **is** on the wire — embedded in the `POST /extract` response (`result_to_json`, `pdftract-core/src/extract.rs:1575`; `serve.rs:576-583`) — but there is no lightweight route on either surface, so an HTTP `getMetadata()` must pay a full extraction plus the full PDF upload per call (matrix § "`getMetadata` — GAP"). |
| `search` | **R+ — route gap, CLI counterpart itself feature-gated** | No route; the CLI's `pdftract grep` is behind the non-default `grep` feature (`cli.rs:208-210`, `Cargo.toml:118` `default = []`, `Cargo.toml:134`). A default `pdftract` build has no search equivalent anywhere, CLI or serve. |
| `classify` | **R — route gap** | No route; the CLI subcommand is unconditional (`cli.rs:179-207`, dispatch `main.rs:659`). |
| `verifyReceipt` | **R — route gap** | No route; the CLI subcommand is unconditional (`cli.rs:213-214`, dispatch `main.rs:742`). |

Two cross-cutting evidence items constrain every option:

- **No OpenAPI schema exists to regenerate against** (matrix § "OpenAPI
  finding": hand-routed axum `Router`, no generator crate, `schemars` used
  only for MCP tool inputs, the serve user-doc is a 5-line stub; the closest
  artifacts are CLI-output JSON Schemas — exactly the shape ADR-1 bullet 5
  says the models must stop being derived from). The original bullet-5
  commitment is already struck as void in `plan.md` (bead `pdfphp-65f3444d`);
  the surviving commitment is an audit against the routes' actual bodies
  (`pdfphp-087173a2`, open). Any option that adds methods must say what
  their models are audited against — there is no schema to cite, and the
  matrix's own instruction is that the amendment "should not cite a schema
  that does not exist."
- **Serve cannot honour passwords at all** (Addendum 2026-09-27d, trailing
  block): `ExtractParams`/`receive_pdf` accept no password field while the
  CLI's `extract`/`hash`/`classify` accept `--password`/`--password-stdin`,
  and the serve 422's own hint ("Supply the correct password via
  `--password`") is impossible to follow over HTTP. Under any HTTP-only
  disposition, encrypted-document workloads are outside the SDK's reach
  until upstream adds a password-capable serve contract. Only a CLI
  fallback (option B) can honour them today.

## Option A — track upstream serve-route additions as a prerequisite of ADR-1 completion

**The rule:** ADR-1 stays "HTTP-only, eventually all 9 methods"; bf-4gq's
completion is formally gated on upstream adding the missing routes. bf-4bd
stays open as the tracking bead until upstream delivers.

**Effort.** Almost entirely upstream, not here: routes for `search`,
`getMetadata`, `hash`, `classify` and `verifyReceipt`, plus a markdown
representation — a sixth route or a `format=markdown`-style output selector
on the existing POST routes, as the Addendum 2026-11-25 consequence line
suggests — plus a decision upstream
must make anyway — publish an OpenAPI surface (none exists; § OpenAPI
finding) or accept that the SDK audits bodies empirically. SDK-side work
until routes land: none. The route inventory this memo rests on is
explicitly "the route inventory the ADR-1 amendment rests on" (matrix
header), i.e. the upstream work has a written spec waiting.

**Risk.** The prerequisite is outside this repo's control and undated.
Counter-evidence to "upstream never moves": it demonstrably does — the
ConnectInfo serve defect was fixed upstream (`2566480f0`, wiring pinned by
`62ff1e90e`) after this repo's audit surfaced it, and parser work between
`eeab77e` and `4481482e7` made all twelve vendored fixtures extract
(Addendum 2026-09-27e). But `grep` being feature-gated non-default shows
upstream also ships capabilities it does not consider core; search is the
row most likely never to ship. The dominant risk is bf-4gq staying open
indefinitely with its remaining children blocked or re-scoped.

**Migration/rollback impact.** Nothing changes now, so nothing can need
rolling back. The cost is schedule, not reversibility. If upstream stalls,
option A degenerates into option C by default — the surface stays
3-methods.

**Effect on bf-4gq's reconciliation children.**

- `pdfphp-92801363` (exception hierarchy) — closed; unaffected.
- `pdfphp-decde78f` (complete the HTTP Client surface) — **blocked as
  written.** Its acceptance criteria demand "all nine SDK methods … exist on
  the HTTP Client", which no option can satisfy today except B. Under A it
  must be re-scoped by the ruling to "three methods now; six deferred on the
  upstream gate", or held open until routes land.
- `pdfphp-6b9a3bdb` (port 25 model classes) — proceeds mechanically, but its
  audit target ("against the JSON shape the HTTP serve API actually returns")
  exists only for the covered routes' shapes; the six methods' models (e.g.
  `Receipt`, search-result shapes) would be ported with nothing live to
  audit against — the standing consequence of the missing OpenAPI finding.
- `pdfphp-ce2bbc56` (delete `src/Pdftract/`) — proceeds unchanged; A adds no
  reason to keep the dead tree.
- `pdfphp-9c33ad83` (verify end to end) — cannot close while bf-4gq holds an
  undated upstream gate; effectively deferred with `decde78f`.

**Conformance suite.** Unchanged today: the two SERVE_BIN-gated suites
(`serve-parity`: 36 tests / 420 assertions green at `4481482e7`;
`real-server`: 10 / 73) keep gating on `PDFTRACT_SERVE_BIN`, and CI's
`ci-assert-gated-ran.php` keeps proving the gated cases executed. The
suites' per-route pin architecture is exactly the landing pad for new
routes — when a route lands, its parity pin extends the existing suite, no
rework. The retired CLI suites stay retired.

**Public Client surface / breaking posture.** The live `Client` already
exposes exactly three methods with the exclusion documented in its class
docblock (`src/Client.php:45-48`, "tracked as bead bf-4bd"). Under A the
surface stays three-methods indefinitely; the nine-method promise in
plan.md stays written but unmet — the amendment's honest form is "3 today,
6 on the upstream gate". No breaking change now or later (future additions
are non-breaking).

**Disposition of each uncovered method.** All six: **deferred to upstream** —
no SDK work, no substitute surface, parity-matrix rows stay GAP until
upstream closes them. `hash` needs a route returning the subcommand-grade
page-tree fingerprint specifically (Addendum 2026-09-23's warning: the
embedded extract fingerprint is the wrong value); `getMetadata` needs either
a lightweight route or an explicit decision to ship it on the embedded
value's cost; `extractMarkdown` needs a markdown representation or output
selector; `search` needs a route *and* a decision to promote the
feature-gated CLI capability to the serve surface.

## Option B — a temporary CLI-subprocess fallback scoped to the uncovered methods

**The rule:** HTTP is sole transport for the three covered methods; the six
uncovered methods ship on a CLI-subprocess fallback until upstream serves
them, at which point the fallback is removed. This partially and temporarily
reinstates ADR-1's rejected Alternative 2 ("support both transports behind a
shared interface … can be revisited later if a concrete need for the CLI
path re-emerges (e.g. an offline/air-gapped use case)" — `plan.md`,
Alternatives ¶2). The revisit clause exists; the question is whether the
concrete need exists.

**Effort.** The largest SDK-side effort of the three, and it lands here, not
upstream:

- a second transport beside curl (subprocess execution with the timeout
  contract the retired wrapper pioneered — `bf-jyj`'s `stream_select` +
  idle-bound design), plus transport selection in the constructor. The
  constructor-ambiguity problem ADR-1 rejected ("`Client` picks CLI or HTTP
  based on constructor args") returns;
- porting the six methods — onto **argv contracts that are largely
  unrepaired**. This is the load-bearing fact: the only existing subprocess
  implementation is the dead `src/Pdftract/` tree, whose argv construction
  for `extract`/`text`/`markdown`/`stream`/`metadata` and
  `verifyReceipt`/`classify`/`search` is the open bf-1s2 catalogue
  (`pdfphp-974457a7`, `pdfphp-61e9f890`, `pdfphp-9ee5e099`,
  `pdfphp-4f09b21b` — all open). Only `hash()` was repaired and pinned
  against the real binary (Addendum 2026-09-27b). So B is not "re-enable the
  old code"; it is "finish four open fix beads' worth of argv repair, then
  build a dual-transport client around it";
- docblocks, README usage (`pdfphp-dccc814f` currently assumes nine methods
  — under B that scope becomes real work), and a decision on how a caller
  discovers which methods work on which transport.

**Risk.** Highest of the three. (1) It reintroduces the exact deployment
constraint ADR-1 exists to remove — `proc_open` enabled and the binary
installed — for six methods, on shared/managed hosting where ADR-1 notes
that is routinely disabled. (2) Two transports must stay at behavioural
parity with two different upstream surfaces; the stream-route divergence
already pinned (in-band NDJSON error record vs buffered 422, Addendum
2026-09-27) is a preview of the per-method divergence catalogue a dual
transport accumulates. (3) The fallback's semantics base is the CLI output
shapes — the very shapes ADR-1 bullet 5 (as amended) forbids deriving models
from; `search` even has its own published CLI-output schema
(`grep-jsonl.schema.json`). (4) The "temporary" qualifier has no enforcement
mechanism: temporary transports in the absence of an upstream commitment
tend to become permanent ones.

**Migration/rollback impact.** Adding six methods is non-breaking today, but
the *exit* is the trap: when upstream catches up, removing the CLI fallback
is a breaking change (public methods change transport, availability, and
error vocabulary), on a library that by then may have consumers. B converts
today's cheap omissions into a future breaking removal — the inverse of the
pre-1.0 asymmetry that makes additions cheap now. Rollback of B itself
(mid-flight) means un-shipping advertised methods.

**Effect on bf-4gq's reconciliation children.**

- `pdfphp-92801363` — closed; unaffected.
- `pdfphp-decde78f` — proceeds, but its scope transforms: six methods on a
  second transport, the bf-1s2-class argv repairs pulled into scope, plus
  the unreferenced-stubs question already resolved by deletion
  (`codegen-stubs-resolution.md`) stays resolved.
- `pdfphp-6b9a3bdb` — **directly conflicted.** The models for the six
  methods would have to represent CLI-shaped output, creating two model
  dialects (serve-shaped for 3 methods, CLI-shaped for 6) inside one
  `src/Models/` tree — the fork bullet 5's amendment closed.
- `pdfphp-ce2bbc56` — **blocked or narrowed.** The only existing subprocess
  implementation is the tree slated for deletion. Either the deletion waits
  until the fallback has extracted what it needs, or B ships a new minimal
  subprocess transport and the deletion proceeds — the latter is more work,
  the former holds bf-4gq's tail hostage to the fallback.
- `pdfphp-9c33ad83` — verification scope doubles (both transports, clean
  clone).

**Conformance suite.** The two retired suites
(`tests/Retired/ClientBinaryConformanceTest.php`,
`ClientHashConformanceTest.php`) un-retire and rejoin the gated set under
`PDFTRACT_BIN` — the README keeps their pins "for whoever resurrects a
`hash()` surface after that" (README § suites; the hash pins are green
against real binaries, Addendum 2026-09-27b: INV-13 format, determinism,
structural collision twins, byte equality, failure domain, mutation-checked).
But the other five methods' wrapper contracts were never repaired — the
sweep harness would either pin the fictional contracts bf-1s2 catalogued or
wait on the fix beads. CI grows a CLI-binary build leg
(`scripts/build-conformance-binaries.sh` exists) and
`ci-assert-gated-ran.php` must cover the resurrected groups. The DoD's plain
run stays green either way (the Retired partition is excluded by default).

**Public Client surface / breaking posture.** Nine methods again — but on a
split-brain surface: three over HTTP everywhere, six only where a binary and
`proc_open` exist. Method availability becomes host-dependent, which is the
failure mode (silent unavailability) ADR-1's Context section describes for
the wrapper era. No breaking change today; a certain breaking change at
exit.

**Disposition of each uncovered method.** All six: **live on the CLI
fallback**, with per-method quality divergence: `hash` is the one method
with a repaired, mutation-checked, real-binary-pinned contract (2026-09-27b)
— arguably the method B serves best; `extractMarkdown` falls back to
`--md`/`--md-anchors` argv construction that is still in the unrepaired
bf-1s2 set (`974457a7`); `search` additionally requires the
feature-complete binary build (the default build has no grep — matrix row
5); `classify`/`verifyReceipt` fall back to subcommands whose wrapper argv
is unrepaired (`4f09b21b`, `9ee5e099`); `getMetadata` falls back to a
method whose CLI contract is itself unresolved ("reimplement getMetadata on
a metadata source the real pdftract CLI ships" — `61e9f890`, open: the CLI
has no dedicated metadata subcommand at all, matrix row 6).

## Option C — drop the six methods from pdftract-php's v1 HTTP-only surface until upstream catches up

**The rule:** v1 ships HTTP-only with the three covered methods, documented
as deliberate scope; the six are re-added when (and only when) a serve
transport for them exists. This ratifies what the committed tree already
ships — the live `Client` has exactly three methods and a docblock saying
the other six are deliberately absent (`src/Client.php:45-48`).

**Effort.** Near zero in code: the exclusion is already implemented and
documented. The work is editorial and bookkeeping: the ruling re-scopes
`pdfphp-decde78f` (from "nine methods" to "three methods + documented
exclusion"), notes `pdfphp-dccc814f`'s README scope the same way, and
`pdfphp-b3788e75` writes the per-method disposition into plan.md as the
ADR-1a amendment. No transport, no suites, no model dialects.

**Risk.** Lowest of the three, and honest about its one real cost: until
upstream adds a password-capable serve contract, an HTTP-only SDK cannot
process encrypted documents at all (Addendum 2026-09-27d) — and under C
there is no CLI escape hatch. The other costs are opportunity costs (no
`hash()` for callers who want it today, despite it being the one method
with a fully repaired CLI contract) — weighed against which: the SDK has no
known external consumers (plan.md, ADR-1 Consequences:
"pre-1.0/pre-Packagist-listing library with no known external consumers
yet"; install is the Forgejo `0.1.0` tag), so there is no demonstrated
demand being refused.

**Migration/rollback impact.** None now. The re-add path is monotone:
adding a method to a published surface is non-breaking, so C keeps every
other option reachable later — including B (a consumer need can resurrect
the fallback per ADR-1's revisit clause) and the getMetadata hybrid (D).
This is the decisive asymmetry of the whole decision: **under C, mistakes
are cheap to repair; under B, the exit is a breaking removal; under A,
completion is hostage to an undated external dependency.**

**Effect on bf-4gq's reconciliation children.**

- `pdfphp-92801363` — closed; unaffected.
- `pdfphp-decde78f` — re-scoped by the ruling to the three-method surface +
  documented exclusion; becomes closable without upstream dependency. This
  unblocks bf-4gq's chain.
- `pdfphp-6b9a3bdb` — proceeds unchanged in mechanics (25 classes under
  `src/Models/`, audited against serve shapes where serve shapes exist),
  with the ruling noting that the six methods' models (e.g. `Receipt`) are
  ported ahead of any live consumer of them — same standing consequence of
  the missing schema as under A, just explicit.
- `pdfphp-ce2bbc56` — proceeds unchanged; C removes the last reason to keep
  the dead subprocess tree.
- `pdfphp-9c33ad83` — proceeds unchanged; the verified end state is the
  three-method client, cleanly.

**Conformance suite.** Exactly the current state: default suite green with
no binary; `serve-parity` and `real-server` gated on `PDFTRACT_SERVE_BIN`;
the retired CLI suites stay retired as records; the parity matrix's forward
pins (failure-domain assertions standing for the next binary that cannot
extract, per Addendum 2026-09-27e's role flip) remain the guard. When
upstream later serves one of the six, the same suite architecture accepts
the new route's pins with no rework — C is also the cheapest *entry* into A
later.

**Public Client surface / breaking posture.** v1's documented contract
becomes "three methods, HTTP-only, exclusions deliberate" — which is what
the docblock already says. No breaking change now; all future changes are
additions (non-breaking).

**Disposition of each uncovered method.** All six: **out of v1, with
recorded re-add triggers per gap class** — `extractMarkdown`: when upstream
exposes a markdown representation (route or output selector); `hash`: when
a serve route returns the subcommand-grade page-tree fingerprint (not the
embedded extract fingerprint); `getMetadata`: when upstream adds a
lightweight route, or on explicit ruling, shipping on the embedded value
with its full-extraction cost documented (the D path); `search`: when
upstream adds a route — noting upstream must also decide to promote its own
feature-gated capability; `classify`, `verifyReceipt`: when upstream adds
routes. Each re-addition lands as a non-breaking minor release.

## Option D — the fourth option the matrix itself dangles: a per-method hybrid

The matrix's `getMetadata` gap detail ends: "An amendment should either
accept that cost or add a metadata route upstream." That is a different
decision shape from A/B/C — **dispose per method, by gap class, instead of
one rule for all six.** Its maximal form: ship `getMetadata()` now derived
from the `POST /extract` response (the only uncovered method whose value is
already on the wire), drop the other five until upstream serves them.

Considered, and not recommended, for three grounded reasons:

1. **No parity reference exists to pin it against.** The CLI has no
   metadata subcommand (matrix row 6: "no dedicated subcommand — metadata
   rides `extract --json` output"), so unlike every covered method there is
   no second surface to conformance-pin a derived `getMetadata()` against —
   the suite would pin the SDK to its own invention. That is precisely the
   fiction-contract failure mode bf-1s2 catalogued (the wrapper's argv
   contracts that fail against the real binary) and its fix children retired
   method by method.
2. **The cost is invisible to callers.** The method's contract would be
   "full extraction plus full upload, every call" with nothing in the
   signature saying so; and if upstream later adds a real lightweight
   metadata route, the SDK accumulates two `getMetadata()` semantics across
   versions.
3. **It spends the pre-1.0 option value.** With no known consumers, shipping
   one speculative method now buys nothing that adding it later (non-
   breaking) wouldn't.

Two sub-variants the matrix also dangles, rejected on its own words:

- **Client-side derivation** (a PHP markdown renderer over `POST /extract`
  blocks; a PHP page-tree fingerprint for `hash()`): the matrix forecloses
  both — the hash addendum names the PHP fingerprint reimplementation
  "out of scope for a client whose ADR-1 charter is transport, not
  crypto/renderer, reimplementation", and the markdown verdict shows the
  representation lives in upstream's renderer
  (`page_to_markdown_with_links_and_footnotes`, `main.rs:1433`): a PHP
  port would be a private
  reimplementation that diverges the moment upstream evolves it. The
  content is identical (2026-11-25); the *contract* — the representation —
  is not reproducible without upstream.
- **Targeting the MCP tool registry as a transport**: the SDK's method
  taxonomy matches upstream's MCP tools (`ExtractArgs` … `ClassifyArgs`,
  `registry.rs:554-1079`) — but the matrix's ancillary note already rules
  it: a stdio JSON-RPC surface feature-gated behind `mcp = []`, "not a
  transport ADR-1 can target", with no `VerifyReceipt` tool even there, and
  the same spawned-process deployment constraints ADR-1 removes.

**When D becomes right:** if a concrete consumer needs `getMetadata`
specifically, before upstream ships a metadata route, and accepts the
documented full-extraction cost. Even then it lands as a non-breaking
addition under C — adopting C does not foreclose D; adopting D forecloses
nothing C keeps, but buys a pin-without-reference method and sets the
precedent that scope is decided per-method by demand rather than by the
transport's actual reach.

## Recommendation

**Recommend option C** — ratify the three-method HTTP-only v1 surface, with
the six exclusions documented per the disposition table above, and each
re-add trigger recorded so the exclusion reads as sequencing, not refusal.

Rationale, grounded:

1. **It is the only option under which bf-4gq completes on work this repo
   controls.** A holds the migration's tail hostage to undated upstream
   routes; B trades that for the largest in-repo effort plus a certain
   future breaking change. C lets `decde78f` → `ce2bbc56` → `9c33ad83` run
   to completion now.
2. **It ratifies the shipped reality with zero implementation risk.** The
   live client already has three methods and the exclusion docblock; C
   makes the plan match the code instead of making the code chase the plan.
3. **The pre-1.0 asymmetry decides it.** With no known external consumers,
   omissions are free to repair later and promises are expensive to retract.
   C is the only option whose every future change is an addition. B's exit
   is a breaking removal; A's completion has no date.
4. **The evidence base already leans here.** bf-4bd's settled sub-questions
   record extractMarkdown as "a hard GAP … under option (c) it belongs with
   the dropped-from-v1 methods" and hash as "cannot be served over HTTP
   today"; the markdown verdict's consequence line names an upstream
   markdown route as "the prerequisite for ever closing this row as
   COVERED". The matrix's own per-row consequences are C-shaped.
5. **The suite stays honest.** Every live pin maps to a real wire contract;
   nothing gets pinned against the partially-fictional wrapper-contract
   base (bf-1s2's unrepaired rows) or against a derived method with no
   reference surface (D's problem).

**What would have to be true for each non-recommended option to become
right:**

- **A** becomes right when the operator owns — or can extract — a dated
  upstream commitment to the missing serve surface: routes for
  `hash` (subcommand-grade fingerprint), `classify`, `verifyReceipt`,
  `search` (including the promote-the-feature-gate decision), a markdown
  representation or output selector, and a metadata route or an accepted
  embedded-cost ruling; plus the audit-basis decision the OpenAPI finding
  forces (an upstream OpenAPI surface, or explicit continuation of the
  empirical-body audit `pdfphp-087173a2` performs). Upstream has
  demonstrably responded to this repo's findings before (the ConnectInfo
  fix; the parser work that made all twelve fixtures extract), so A is not
  hypothetical — it is just undated. If such a roadmap is agreed, C
  converts into A by dating the prerequisite; no code changes.
- **B** becomes right when a concrete consumer demonstrates a need for the
  six methods on hosts where the binary and `proc_open` exist and cannot
  wait for upstream — ADR-1's own revisit clause, with the offline/
  air-gapped case it named. The sharpest live trigger is the password path:
  serve cannot honour encrypted documents at all (Addendum 2026-09-27d), so
  an encrypted-PDF workload on the SDK is a need no amount of upstream
  route-*addition* in bf-4bd's current list fixes by itself. Adopting B
  means accepting: the bf-1s2-class argv repairs pulled into scope, a
  dual-transport test matrix, un-retiring the two CLI suites and a CI
  build leg, two model dialects (or CLI-shaped models — which bullet 5's
  amendment forbids), and holding or narrowing `ce2bbc56`'s deletion.
- **D** becomes right when a consumer needs `getMetadata` specifically,
  before upstream ships a metadata route, and accepts the documented
  full-extraction cost — in which case it should land as a non-breaking
  addition on top of C, not as the v1 ruling.

## Decision

*(Empty pending the operator's ruling. `pdfphp-ddcdd874` records the ruling
verbatim here — chosen option, conditions, sequencing constraints, deferral,
or a counter-proposal outside this memo's menu — and in its own bead notes.
`pdfphp-b3788e75` then applies it to ADR-1 in `docs/plan/plan.md` and hands
the scope constraint to bf-4gq.)*
