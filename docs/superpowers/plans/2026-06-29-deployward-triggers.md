# Deployward Triggers (Plan 2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add automatic "deploy on push to main": a public, HMAC-authenticated GitHub webhook endpoint that runs the deploy in the background, plus a WP-Cron polling fallback for hosts where inbound webhooks are not configured, plus the admin UI to copy the webhook URL and secret into GitHub.

**Architecture:** GitHub POSTs a push event to `deployward/v1/webhook/{id}`; the handler verifies the `X-Hub-Signature-256` HMAC against that deployment's stored secret, confirms the push targets the watched branch, schedules the deploy as a single WP-Cron event, immediately spawns cron via a non-blocking loopback, and returns 202. A recurring WP-Cron poll (every 5 minutes) compares each watched branch's latest commit SHA to the last deployed SHA and queues a deploy when it changed. The existing manual "Deploy now" stays synchronous; only webhook and cron run in the background. Everything reuses the existing engine through `Container`, `Deployer`, `DeploymentRepository`, and `GitHubClient`.

**Tech Stack:** PHP 7.4, WordPress REST API + WP-Cron, libsodium-derived secrets (existing), `hash_hmac`/`hash_equals` for signature verification, vanilla ES6 + CSS for the UI additions (no build), PHPUnit 10 + Brain Monkey + Mockery.

## Global Constraints

Apply to every task. Copied from the spec and the locked Plan 2 decisions.

- PHP 7.4 floor. NO PHP 8.0+ syntax (no constructor property promotion, no union/`mixed` type declarations, no `match`, no enums, no named args, no nullsafe `?->`). `/** @var */` docblocks are fine.
- WordPress 6.0+. Files under 400 lines, functions under 50 lines, nesting under 4.
- Immutability: build new arrays/objects; never mutate shared state.
- Security: the webhook route is PUBLIC (`permission_callback` returns true) because GitHub cannot authenticate as a WP user; it is authenticated INSTEAD by verifying the `X-Hub-Signature-256` HMAC against the deployment's `webhook_secret` using a timing-safe `hash_equals`. An invalid or missing signature returns 401 and never triggers a deploy. All OTHER new routes keep `current_user_can('manage_options')`.
- The webhook secret may be returned ONLY by the admin-only webhook-info endpoint (so the logged-in admin can paste it into GitHub). The GitHub token is still NEVER returned by any endpoint. The list endpoint (`present()`) stays free of both token and secret.
- Sanitize all input at the boundary; escape all output; `$wpdb->prepare()` for SQL. The webhook handler reads the RAW request body (needed for HMAC) and must not trust the parsed payload until the signature is verified.
- No build step, no npm, no framework for the UI additions.
- Reuse the engine via the existing interfaces and `Container`. Do not duplicate deploy logic. Manual deploy stays synchronous (unchanged); background execution is only for webhook + cron.
- No em-dashes anywhere. TDD for all PHP, 80%+ on new PHP. JS/CSS verified in-browser (Task 9), no JS unit harness by design.
- Test runner: Local PHP 8.2.30 binary, PHPUnit 10:
  `PHP="/Users/kamalahmed-nara/Library/Application Support/Local/lightning-services/php-8.2.30+1/bin/darwin-arm64/bin/php"; "$PHP" vendor/bin/phpunit`
- Mockery-using test classes MUST `use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;`.

## Existing interfaces this plan consumes (already on `main`)

- `Deployward\Container`: `repository(): DeploymentRepositoryInterface`, `deployer(): DeployerInterface`, `github(): GitHubClientInterface`, `log(): DeployLogInterface`.
- `Deployward\Config\DeploymentRepositoryInterface`: `all(): array` (Deployment[] keyed by id), `find(string $id): ?Deployment`.
- `Deployward\Config\Deployment`: getters `id()`, `repo()`, `branch()`, `visibility()`, `token()`, `webhookSecret()`, `lastDeployedSha()`.
- `Deployward\Deploy\DeployerInterface`: `deploy(Deployment $d, string $trigger, bool $force = false): Result`.
- `Deployward\GitHub\GitHubClientInterface`: `resolveSha(string $repo, string $ref, ?string $token): Result` (data = sha on success).
- `Deployward\Http\ApiResponse`: `ok($data = array(), int $status = 200)`, `error(string $message, int $status = 400, array $extra = array())`, `status()`, `data()`.
- `Deployward\Http\RestController`: existing CRUD + deploy/rollback/log/branches handlers; `present()` is token-and-secret-free.
- `Deployward\Http\RestRoutes`: `__construct(RestController $controller)`, `register(): void`, `static canManage(): bool`, `respond(ApiResponse): \WP_REST_Response`.
- `Deployward\Plugin`: `boot()`, `activate()`, `makeCommand()`.

## File Structure

- Create `src/Security/SignatureVerifier.php` — timing-safe HMAC verification of `X-Hub-Signature-256`.
- Create `src/Deploy/DeployScheduler.php` — schedule a background deploy (single cron event + spawn loopback) and the cron callback that runs it.
- Create `src/Http/WebhookController.php` — verify + parse the webhook and queue the deploy; returns `ApiResponse`.
- Create `src/Cron/CronPoller.php` — recurring poll that queues deploys for changed SHAs.
- Modify `src/Http/RestController.php` — add `webhookInfo(string $id): ApiResponse` (admin-only secret retrieval).
- Modify `src/Http/RestRoutes.php` — register the public webhook route + the admin webhook-info route; take a `WebhookController`.
- Modify `src/Plugin.php` — construct the new pieces from `Container`; register the cron hooks, the 5-minute schedule, the run-deploy action, and the webhook route; schedule the poll on activate and clear all Deployward cron events on deactivate.
- Modify `deployward.php` — register the deactivation hook.
- Modify `assets/admin.js`, `assets/admin.css` — per-deployment "Webhook setup" panel (fetch the secret, build the URL, copy) and update the Getting Started tab with the real webhook steps.
- Tests: `tests/Unit/Security/SignatureVerifierTest.php`, `tests/Unit/Deploy/DeploySchedulerTest.php`, `tests/Unit/Http/WebhookControllerTest.php`, `tests/Unit/Cron/CronPollerTest.php`, additions to `tests/Unit/Http/RestControllerTest.php` and `tests/Unit/Http/RestRoutesTest.php`.

