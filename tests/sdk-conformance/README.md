# Vendored SDK conformance suite

This directory is a **vendored copy** of the shared `pdftract` SDK conformance
suite (`tests/sdk-conformance/` in the upstream `pdftract` repository). It is
checked into `pdftract-php` so the conformance tests can run **standalone** —
i.e. without this repo being a subdirectory of a `pdftract` monorepo checkout.

Before this was vendored, `tests/ConformanceTest.php` loaded its cases from
`__DIR__ . '/../../../../tests/sdk-conformance/cases.json'`, a path that only
resolved inside the monorepo. In the standalone repo that directory does not
exist, so `setUp()` failed immediately and every test errored before running
(see `docs/plan/plan.md`, "Known issues", and bead `bf-1o3`).

## Contents

- `cases.json` — the conformance case definitions (method, options, expected
  results, tolerances) for all 9 SDK contract methods.
- `schema.json`, `report-schema.json` — JSON schemas for the extraction result
  and the conformance report.
- `fixtures/` — the input PDFs (and receipt JSON) referenced by `cases.json`.
  Only the files actually referenced by a case are vendored; the upstream
  fixture-generator scripts are intentionally left out.

## Updating

Re-vendor from the upstream `pdftract` checkout when the shared suite changes:

```sh
# from the pdftract-php repo root, with $PDFTRACT pointing at the upstream repo
cp "$PDFTRACT/tests/sdk-conformance/cases.json" tests/sdk-conformance/
cp "$PDFTRACT/tests/sdk-conformance/"{schema,report-schema}.json tests/sdk-conformance/
# then copy each fixture referenced by cases.json into tests/sdk-conformance/fixtures/
```

## Scope of `ConformanceTest.php`

The vendored suite lets the PHP test validate, standalone, that every case is
well-formed and its fixtures are present and readable. Executing each case
against a live `pdftract` transport (the actual method-behavior conformance) is
deferred to the HTTP-client migration tracked in `docs/plan/plan.md` (ADR-1),
since it requires a running `pdftract --serve` endpoint.
