# Deployward Design Spec

- Date: 2026-06-29
- Author: Kamal Ahmed
- Status: Approved (brainstorming), pending implementation plan

## 1. Summary

Deployward is a standalone, distributable WordPress plugin that safely auto-deploys
selected plugins and themes from GitHub repositories (public or private) into a
WordPress site, with no host-level git integration required. It is installed
per-site and acts as a deploy agent: each site pulls its own code on push to a
watched branch. The first real consumer is the `nara-core` plugin, deployed to
WP Engine staging and live.

The product motto is safety and ease of use. The design follows the industry
standard "safe deploy" pattern: download, validate, atomic swap, health check,
auto-rollback, audit log.

## 2. Goals

- Deploy a chosen set of plugins/themes from GitHub into WordPress automatically
  on push to a watched branch.
- Work on managed hosts (WP Engine) that lack a usable `git` binary, SSH from PHP,
  or native git deployment.
- Be safe by default: never leave a site in a broken or half-written state.
- Be easy: an admin can add a deployment in a short wizard and see exactly what to
  paste into GitHub.
- Be self-contained: no central server or external service is required to operate.

## 3. Non-goals (v1)

- Build / release-artifact mode (running composer/npm and deploying a built `.zip`
  attached to a GitHub Release). Designed for, but deferred to a later version.
- Providers other than GitHub (GitLab, Bitbucket).
- Multisite (network) deployment.
- A central orchestrator that pushes code out to multiple remote sites.
- Slack/Discord notifications (email only in v1).

## 4. Core model: per-site agent

Deployward is installed on each environment (WP Engine staging, WP Engine live,
local). Each install owns its own set of Deployments. Each site pulls its own code;
no site holds credentials to any other site.

A Deployment is the central configuration unit:

| Field | Meaning |
|-------|---------|
| `id` | Internal unique id (used in the webhook URL) |
| `repo` | GitHub `owner/repo` |
| `branch` | Watched branch (for example `main` on live, `develop` on staging) |
| `visibility` | `public` or `private` |
| `target_type` | `plugin`, `theme`, or `mu-plugin` |
| `target_slug` | Destination directory under the target type's root |
| `token_ref` | Reference to the stored GitHub token (encrypted) |
| `webhook_secret` | Per-deployment HMAC secret for webhook verification |
| `last_deployed_sha` | Last successfully deployed commit SHA |

"Staging vs live" is expressed by each site's Deployment watching the branch (later:
release tag) appropriate for that environment.

## 5. Trigger paths

All three are supported in v1.

1. Webhook (instant). GitHub POSTs to REST route
   `deployward/v1/webhook/{deployment_id}`. The handler verifies the
   `X-Hub-Signature-256` HMAC against the Deployment's stored secret, confirms the
   event is a push to the watched branch, responds 202 immediately, and queues the
   deploy to run in the background. Responding fast avoids GitHub webhook timeouts.

2. WP-Cron polling (fallback). On a schedule (default every 5 minutes), compare the
   watched branch's latest commit SHA (GitHub API) to `last_deployed_sha`; deploy if
   changed. Covers hosts where inbound webhooks are blocked or not yet configured.

3. Manual "Deploy now" button in the admin UI, for first deploys, re-deploys, and
   forced rollbacks.

### Background execution

Deploys run asynchronously (download, extract, swap, health check can take time).
On webhook receipt the plugin schedules a single WP-Cron event and triggers a
non-blocking loopback to spawn cron immediately, so the deploy starts without
blocking the 202 response.

## 6. Fetch and deploy pipeline (safe swap)

1. Resolve the target commit SHA from the GitHub API.
2. Download the repository zipball over HTTPS via the WP HTTP API
   (`Authorization: Bearer <token>` for private repos), streamed to a temp file.
3. Extract with WordPress core `unzip_file()` into a temp directory, then flatten
   GitHub's `owner-repo-<sha>/` wrapper directory.
4. Validate the payload is the expected target: correct plugin/theme header and
   matching slug. Refuse the deploy on mismatch or empty payload.
5. Enter maintenance mode.
6. Atomic swap: rename the current target directory to a versioned backup, then move
   the new directory into place. The live code is never half-overwritten.
7. Exit maintenance mode.
8. Post-deploy health check: loopback request to the front page plus a light admin
   probe. On HTTP 500 or a detected fatal, auto-rollback by restoring the backup.
9. Record the result (SHA, trigger source, status, errors) in the audit log and send
   an email notification.

### Why maintenance mode is exited before the health check (step 7 before step 8)