---

### Task 1: SignatureVerifier

**Files:**
- Create: `src/Security/SignatureVerifier.php`
- Test: `tests/Unit/Security/SignatureVerifierTest.php`

**Interfaces:**
- Produces: `Deployward\Security\SignatureVerifier` with `verify(string $payload, ?string $signatureHeader, string $secret): bool`. The header format is `sha256=<hex>`. Returns true only when the header is present and equals `'sha256=' . hash_hmac('sha256', $payload, $secret)` under a timing-safe comparison.

- [ ] **Step 1: Write the failing test `tests/Unit/Security/SignatureVerifierTest.php`**

```php
<?php

namespace Deployward\Tests\Unit\Security;

use Deployward\Security\SignatureVerifier;
use PHPUnit\Framework\TestCase;

final class SignatureVerifierTest extends TestCase
{
    private function sign(string $payload, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }

    public function test_accepts_a_valid_signature(): void
    {
        $payload = '{"ref":"refs/heads/main"}';
        $secret = 'whsec_example';
        $verifier = new SignatureVerifier();

        $this->assertTrue($verifier->verify($payload, $this->sign($payload, $secret), $secret));
    }

    public function test_rejects_a_tampered_payload(): void
    {
        $secret = 'whsec_example';
        $sig = $this->sign('{"ref":"refs/heads/main"}', $secret);
        $verifier = new SignatureVerifier();

        $this->assertFalse($verifier->verify('{"ref":"refs/heads/evil"}', $sig, $secret));
    }

    public function test_rejects_a_missing_or_malformed_header(): void
    {
        $verifier = new SignatureVerifier();

        $this->assertFalse($verifier->verify('{}', null, 'secret'));
        $this->assertFalse($verifier->verify('{}', '', 'secret'));
        $this->assertFalse($verifier->verify('{}', 'deadbeef', 'secret'));
    }

    public function test_rejects_wrong_secret(): void
    {
        $payload = '{"ref":"refs/heads/main"}';
        $verifier = new SignatureVerifier();

        $this->assertFalse($verifier->verify($payload, $this->sign($payload, 'right'), 'wrong'));
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `"$PHP" vendor/bin/phpunit --filter SignatureVerifierTest` (class not found).

- [ ] **Step 3: Implement `src/Security/SignatureVerifier.php`**

```php
<?php

namespace Deployward\Security;

final class SignatureVerifier
{
    public function verify(string $payload, ?string $signatureHeader, string $secret): bool
    {
        if ($signatureHeader === null || strpos($signatureHeader, 'sha256=') !== 0) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signatureHeader);
    }
}
```

- [ ] **Step 4: Run to verify it passes**, then **Step 5: Commit**

```bash
git add src/Security/SignatureVerifier.php tests/Unit/Security/SignatureVerifierTest.php
git commit -m "feat(deployward): add SignatureVerifier for GitHub webhook HMAC"
```

---

### Task 2: DeployScheduler (background runner)

**Files:**
- Create: `src/Deploy/DeployScheduler.php`
- Test: `tests/Unit/Deploy/DeploySchedulerTest.php`

**Interfaces:**
- Consumes: `Deployward\Container` (for `repository()` + `deployer()`).
- Produces: `Deployward\Deploy\DeployScheduler` with constant `HOOK = 'deployward_run_deploy'`; `__construct(Container $container)`; `schedule(string $deploymentId, string $trigger, bool $force = false): void` (schedules a single WP-Cron event for `self::HOOK` with args `array($deploymentId, $trigger, $force)` and calls `spawn_cron()`); `run(string $deploymentId, string $trigger, bool $force = false): void` (the cron callback: finds the deployment and, if present, calls `deployer()->deploy()`).

- [ ] **Step 1: Write the failing test `tests/Unit/Deploy/DeploySchedulerTest.php`**

```php
<?php

