# Codegen stubs — resolution record (bead pdfphp-decde78f)

- **Date:** 2026-09-23
- **Decision:** `src/Codegen/Methods.php` and `src/Codegen/Errors.php` were
  **deleted**, not implemented.
- **Requirement driving this:** bead `pdfphp-decde78f` — "nothing may remain
  as an unreferenced TODO stub", with the choice recorded here.

## Why deletion, not implementation

The bead offered two resolutions: implement the stubs "against the serve
OpenAPI schema per ADR-1", or delete them as redundant with `Client`. The
first branch is void on the evidence, and the second branch holds for both
files:

1. **There is no serve OpenAPI schema to implement against.** ADR-1's
   Decision bullet 5 commits to regenerating/auditing the generated tree
   "against the actual HTTP OpenAPI schema `pdftract --serve` exposes", but
   `docs/notes/serve-parity-gap.md` (§ "OpenAPI finding") established — at
   the pinned upstream revision `eeab77e`, which is still upstream's HEAD as
   of 2026-09-23 — that no such schema exists: the serve API is a
   hand-routed axum `Router` with ad-hoc JSON bodies, no OpenAPI-generating
   crate is attached to it, and nothing matching `*openapi*`/`*swagger*`
   exists in the upstream tree. Implementing `Methods` "against the serve
   OpenAPI schema" would mean inventing the schema first — fabrication, not
   generation.

2. **`Methods` is redundant with `Client`.** The canonical HTTP client
   (`src/Client.php`) is the landed ADR-1 surface (bead
   `pdfphp-e0998515`): one class, one shared request-building/error-handling
   path, the timeout contract documented in its class docblock. A second
   `Codegen\Methods` class holding a parallel method surface would fork
   transport behaviour the first time either changed.

3. **`Errors` is redundant with the ported exception hierarchy.** The full
   exception tree now lives at the `src/` root (`PdftractException` plus the
   status-mapped subclasses; commit `d0b9b59`, bead `pdfphp-92801363`), and
   `Client::exceptionClassForStatus()` is the single place a response status
   is mapped to one of them. `Errors.php` held no code at all — only its
   TODO — and a parallel `Codegen` error tree would create a second, competing
   identity for the same failure modes.

Neither file was referenced anywhere in `src/`, `tests/`, `composer.json`,
or `README.md` at the time of deletion; both were unreferenced TODO stubs
carried over from the abandoned 2026-05 working-tree WIP.

## Related, not decided here

The same evidence base (`serve-parity-gap.md`, open OPS-GATED bead
`bf-4bd`) is what gates the *method-surface* half of `pdfphp-decde78f` —
whether `extractMarkdown`, `search`, `getMetadata`, `hash`, `classify`, and
`verifyReceipt` join `Client`'s HTTP surface, and on what semantics. That is
a human decision recorded on `bf-4bd`, not an engineering follow-through,
and this file deliberately does not preempt it. (`serve-parity-gap.md`
carries a 2026-09-23 addendum with new fingerprint evidence bearing on the
`hash` row of that decision.)
