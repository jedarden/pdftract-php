# The CI trigger chain — Forgejo push to pdftract-php-ci workflow

- **Date:** 2026-11-24
- **Status:** repaired and live (bead `pdfphp-715ca6c3`). This note records
  the full delivery chain for the SDK's CI and the one link that was missing
  from it for the template's entire life.

## The chain

```
git push (jedarden/pdftract-php, any refs/heads/*)
  → Forgejo webhook id 39 on the repo
      url    https://webhooks-ci.ardenone.com/pdftract-php
      events push
  → EventSource forgejo-webhooks, route /pdftract-php (port 12000)
      declarative-config: k8s/iad-ci/argo-events/forgejo-eventsource.yml
  → NATS subject default.forgejo-webhooks.pdftract-php
  → Sensor pdftract-php-ci-sensor
      filters header X-Forgejo-Event == push, body.ref matches ^refs/heads/
      declarative-config: k8s/iad-ci/argo-events/pdftract-php-ci-sensor.yml
  → Workflow (generateName pdftract-php-ci-) in namespace argo-workflows
      spec.workflowTemplateRef: pdftract-php-ci
      declarative-config: k8s/iad-ci/argo-workflows/pdftract-php-ci.yaml
```

The template's own header documents the sensor half of this chain; what it
could not document is that the Forgejo half did not exist.

## The gap: the repo never had the webhook

The template (Aug 2026), the eventsource route, and the sensor have been in
place and healthy since late August, but the Forgejo-side webhook on
`jedarden/pdftract-php` was never created — `GET /api/v1/repos/jedarden/
pdftract-php/hooks` returned `[]` until 2026-11-24. The repo was created by
push-to-create, which provisions no webhook, and no later step added one.
Sibling repos that CI actually fires for (jedarden/pdftract hook 15 →
`/pdftract`, jedarden/declarative-config hook 9 → `/declarative-config`,
jedarden/miroir hook 25 → `/miroir`) each carry their own repo-level
webhook; this repo's absence was an omission, not a deliberate removal.

Consequence: **push-triggered CI has never run for this repo.** Every green
`pdftract-php-ci` run on record — `pdftract-php-ci-manual-9znq9`,
`-nx7f7`, `-h4lbc` (2026-09-28/29) — was a manual `kubectl create` submit.
A broken suite landing on main would have sat there undetected; nothing
failed loudly because nothing ever ran.

## The repair

Webhook id 39 created on 2026-11-24 via the Forgejo API (the same token
from the git credential store that CLAUDE.md documents for repo admin
calls):

```bash
curl -X POST https://git.ardenone.com/api/v1/repos/jedarden/pdftract-php/hooks \
  -H "Authorization: token $FORGEJO_TOKEN" -H "Content-Type: application/json" \
  -d '{"type":"forgejo","active":true,"branch_filter":"*","events":["push"],
       "config":{"url":"https://webhooks-ci.ardenone.com/pdftract-php",
                 "content_type":"json","secret":"<forgejo token>"}}'
```

The webhook secret drives Forgejo's HMAC delivery signature, and the
signature is enforced: the live `/pdftract-php` route carries an
`authSecret` (`forgejo-webhook-secret`, key `webhook-secret`) just like
the sibling `/pdftract` route, so a webhook whose `secret` does not match
that key gets its deliveries rejected. Webhook 39 was created with the
same forgejo token the working sibling webhooks use, so the values are
expected to match; the first delivery's response code proves it. Any
delivery's arrival is visible as a `dispatching event on route's dispatch
channel...` line with `endpoint":"/pdftract-php"` in the
`forgejo-webhooks-eventsource` pod log; the sensor's subscription is the
NATS durable `default.forgejo-webhooks.pdftract-php`.

## Manual submission, for when a run is needed without a push

The template header's manual-run recipe is `kubectl create` against iad-ci.
State of that path as of 2026-11-24, for the next agent who reaches for it:

- `/home/coding/.kube/iad-ci.kubeconfig` (token issued ~2026-09-08)
  authenticates **no longer** — the API server answers every request with
  401 "the server has asked for the client to provide credentials". It is
  the same bounded-lifetime `argocd-manager` token that has expired here
  before (CLAUDE.md records a 2026-09-05 outage, re-issued by 2026-09-08).
- The read-only proxy (`traefik-iad-ci:8001`) cannot create workflows, and
  its RBAC denies secret reads, so it cannot hand over a fresh token either.
- The pinniped kubeconfigs under `~/.kube/pinniped/` (iad-ci.yaml et al,
  Oct 2026) mint credentials through the concierge, but their
  `browser_authcode` flow needs an interactive browser login this box
  cannot do headlessly; there is no cached OIDC session under
  `~/.cache/pinniped/`.

So from this box the sanctioned write path for a manual run is blocked
until the operator re-issues the `argocd-manager` token (re-confirmed
2026-11-25: the kubeconfig still answers 401, and no replacement
credential for `argocd-manager` on iad-ci exists in `~/.kube/` — the
rs-manager kubeconfig CLAUDE.md documents is no longer on disk either).
Push-triggered delivery — the template's designed trigger — is the path
that works, and as of the repair it is wired end to end.