namespace Deployward\Tests\Unit\Deploy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Config\Deployment;
use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\Container;
use Deployward\Deploy\DeployScheduler;
use Deployward\Deploy\DeployerInterface;
use Deployward\Support\Result;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class DeploySchedulerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function deployment(): Deployment
    {
        return Deployment::fromArray(array(
            'id' => 'dw_abc', 'repo' => 'o/r', 'branch' => 'main', 'visibility' => 'public',
            'target_type' => 'plugin', 'target_slug' => 'sample', 'token' => '', 'webhook_secret' => 's',
        ));
    }

    public function test_schedule_enqueues_a_single_event_and_spawns_cron(): void
    {
        $container = Mockery::mock(Container::class);
        Functions\expect('wp_schedule_single_event')->once()->with(
            Mockery::type('int'),
            DeployScheduler::HOOK,
            array('dw_abc', 'webhook', false)
        );
        Functions\expect('time')->andReturn(1000000000);
        Functions\expect('spawn_cron')->once();

        (new DeployScheduler($container))->schedule('dw_abc', 'webhook');
    }

    public function test_run_deploys_a_found_deployment(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->with('dw_abc')->andReturn($this->deployment());
        $deployer = Mockery::mock(DeployerInterface::class);
        $deployer->shouldReceive('deploy')->once()
            ->with(Mockery::type(Deployment::class), 'webhook', false)
            ->andReturn(Result::ok('sha'));
        $container = Mockery::mock(Container::class);
        $container->shouldReceive('repository')->andReturn($repo);
        $container->shouldReceive('deployer')->andReturn($deployer);

        (new DeployScheduler($container))->run('dw_abc', 'webhook', false);
    }

    public function test_run_ignores_a_missing_deployment(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->with('gone')->andReturn(null);
        $deployer = Mockery::mock(DeployerInterface::class);
        $deployer->shouldNotReceive('deploy');
        $container = Mockery::mock(Container::class);
        $container->shouldReceive('repository')->andReturn($repo);
        $container->shouldReceive('deployer')->andReturn($deployer);

        (new DeployScheduler($container))->run('gone', 'webhook', false);
    }
}
```

- [ ] **Step 2: Run to verify it fails** — class not found.

- [ ] **Step 3: Implement `src/Deploy/DeployScheduler.php`**

```php
<?php

namespace Deployward\Deploy;

use Deployward\Container;

final class DeployScheduler
{
    const HOOK = 'deployward_run_deploy';

    /** @var Container */
    private $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function schedule(string $deploymentId, string $trigger, bool $force = false): void
    {
        wp_schedule_single_event(time(), self::HOOK, array($deploymentId, $trigger, $force));
        spawn_cron();
    }

    public function run(string $deploymentId, string $trigger, bool $force = false): void
    {
        $deployment = $this->container->repository()->find($deploymentId);
        if ($deployment === null) {
            return;
        }
        $this->container->deployer()->deploy($deployment, $trigger, $force);
    }
}
```

- [ ] **Step 4: Run to verify it passes**, then **Step 5: Commit**

```bash
git add src/Deploy/DeployScheduler.php tests/Unit/Deploy/DeploySchedulerTest.php
git commit -m "feat(deployward): add DeployScheduler background runner"
```

---

### Task 3: WebhookController

**Files:**
- Create: `src/Http/WebhookController.php`
- Test: `tests/Unit/Http/WebhookControllerTest.php`

**Interfaces:**
- Consumes: `DeploymentRepositoryInterface`, `Deployward\Security\SignatureVerifier`, `Deployward\Deploy\DeployScheduler`, `ApiResponse`.
- Produces: `Deployward\Http\WebhookController` with `__construct(DeploymentRepositoryInterface $repository, SignatureVerifier $verifier, DeployScheduler $scheduler)` and `handle(string $deploymentId, string $rawBody, ?string $signatureHeader, ?string $eventHeader): ApiResponse`. Behavior: unknown id -> 404; invalid signature -> 401 (no deploy); `eventHeader === 'ping'` -> 200 `{message:'pong'}`; event other than `push` -> 200 `{message:'event ignored'}`; push whose `ref` is not `refs/heads/<watched-branch>` -> 200 `{message:'branch ignored'}`; push to the watched branch -> schedule the deploy and return 202 `{message:'queued', sha:<after>}`.

- [ ] **Step 1: Write the failing test `tests/Unit/Http/WebhookControllerTest.php`**

```php
<?php

namespace Deployward\Tests\Unit\Http;

use Deployward\Config\Deployment;
use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\Deploy\DeployScheduler;
use Deployward\Http\WebhookController;
use Deployward\Security\SignatureVerifier;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class WebhookControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private function deployment(string $secret = 'whsec'): Deployment
    {
        return Deployment::fromArray(array(
            'id' => 'dw_abc', 'repo' => 'o/r', 'branch' => 'main', 'visibility' => 'public',
            'target_type' => 'plugin', 'target_slug' => 'sample', 'token' => '', 'webhook_secret' => $secret,
        ));
    }

    private function controller($repo, $verifier, $scheduler): WebhookController
    {
        return new WebhookController($repo, $verifier, $scheduler);
    }

    public function test_unknown_id_returns_404(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->with('nope')->andReturn(null);
        $scheduler = Mockery::mock(DeployScheduler::class);
        $scheduler->shouldNotReceive('schedule');

        $res = $this->controller($repo, new SignatureVerifier(), $scheduler)
            ->handle('nope', '{}', 'sha256=x', 'push');

        $this->assertSame(404, $res->status());
    }

    public function test_invalid_signature_returns_401_and_does_not_deploy(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->andReturn($this->deployment());
        $verifier = Mockery::mock(SignatureVerifier::class);
        $verifier->shouldReceive('verify')->andReturn(false);
        $scheduler = Mockery::mock(DeployScheduler::class);
        $scheduler->shouldNotReceive('schedule');

        $res = $this->controller($repo, $verifier, $scheduler)
            ->handle('dw_abc', '{"ref":"refs/heads/main"}', 'sha256=bad', 'push');

        $this->assertSame(401, $res->status());
    }

    public function test_ping_event_returns_pong(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->andReturn($this->deployment());
        $verifier = Mockery::mock(SignatureVerifier::class);
        $verifier->shouldReceive('verify')->andReturn(true);
        $scheduler = Mockery::mock(DeployScheduler::class);
        $scheduler->shouldNotReceive('schedule');

        $res = $this->controller($repo, $verifier, $scheduler)
            ->handle('dw_abc', '{"zen":"x"}', 'sha256=ok', 'ping');

        $this->assertSame(200, $res->status());
        $this->assertSame('pong', $res->data()['message']);
    }

    public function test_push_to_other_branch_is_ignored(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->andReturn($this->deployment());
        $verifier = Mockery::mock(SignatureVerifier::class);
        $verifier->shouldReceive('verify')->andReturn(true);
        $scheduler = Mockery::mock(DeployScheduler::class);
        $scheduler->shouldNotReceive('schedule');

        $res = $this->controller($repo, $verifier, $scheduler)
            ->handle('dw_abc', '{"ref":"refs/heads/develop"}', 'sha256=ok', 'push');

        $this->assertSame(200, $res->status());
    }

    public function test_push_to_watched_branch_queues_deploy_202(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->andReturn($this->deployment());
        $verifier = Mockery::mock(SignatureVerifier::class);
        $verifier->shouldReceive('verify')->andReturn(true);
        $scheduler = Mockery::mock(DeployScheduler::class);
        $scheduler->shouldReceive('schedule')->once()->with('dw_abc', 'webhook', false);

        $res = $this->controller($repo, $verifier, $scheduler)
            ->handle('dw_abc', '{"ref":"refs/heads/main"}', 'sha256=ok', 'push');

        $this->assertSame(202, $res->status());
    }
}
```

- [ ] **Step 2: Run to verify it fails** — class not found.

- [ ] **Step 3: Implement `src/Http/WebhookController.php`**

```php
<?php

