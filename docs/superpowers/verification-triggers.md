# Plan 2 (triggers) live verification

Verified on nara.local (Local by Flywheel), PHP 8.2.30, against the public test repo
`kamalahmed/licensekit` deployed into a throwaway slug `deployward-test`. All test
artifacts (deployment, plugin dir, backups, temp admin user) were removed afterwards;
the plugin was left active.

## Webhook (POST /deployward/v1/webhook/{id})
- Push to the watched branch with a valid `X-Hub-Signature-256` HMAC: HTTP 202
  `{message:queued, sha:...}`, the background deploy ran, files landed, audit row
  `success | webhook | Deployed <sha>`.
- Wrong signature: HTTP 401 `{error:Invalid signature}`, no deploy.
- `X-GitHub-Event: ping`: HTTP 200 `{message:pong}`, no deploy.
- Push to a non-watched branch: HTTP 200 `{message:branch ignored}`, no deploy.

## Cron poll (deployward_poll, every 5 minutes)
- After the activation fix, `wp_next_scheduled('deployward_poll')` is true.
- With a changed/empty last-deployed SHA, the poll detected the new commit and
  deployed: audit row `success | cron | Deployed <sha>`.
- The skip guard works: a second trigger for an already-deployed SHA records
  `skipped | ... | Already at latest commit` instead of redeploying.

## Admin UI
- The per-deployment "Webhook setup" panel shows the Payload URL
  (`.../deployward/v1/webhook/{id}`), the Secret, Copy buttons, and the fixed GitHub
  settings (Content type: application/json, Event: Just the push event). The secret is
  fetched only when the panel is opened. Getting Started now documents the webhook steps.

## Bugs found and fixed during this verification
- The poll was never scheduled: `wp_schedule_event` with the custom `deployward_5min`
  interval failed during activation because the plugin's `cron_schedules` filter was not
  yet registered. Fixed by registering the interval before scheduling and self-healing on
  boot (`Plugin::ensureScheduled`).

## Known follow-up
- No per-deployment deploy lock: concurrent triggers (for example a webhook arriving
  while the poll fires) for the same deployment can run two deploys of the same commit.
  Harmless today (identical code, atomic swap plus backup), but a lock/dedupe is a
  hardening follow-up. See FOLLOWUPS.md.
