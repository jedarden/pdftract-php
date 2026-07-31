# bf-64f — Packagist publication vs. install instructions

## What was verified (2026-07-30)

- `https://repo.packagist.org/p2/jedarden/pdftract.json` → **404**. The package
  is not registered on Packagist, so the README's
  `composer require jedarden/pdftract` fails for any external user with a stock
  Composer config.
- `git tag -l` → empty. There is **no tagged release**, stable or otherwise.
- `https://git.ardenone.com/jedarden/pdftract-php` → **303** (redirect to
  login); anonymous `info/refs?service=git-upload-pack` → **401**. The canonical
  repository is **private**.

## What was done

Took the interim option from the bead: rewrote the README Installation section
to the VCS-repository incantation that works today, including the
`dev-main` constraint (no tags exist), the `minimum-stability` implication, the
`composer config --global --auth http-basic.git.ardenone.com` step the private
remote requires, and a `path` repository fallback for a local checkout.

## Why publishing was not done here

Publishing to Packagist could not be completed from this workspace. It needs:

1. **A publicly readable repository.** Packagist's crawler fetches
   `composer.json` and tags anonymously; it cannot authenticate to
   git.ardenone.com. This means either making the Gitea repo public or
   mirroring to a public host (e.g. GitHub `jedarden/pdftract-php`) and
   submitting that URL.
2. **A stable tagged release.** Packagist accepts a repo with only branches,
   but the package would resolve solely as `dev-main` — no better than the
   current instructions. Per the bead, ADR-1 (HTTP-client transport migration)
   and the conformance suite (bf-1o3, landed) should be in before a `v1.0.0`
   tag is worth publishing.
3. **Packagist account credentials** for jedarden, to submit the package and
   wire the update webhook. Not available to an automated agent.

## Follow-up for a human

1. Land ADR-1 and confirm the conformance suite passes.
2. Make the repo publicly readable (or push a public mirror).
3. `git tag -a v1.0.0 && git push --tags`.
4. Submit the public URL at https://packagist.org/packages/submit and add the
   Gitea/GitHub webhook so tags auto-sync.
5. Revert the README Installation section to
   `composer require jedarden/pdftract`.