namespace Deployward\Http;

use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\Deploy\DeployScheduler;
use Deployward\Security\SignatureVerifier;

final class WebhookController
{
    /** @var DeploymentRepositoryInterface */
    private $repository;
    /** @var SignatureVerifier */
    private $verifier;
    /** @var DeployScheduler */
    private $scheduler;

    public function __construct(
        DeploymentRepositoryInterface $repository,
        SignatureVerifier $verifier,
        DeployScheduler $scheduler
    ) {
        $this->repository = $repository;
        $this->verifier = $verifier;
        $this->scheduler = $scheduler;
    }

    public function handle(string $deploymentId, string $rawBody, ?string $signatureHeader, ?string $eventHeader): ApiResponse
    {
        $deployment = $this->repository->find($deploymentId);
        if ($deployment === null) {
            return ApiResponse::error('Deployment not found', 404);
        }
        if (! $this->verifier->verify($rawBody, $signatureHeader, $deployment->webhookSecret())) {
            return ApiResponse::error('Invalid signature', 401);
        }
        if ($eventHeader === 'ping') {
            return ApiResponse::ok(array('message' => 'pong'));
        }
        if ($eventHeader !== 'push') {
            return ApiResponse::ok(array('message' => 'event ignored'));
        }
        $payload = json_decode($rawBody, true);
        $ref = is_array($payload) && isset($payload['ref']) ? (string) $payload['ref'] : '';
        if ($ref !== 'refs/heads/' . $deployment->branch()) {
            return ApiResponse::ok(array('message' => 'branch ignored'));
        }
        $this->scheduler->schedule($deployment->id(), 'webhook', false);

        return ApiResponse::ok(array('message' => 'queued'), 202);
    }
}
```

- [ ] **Step 4: Run to verify it passes**, then **Step 5: Commit**

```bash
git add src/Http/WebhookController.php tests/Unit/Http/WebhookControllerTest.php
git commit -m "feat(deployward): add WebhookController (HMAC verify, branch match, queue deploy)"
```

---

### Task 4: CronPoller

**Files:**
- Create: `src/Cron/CronPoller.php`
- Test: `tests/Unit/Cron/CronPollerTest.php`

**Interfaces:**
- Consumes: `DeploymentRepositoryInterface`, `GitHubClientInterface`, `DeployScheduler`, `Result`, `Deployment`.
- Produces: `Deployward\Cron\CronPoller` with constant `HOOK = 'deployward_poll'` and constant `INTERVAL = 'deployward_5min'`; `__construct(DeploymentRepositoryInterface $repository, GitHubClientInterface $github, DeployScheduler $scheduler)`; `poll(): void` (for each deployment: resolve the watched branch SHA with the token only for private repos; if it resolved and differs from `lastDeployedSha()`, call `scheduler->schedule(id, 'cron', false)`); `static addInterval(array $schedules): array` (adds a 300-second `deployward_5min` schedule for the `cron_schedules` filter).

- [ ] **Step 1: Write the failing test `tests/Unit/Cron/CronPollerTest.php`**

```php
<?php

namespace Deployward\Tests\Unit\Cron;