WordPress's `.maintenance` file makes the site return HTTP 503 to every request,
including the loopback health check. If maintenance stayed enabled during the health
check, the checker would always see a 503 (treated as unhealthy) and roll back every
deploy. Maintenance is therefore disabled immediately after the atomic swap, before
the health check runs. The cost is a brief exposure window: between the swap and the
end of the health-check-plus-rollback sequence (sub-second to a few seconds), live
traffic can hit newly deployed code that the health check may then roll back. This is
inherent to in-place deploys; the future build/release-artifact mode (and a possible
symlink-style atomic switch) can shrink or remove this window. The maintenance window
around the swap itself is guaranteed to close via a `finally`, so an unexpected error
during the swap can never leave the site stuck in maintenance.

## 7. Authentication and secrets

- GitHub fine-grained Personal Access Token (read-only Contents, scoped to the listed
  repos), entered once per site. Public repos need no token.
- Mechanism: download the zipball over HTTPS via the GitHub API. SSH deploy keys and
  `git clone` are explicitly out, because managed hosts typically lack a `git` binary
  and block SSH from PHP.
- Token encrypted at rest in `wp_options` (`autoload=no`) using a key derived from the
  site's `wp-config` salts (libsodium preferred, OpenSSL fallback). The unavoidable
  ceiling: an attacker with both DB and filesystem access can decrypt, which is the
  standard limit on shared WordPress hosting. The token is never stored in plaintext
  and never appears in a DB export.
- Optional override: read the token from a `wp-config.php` constant for power users.
- Webhook authenticity via HMAC. Admin actions gated by the `manage_options`
  capability plus nonces on every form and AJAX handler.

## 8. Storage and data

- Config: one `wp_options` entry holding the encrypted token(s) and the list of
  Deployments.
- Audit log: a custom table `wp_deployward_log` with paginated history. Never returns
  an unbounded list.
- Backups: the last 3 versions per target, stored in an unguessable, protected
  subdirectory under `uploads` (`index.php` plus `.htaccess` deny). On hosts where a
  location outside the web root is writable, prefer that.
- Self-protection: Deployward refuses to deploy or overwrite itself.

## 9. Admin UX

- A single "Deployward" admin menu listing Deployments with status (last SHA, last
  deploy time, health), each row offering Deploy now, Rollback, and View log.
- An "Add Deployment" wizard: paste the repo URL, pick a branch (auto-fetched),
  choose the target type and slug, paste or generate the token and webhook secret,
  and the wizard displays the exact webhook URL to paste into GitHub.
- All errors surfaced inline with friendly messages. No silent failures.

## 10. Quality and engineering constraints

- Plugin structure: focused classes with a simple PSR-4 autoloader. Files under 400
  lines, functions under 50 lines, immutable data handling (build new arrays/objects,
  no mutation of shared state).
- Security: sanitize all input at the boundary (`sanitize_text_field`,
  `sanitize_email`, `absint`, `wp_kses_post`); escape all output at the echo point
  (`esc_html`, `esc_attr`, `esc_url`); all DB access through `$wpdb->prepare()`; nonce
  plus capability checks on every state-changing handler.
- Testing: TDD with PHPUnit. Brain Monkey for unit isolation; wp-env or wp-phpunit for
  integration coverage of the swap, webhook, and health-check flows. Target at least
  80% coverage on new code.
- Compatibility: PHP 7.4 or newer floor (host variability). WordPress 6.0 or newer.
- No em-dashes in code, commits, or docs.

## 11. Where it lives

New repository at `wp-content/plugins/deployward/`, fully separate from nara-core.
This spec is committed inside the Deployward repository. No GitHub remote is created
until the user explicitly asks.

## 12. Acceptance criteria (v1)

- An admin can add a Deployment for a public or private GitHub repo and see the exact
  webhook URL to configure in GitHub.
- A push to the watched branch triggers a deploy via webhook, with a valid signature
  required (invalid signatures are rejected).
- If webhooks are unavailable, WP-Cron polling deploys the new commit within the poll
  interval.
- A failed or fatal deploy auto-rolls-back to the previous version, and the site
  remains usable.
- Manual Deploy now and Rollback both work from the admin UI.
- The last 3 versions are retained per target and a rollback restores cleanly.
- Every deploy attempt is recorded in the audit log with SHA, trigger, result, and any
  error.
- Secrets are never stored in plaintext and never appear in a DB export.
- Deployward refuses to deploy itself.
- New code ships with PHPUnit tests at 80% coverage or higher.

## 13. Future (post-v1)

- Build / release-artifact source mode (GitHub Actions builds and attaches a `.zip`
  to a Release; Deployward deploys that asset).
- Additional providers: GitLab, Bitbucket.
- Slack/Discord/webhook notifications.
- Multisite support.
- Optional GitHub App tier for a future hosted SaaS variant.