use Deployward\Config\Deployment;
use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\Cron\CronPoller;
use Deployward\Deploy\DeployScheduler;
use Deployward\GitHub\GitHubClientInterface;
use Deployward\Support\Result;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class CronPollerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private function deployment(string $lastSha): Deployment
    {
        return Deployment::fromArray(array(
            'id' => 'dw_abc', 'repo' => 'o/r', 'branch' => 'main', 'visibility' => 'public',
            'target_type' => 'plugin', 'target_slug' => 'sample', 'token' => '', 'webhook_secret' => 's',
            'last_deployed_sha' => $lastSha,
        ));
    }

    public function test_queues_deploy_when_sha_changed(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('all')->andReturn(array('dw_abc' => $this->deployment('oldsha')));
        $github = Mockery::mock(GitHubClientInterface::class);
        $github->shouldReceive('resolveSha')->with('o/r', 'main', null)->andReturn(Result::ok('newsha'));
        $scheduler = Mockery::mock(DeployScheduler::class);
        $scheduler->shouldReceive('schedule')->once()->with('dw_abc', 'cron', false);

        (new CronPoller($repo, $github, $scheduler))->poll();
    }

    public function test_does_not_queue_when_sha_unchanged(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('all')->andReturn(array('dw_abc' => $this->deployment('samesha')));
        $github = Mockery::mock(GitHubClientInterface::class);
        $github->shouldReceive('resolveSha')->andReturn(Result::ok('samesha'));
        $scheduler = Mockery::mock(DeployScheduler::class);
        $scheduler->shouldNotReceive('schedule');

        (new CronPoller($repo, $github, $scheduler))->poll();
    }

    public function test_skips_when_sha_cannot_be_resolved(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('all')->andReturn(array('dw_abc' => $this->deployment('oldsha')));
        $github = Mockery::mock(GitHubClientInterface::class);
        $github->shouldReceive('resolveSha')->andReturn(Result::fail('GitHub HTTP 500'));
        $scheduler = Mockery::mock(DeployScheduler::class);
        $scheduler->shouldNotReceive('schedule');

        (new CronPoller($repo, $github, $scheduler))->poll();
    }

    public function test_add_interval_registers_five_minutes(): void
    {
        $schedules = CronPoller::addInterval(array());

        $this->assertSame(300, $schedules[CronPoller::INTERVAL]['interval']);
    }
}
```

- [ ] **Step 2: Run to verify it fails** — class not found.

- [ ] **Step 3: Implement `src/Cron/CronPoller.php`**

```php
<?php

namespace Deployward\Cron;

use Deployward\Config\Deployment;
use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\Deploy\DeployScheduler;
use Deployward\GitHub\GitHubClientInterface;

final class CronPoller
{
    const HOOK = 'deployward_poll';
    const INTERVAL = 'deployward_5min';

    /** @var DeploymentRepositoryInterface */
    private $repository;
    /** @var GitHubClientInterface */
    private $github;
    /** @var DeployScheduler */
    private $scheduler;

    public function __construct(
        DeploymentRepositoryInterface $repository,
        GitHubClientInterface $github,
        DeployScheduler $scheduler
    ) {
        $this->repository = $repository;
        $this->github = $github;
        $this->scheduler = $scheduler;
    }

    public function poll(): void
    {
        foreach ($this->repository->all() as $deployment) {
            $this->pollOne($deployment);
        }
    }

    private function pollOne(Deployment $deployment): void
    {
        $token = $deployment->visibility() === 'private' ? $deployment->token() : null;
        $result = $this->github->resolveSha($deployment->repo(), $deployment->branch(), $token);
        if (! $result->isOk()) {
            return;
        }
        if ((string) $result->data() === $deployment->lastDeployedSha()) {
            return;
        }
        $this->scheduler->schedule($deployment->id(), 'cron', false);
    }

    public static function addInterval(array $schedules): array
    {
        return array_merge($schedules, array(
            self::INTERVAL => array('interval' => 300, 'display' => 'Every 5 minutes (Deployward)'),
        ));
    }
}
```

- [ ] **Step 4: Run to verify it passes**, then **Step 5: Commit**

```bash
git add src/Cron/CronPoller.php tests/Unit/Cron/CronPollerTest.php
git commit -m "feat(deployward): add CronPoller fallback (queue deploy on changed sha)"
```

---

### Task 5: RestController::webhookInfo (admin-only secret retrieval)

**Files:**
- Modify: `src/Http/RestController.php`
- Test: `tests/Unit/Http/RestControllerTest.php`

**Interfaces:**
- Produces (added to `RestController`): `webhookInfo(string $id): ApiResponse` — unknown id -> 404; else 200 `{secret: <webhook_secret>}`. (The JS builds the full webhook URL from its known REST root plus the id; only the secret is returned here.)

- [ ] **Step 1: Add the failing test** to `tests/Unit/Http/RestControllerTest.php`

```php
    public function test_webhook_info_returns_secret_for_known_id(): void
    {
        $deployment = Deployment::fromArray(array(
            'id' => 'dw_1', 'repo' => 'o/r', 'branch' => 'main', 'visibility' => 'public',
            'target_type' => 'plugin', 'target_slug' => 'sample', 'token' => '', 'webhook_secret' => 'whsecret',
        ));
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->with('dw_1')->andReturn($deployment);

        $response = $this->controller($repo)->webhookInfo('dw_1');

        $this->assertSame(200, $response->status());
        $this->assertSame('whsecret', $response->data()['secret']);
    }

    public function test_webhook_info_unknown_id_404(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->with('nope')->andReturn(null);

        $this->assertSame(404, $this->controller($repo)->webhookInfo('nope')->status());
    }
```

- [ ] **Step 2: Run to verify it fails** — undefined method `webhookInfo`.

- [ ] **Step 3: Add the method to `src/Http/RestController.php`**

```php
    public function webhookInfo(string $id): ApiResponse
    {
        $deployment = $this->repository->find($id);
        if ($deployment === null) {
            return ApiResponse::error('Deployment not found', 404);
        }

        return ApiResponse::ok(array('secret' => $deployment->webhookSecret()));
    }
```

- [ ] **Step 4: Run to verify it passes**, then **Step 5: Commit**

```bash
git add src/Http/RestController.php tests/Unit/Http/RestControllerTest.php
git commit -m "feat(deployward): add admin-only webhookInfo secret retrieval"
```

---

### Task 6: Routes for webhook (public) and webhook-info (admin)

**Files:**
- Modify: `src/Http/RestRoutes.php`
- Test: `tests/Unit/Http/RestRoutesTest.php`

**Interfaces:**
- Consumes: `WebhookController`, `RestController`, `ApiResponse`.
- Produces: `RestRoutes::__construct(RestController $controller, WebhookController $webhook)`; `register()` additionally registers (a) `POST /webhook/(?P<id>[a-zA-Z0-9_-]+)` with `permission_callback => '__return_true'` whose callback reads the RAW body and the `X-Hub-Signature-256` and `X-GitHub-Event` headers and calls `$this->webhook->handle(...)`, and (b) `GET /deployments/(?P<id>[a-zA-Z0-9_-]+)/webhook` with the `canManage` permission calling `$this->controller->webhookInfo(...)`. Constant `RestRoutes::NAMESPACE` is unchanged.

- [ ] **Step 1: Add failing tests** to `tests/Unit/Http/RestRoutesTest.php` (extend the existing file; it already defines a `WP_REST_Response` stub and uses Brain Monkey)

```php
    public function test_constructor_accepts_a_webhook_controller(): void
    {
        $routes = new RestRoutes(
            $this->makeRestController(),
            Mockery::mock(\Deployward\Http\WebhookController::class)
        );

        $this->assertInstanceOf(RestRoutes::class, $routes);
    }

    public function test_register_adds_the_public_webhook_route(): void
    {
        $captured = array();
        Functions\when('register_rest_route')->alias(function ($ns, $route, $args) use (&$captured) {
            $captured[] = array('route' => $route, 'args' => $args);
        });
        Functions\when('current_user_can')->justReturn(true);

        $routes = new RestRoutes(
            $this->makeRestController(),
            Mockery::mock(\Deployward\Http\WebhookController::class)
        );
        $routes->register();

        $webhook = null;
        foreach ($captured as $r) {
            if (strpos($r['route'], '/webhook/') === 0) {
                $webhook = $r;
            }
        }
        $this->assertNotNull($webhook, 'webhook route registered');
        // Public route: permission_callback must allow unauthenticated GitHub callers.
        $perm = isset($webhook['args']['permission_callback']) ? $webhook['args']['permission_callback'] : (isset($webhook['args'][0]['permission_callback']) ? $webhook['args'][0]['permission_callback'] : null);
        $this->assertTrue($perm === '__return_true' || (is_callable($perm) && $perm() === true));
    }
```

Add a small helper to the test class so a real (final) `RestController` can be built with mocked interfaces (mirroring the existing pattern for the `final` class):

```php
    private function makeRestController(): \Deployward\Http\RestController
    {
        return new \Deployward\Http\RestController(
            Mockery::mock(\Deployward\Config\DeploymentRepositoryInterface::class),
            Mockery::mock(\Deployward\Deploy\DeployerInterface::class),
            Mockery::mock(\Deployward\Log\DeployLogInterface::class),
            Mockery::mock(\Deployward\GitHub\GitHubClientInterface::class)
        );
    }
```

(Adjust imports/`use` to match the existing test file. If the file already constructs a RestController this way, reuse that helper instead of duplicating.)

- [ ] **Step 2: Run to verify it fails** — `RestRoutes::__construct()` arg count / missing webhook route.

- [ ] **Step 3: Modify `src/Http/RestRoutes.php`** — add the `WebhookController` dependency and the two routes.

Update the constructor and property:

```php
    /** @var WebhookController */
    private $webhook;

    public function __construct(RestController $controller, WebhookController $webhook)
    {
        $this->controller = $controller;
        $this->webhook = $webhook;
    }
```

Add `use Deployward\Http\WebhookController;` (same namespace `Deployward\Http`, so no import is needed; just reference the class). Inside `register()`, after the existing routes, add:

```php
        register_rest_route(self::NAMESPACE, '/deployments/(?P<id>[a-zA-Z0-9_-]+)/webhook', array(
            'methods' => 'GET',
            'permission_callback' => array(__CLASS__, 'canManage'),
            'callback' => function ($request) {
                return $this->respond($this->controller->webhookInfo(sanitize_key($request['id'])));
            },
        ));

        register_rest_route(self::NAMESPACE, '/webhook/(?P<id>[a-zA-Z0-9_-]+)', array(
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => function ($request) {
                return $this->respond($this->webhook->handle(
                    sanitize_key($request['id']),
                    (string) $request->get_body(),
                    $request->get_header('x-hub-signature-256'),
                    $request->get_header('x-github-event')
                ));
            },
        ));
```

- [ ] **Step 4: Run to verify it passes** (full suite green, 0 risky). Note: `Plugin::boot` still constructs `RestRoutes` with one argument until Task 7; the unit tests construct it with two, so they pass now, and Task 7 fixes the production wiring.

- [ ] **Step 5: Commit**

```bash
git add src/Http/RestRoutes.php tests/Unit/Http/RestRoutesTest.php
git commit -m "feat(deployward): register public webhook route and admin webhook-info route"
```

---

### Task 7: Wire triggers into Plugin (boot, activate, deactivate)

**Files:**
- Modify: `src/Plugin.php`
- Modify: `deployward.php` (register the deactivation hook)
- Test: `tests/Unit/PluginCronTest.php`

**Interfaces:**
- Produces: `Plugin::boot()` constructs `DeployScheduler`, `CronPoller`, `WebhookController`, passes the webhook controller into `RestRoutes`, registers `add_action(DeployScheduler::HOOK, array($scheduler,'run'), 10, 3)`, `add_action(CronPoller::HOOK, array($poller,'poll'))`, and `add_filter('cron_schedules', array('Deployward\\Cron\\CronPoller','addInterval'))`. `Plugin::activate()` also schedules the recurring poll if not already scheduled. New `Plugin::deactivate()` clears the poll and run-deploy hooks. The only directly unit-testable seam is the `cron_schedules` interval (already covered by CronPollerTest); this task's test asserts `Plugin` exposes a static `cronInterval()` passthrough used by the filter, keeping the wiring honest without booting WordPress.

- [ ] **Step 1: Write the failing test `tests/Unit/PluginCronTest.php`**

```php
<?php

namespace Deployward\Tests\Unit;

use Deployward\Cron\CronPoller;
use Deployward\Plugin;
use PHPUnit\Framework\TestCase;

final class PluginCronTest extends TestCase
{
    public function test_cron_interval_passthrough_adds_five_minute_schedule(): void
    {
        $schedules = Plugin::cronInterval(array('hourly' => array('interval' => 3600, 'display' => 'Hourly')));

        $this->assertArrayHasKey('hourly', $schedules);
        $this->assertSame(300, $schedules[CronPoller::INTERVAL]['interval']);
    }
}
```

- [ ] **Step 2: Run to verify it fails** — undefined method `Plugin::cronInterval`.

- [ ] **Step 3: Modify `src/Plugin.php`** — add imports, the boot wiring, activate scheduling, deactivate clearing, and the `cronInterval` passthrough.

Add to the `use` block:

```php
use Deployward\Cron\CronPoller;
use Deployward\Deploy\DeployScheduler;
use Deployward\Http\WebhookController;
use Deployward\Security\SignatureVerifier;
```

Replace `boot()` with (preserving the existing WP-CLI registration and admin page wiring; this shows the full method):

```php
    public static function boot(): void
    {
        global $wpdb;
        $container = new Container($wpdb);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('deployward', self::makeCommand());
        }

        $scheduler = new DeployScheduler($container);
        $poller = new CronPoller($container->repository(), $container->github(), $scheduler);

        add_filter('cron_schedules', array(__CLASS__, 'cronInterval'));
        add_action(DeployScheduler::HOOK, array($scheduler, 'run'), 10, 3);
        add_action(CronPoller::HOOK, array($poller, 'poll'));

        $routes = new RestRoutes(
            new RestController(
                $container->repository(),
                $container->deployer(),
                $container->log(),
                $container->github()
            ),
            new WebhookController($container->repository(), new SignatureVerifier(), $scheduler)
        );
        add_action('rest_api_init', array($routes, 'register'));

        $page = new AdminPage();
        add_action('admin_menu', array($page, 'register'));
        add_action('admin_enqueue_scripts', array($page, 'enqueue'));
    }

    public static function cronInterval(array $schedules): array
    {
        return CronPoller::addInterval($schedules);
    }
```

Update `activate()` to also schedule the poll (keep the existing log-table install):

```php
    public static function activate(): void
    {
        global $wpdb;
        $log = new DeployLog($wpdb);
        $log->installTable();

        if (! wp_next_scheduled(CronPoller::HOOK)) {
            wp_schedule_event(time(), CronPoller::INTERVAL, CronPoller::HOOK);
        }
    }
```

Add `deactivate()`:

```php
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(CronPoller::HOOK);
        wp_clear_scheduled_hook(DeployScheduler::HOOK);
    }
```

Note: scheduling the poll with `CronPoller::INTERVAL` at activation requires the `cron_schedules` filter to be registered before the schedule resolves; WordPress resolves the interval lazily on the next cron run, and `boot()` registers the filter on every load, so the recurring event fires correctly. If `wp_schedule_event` returns false at activation because the interval is not yet known, the first poll still self-heals once `boot()` has run; acceptable for v1.

- [ ] **Step 4: Modify `deployward.php`** — register the deactivation hook next to the activation hook:

```php
register_activation_hook(__FILE__, array('\\Deployward\\Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('\\Deployward\\Plugin', 'deactivate'));
add_action('plugins_loaded', array('\\Deployward\\Plugin', 'boot'));
```

(Keep the existing activation and `plugins_loaded` lines; add only the deactivation line.)

- [ ] **Step 5: Run tests** — `"$PHP" vendor/bin/phpunit`. Full suite green, 0 risky.

- [ ] **Step 6: Commit**

```bash
git add src/Plugin.php deployward.php tests/Unit/PluginCronTest.php
git commit -m "feat(deployward): wire webhook + cron triggers, schedule poll on activate, clear on deactivate"
```

---

### Task 8: Admin UI for webhook setup + Getting Started update

**Files:**
- Modify: `assets/admin.js`
- Modify: `assets/admin.css`

Presentation assets, verified in the browser (Task 9). No RED/GREEN cycle.

- [ ] **Step 1: Add a per-deployment "Webhook" affordance in `assets/admin.js`**
  - On each deployment card, add a button "Webhook setup" (class `dw-btn dw-btn--ghost`).
  - Clicking it calls `GET deployments/{id}/webhook` (the admin webhook-info endpoint) to get `{secret}`, builds the full URL as `root + 'webhook/' + id` (root is the existing `dataset.root`), and renders an inline panel showing: the Payload URL (read-only text, copyable), the Secret (read-only, copyable), and the fixed settings to choose in GitHub ("Content type: application/json", "Event: Just the push event"). Provide a "Copy" affordance for the URL and the secret using `navigator.clipboard.writeText` with a graceful fallback (select the text) and a small "Copied" confirmation. Build all nodes with `createElement`/`textContent`; never use innerHTML with the secret.
  - Do NOT auto-fetch the secret for every card on list render; fetch it only when the user opens the Webhook panel (so the secret is not pulled into the DOM unless requested).
- [ ] **Step 2: Update the Getting Started tab in `assets/admin.js`**
  - Replace the closing note that says automatic deploy-on-push "arrives in the next release" with a real "Automatic deploys (webhook)" section: 1) open Webhook setup on the deployment, 2) copy the Payload URL and Secret, 3) in GitHub go to the repo Settings -> Webhooks -> Add webhook, paste the Payload URL, set Content type to application/json, paste the Secret, choose "Just the push event", save, 4) a note that without a webhook, Deployward also polls every 5 minutes and deploys new commits automatically.
- [ ] **Step 3: Add styles in `assets/admin.css`** for the webhook panel (`.dw-webhook`, `.dw-webhook__row`, `.dw-webhook__value` monospace read-only field, a `.dw-copy` button, a `.dw-copied` confirmation), scoped under `#deployward-app`, matching the existing token-based design. No em-dashes.
- [ ] **Step 4: Syntax check** — `node --check assets/admin.js` (SYNTAX OK).
- [ ] **Step 5: Commit**

```bash
git add assets/admin.js assets/admin.css
git commit -m "feat(deployward): admin webhook setup panel and Getting Started webhook steps"
```

---

### Task 9: Live verification on nara.local (webhook + cron)

NEVER target a real in-use plugin. Use a throwaway slug and the user-provided public test repo. Use the WP-CLI socket override from the project notes to inspect state.

**Files:** none (verification + any fixes it surfaces).

- [ ] **Step 1: Setup** — ensure the plugin is active; create a deployment for the public test repo into slug `deployward-test` (via the UI or `wp deployward add`). Note its id and webhook secret (via the UI Webhook setup panel, or `wp eval` reading the repository).
- [ ] **Step 2: Simulate a signed GitHub push webhook** with curl, computing the HMAC locally:
  - `SECRET=<the deployment secret>`
  - `BODY='{"ref":"refs/heads/main"}'`
  - `SIG="sha256=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.* //')"`
  - `curl -sk -X POST "https://nara.local/wp-json/deployward/v1/webhook/<id>" -H "X-Hub-Signature-256: $SIG" -H "X-GitHub-Event: push" -H "Content-Type: application/json" -d "$BODY" -i | head -20`
  - Expected: HTTP 202 with `{"message":"queued"}`.
- [ ] **Step 3: Confirm the background deploy ran** — after a few seconds (cron spawned), verify `wp-content/plugins/deployward-test/` was deployed and the audit log has a `webhook` row (`wp eval` reading `DeployLog::recent` for the id, or query the table). If WP-Cron did not fire on the Local host, run `wp cron event run deployward_run_deploy` (with the socket override) and re-check.
- [ ] **Step 4: Negative webhook checks** — repeat the curl with a WRONG signature (expect 401, no deploy) and with `X-GitHub-Event: ping` (expect 200 `{"message":"pong"}`, no deploy), and with `"ref":"refs/heads/other"` (expect 200 `{"message":"branch ignored"}`, no deploy).
- [ ] **Step 5: Cron poll check** — confirm the recurring event is scheduled (`wp cron event list | grep deployward_poll`). Set the deployment's `last_deployed_sha` to a stale value (`wp eval`), run `wp cron event run deployward_poll`, and confirm it queued/ran a deploy that updated the SHA.
- [ ] **Step 6: UI check** — open the deployment's Webhook setup panel in the browser; confirm the Payload URL and Secret display and copy; screenshot it and the updated Getting Started tab. Confirm no console errors.
- [ ] **Step 7: Clean up** — remove the test deployment, the `deployward-test` directory and its backups, and any temp admin user created for the browser. Leave the plugin active.
- [ ] **Step 8: Record** — commit a short `docs/superpowers/verification-triggers.md` capturing what was tested and the results; commit any fixes the verification surfaced as their own `fix(deployward): ...` commits.

---

## Self-Review

**1. Spec coverage:**
- Webhook trigger (instant), HMAC-verified, push-to-watched-branch, 202 + background: Tasks 1, 2, 3, 6, 7. Covered.
- WP-Cron polling fallback (every 5 min, deploy on changed SHA): Tasks 2, 4, 7. Covered.
- Background runner (single event + spawn cron) so the webhook responds fast: Task 2. Covered.
- Admin UI to copy the webhook URL + secret into GitHub, Getting Started updated: Tasks 5, 6, 8. Covered.
- Manual deploy stays synchronous: unchanged, stated in Global Constraints.
- Public webhook route authenticated by HMAC, all other routes capability-gated: Tasks 6, 7, with the security rationale in Global Constraints.

**2. Placeholder scan:** PHP steps contain complete code. The UI task (8) is a concrete behavior spec (every screen, endpoint, and the no-innerHTML/secret-on-demand rules enumerated), verified in-browser in Task 9, consistent with the no-build mandate. No "TBD"/"TODO".

**3. Type consistency:** `DeployScheduler::HOOK`/`schedule()`/`run()` are used identically across Tasks 2, 3, 4, 7. `CronPoller::HOOK`/`INTERVAL`/`addInterval()`/`poll()` are consistent across Tasks 4, 7 and `PluginCronTest`. `WebhookController::handle(string,string,?string,?string): ApiResponse` matches the route callback in Task 6. `RestController::webhookInfo(string): ApiResponse` matches Tasks 5 and 6. `RestRoutes::__construct(RestController, WebhookController)` matches Tasks 6 and 7. `ApiResponse` and the engine interfaces match their definitions on `main`.

Identified intentional scope note (not a gap): manual "Deploy now" remains synchronous; routing it through the background runner with UI status polling is a future enhancement, not part of this plan.
