# Deployward Admin UI (Plan 3) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a beautiful, modern, tabbed single-page admin screen for Deployward (Deployments / Add Deployment / Activity Log / Getting Started) backed by an admin-only REST API, so an operator can configure public or private GitHub deployments and run manual deploy/rollback with no page reloads and minimal effort.

**Architecture:** A no-build, dependency-free admin SPA. PHP exposes an admin-only REST API (`deployward/v1`) whose controller depends only on the existing engine interfaces and returns a small `ApiResponse` value object (status + data); a thin route registrar maps that to WordPress REST responses with capability and nonce checks. A single enqueued vanilla-JS app renders the tabs and screens and talks to the REST API via `fetch()` with the `X-WP-Nonce` header. A shared service factory (`Container`) builds the engine object graph once and is reused by both the WP-CLI command and the REST controller (DRY).

**Tech Stack:** PHP 7.4, WordPress REST API, vanilla ES6 (no framework, no build step), modern CSS (custom properties), PHPUnit 10 + Brain Monkey + Mockery for the PHP core.

## Global Constraints

Apply to every task. Copied from the spec and the locked Plan 3 decisions.

- PHP 7.4 floor. NO PHP 8.0+ syntax (no constructor property promotion, no union/`mixed` type declarations, no `match`, no enums, no named args, no nullsafe `?->`). `/** @var */` docblocks are fine.
- WordPress 6.0+. Files under 400 lines, functions under 50 lines, nesting under 4.
- Immutability: build new arrays/objects; never mutate shared state.
- Security: EVERY REST route's `permission_callback` is `current_user_can('manage_options')`; the JS sends the REST nonce (`X-WP-Nonce`); sanitize every request param via `sanitize_callback`/explicit sanitizers; escape every value printed into the admin page (`esc_attr`, `esc_url`, `esc_html`); never echo a token; the REST API never returns a token or webhook secret in any payload.
- No build step, no npm, no framework. The admin app is plain ES6 + CSS enqueued as static files. It must work on any host with the plugin active and JS enabled.
- Reuse the existing engine via the interfaces (`DeploymentRepositoryInterface`, `DeployerInterface`, `DeployLogInterface`, `GitHubClientInterface`) and the `Deployment` value object and `Result`. Do not duplicate engine logic.
- Deployward never deploys itself (already enforced in `Deployer`); the UI must not offer `deployward` as a target slug for a plugin deployment (client-side guard plus the server guard).
- No em-dashes anywhere (code, comments, docs, UI copy).
- TDD for all PHP: failing test first (RED), then implementation (GREEN), 80%+ coverage on new PHP. The vanilla JS and CSS are presentation assets verified in the browser on nara.local (no JS unit harness); their acceptance is the manual verification in the final task.
- Test runner: Local PHP 8.2.30 binary, PHPUnit 10. The canonical command:
  `PHP="/Users/kamalahmed-nara/Library/Application Support/Local/lightning-services/php-8.2.30+1/bin/darwin-arm64/bin/php"; "$PHP" vendor/bin/phpunit`
- Mockery-using test classes MUST `use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;` (import + `use` inside the class) so the suite stays at 0 risky tests.

## Design language (for admin.css / admin.js)

Model the look on WordPress core's modern admin (Site Health, block-editor side panels) and WooCommerce-style tabbed settings: calm, card-based, generous spacing, a single accent color, clear status pills, accessible focus rings.

- All styles scoped under `#deployward-app` so nothing leaks into the rest of wp-admin.
- Design tokens as CSS custom properties on `#deployward-app`: `--dw-accent` (a deep indigo, e.g. `#3858e9`, WP's admin blue-violet family), `--dw-bg`, `--dw-card`, `--dw-border`, `--dw-text`, `--dw-muted`, `--dw-ok` (green), `--dw-warn` (amber), `--dw-danger` (red), `--dw-radius` (10px), `--dw-shadow`. Respect `prefers-color-scheme: dark` with a dark token set.
- Status pills: Healthy (green), Failed (red), Deploying (amber, animated dots), Never deployed (muted).
- Buttons: a primary (accent, used for Deploy now / Save), a secondary (outline), a destructive (danger outline for Delete/Rollback-confirm). Visible `:focus-visible` ring. Disabled + spinner state for in-flight actions.
- Cards with `--dw-radius` and `--dw-shadow`; a sticky tab bar; responsive down to a narrow column.
- No external fonts or CDN assets; use the system font stack.

## File Structure

- Create `src/Container.php` — shared service factory; builds repository/deployer/log/github from WP globals. Reused by `Plugin` (CLI) and the REST controller.
- Modify `src/Plugin.php` — use `Container`; register REST routes (`rest_api_init`), the admin menu (`admin_menu`), and asset enqueues (`admin_enqueue_scripts`).
- Modify `src/GitHub/GitHubClient.php` + `src/GitHub/GitHubClientInterface.php` — add `listBranches()`.
- Create `src/Http/ApiResponse.php` — immutable HTTP result (status + data).
- Create `src/Http/RestController.php` — handler methods returning `ApiResponse`, depending on interfaces; WP-free and unit-tested.
- Create `src/Http/RestRoutes.php` — registers `register_rest_route` entries, capability + arg sanitizers, and adapts `ApiResponse` to `WP_REST_Response`.
- Create `src/Admin/AdminPage.php` — registers the menu, renders the app container, enqueues + localizes assets.
- Create `assets/admin.css`, `assets/admin.js` — the SPA.
- Tests: `tests/Unit/Http/ApiResponseTest.php`, `tests/Unit/Http/RestControllerTest.php`, extend `tests/Unit/GitHub/GitHubClientTest.php`, `tests/Unit/ContainerTest.php`.

---

### Task 1: Shared service factory (`Container`)

Refactor the engine wiring out of `Plugin::makeCommand()` into a reusable `Container` so the REST controller and the CLI share one composition root.

**Files:**
- Create: `src/Container.php`
- Modify: `src/Plugin.php` (have `makeCommand()` delegate to `Container`)
- Test: `tests/Unit/ContainerTest.php`

**Interfaces:**
- Consumes: all engine classes/interfaces (GitHubClient, Extractor, PayloadValidator, BackupManager, HealthChecker, MaintenanceMode, DeployLog, DeploymentRepository, Notifier, Encryptor, Deployer).
- Produces: `Deployward\Container` with `__construct($wpdb)`, and methods `repository(): DeploymentRepositoryInterface`, `log(): DeployLogInterface`, `github(): GitHubClientInterface`, `deployer(): DeployerInterface`. Each returns a fully-wired concrete instance. `Container` reads WP globals/constants/functions exactly as the current `makeCommand()` does.

- [ ] **Step 1: Write the failing test `tests/Unit/ContainerTest.php`**

```php
<?php

namespace Deployward\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\Container;
use Deployward\Deploy\DeployerInterface;
use Deployward\GitHub\GitHubClientInterface;
use Deployward\Log\DeployLogInterface;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class ContainerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        if (! defined('AUTH_KEY')) {
            define('AUTH_KEY', str_repeat('a', 64));
        }
        if (! defined('AUTH_SALT')) {
            define('AUTH_SALT', str_repeat('b', 64));
        }
        if (! defined('WP_PLUGIN_DIR')) {
            define('WP_PLUGIN_DIR', sys_get_temp_dir() . '/plugins');
        }
        if (! defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', sys_get_temp_dir());
        }
        if (! defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }
        if (! defined('DEPLOYWARD_SLUG')) {
            define('DEPLOYWARD_SLUG', 'deployward');
        }
        Functions\when('wp_upload_dir')->justReturn(array('basedir' => sys_get_temp_dir() . '/uploads'));
        Functions\when('get_temp_dir')->justReturn(sys_get_temp_dir() . '/');
        Functions\when('get_theme_root')->justReturn(sys_get_temp_dir() . '/themes');
        Functions\when('home_url')->justReturn('https://nara.local/');
        Functions\when('get_option')->justReturn('admin@example.com');
        Functions\when('trailingslashit')->alias(function ($p) {
            return rtrim($p, '/') . '/';
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_builds_the_engine_collaborators(): void
    {
        $wpdb = Mockery::mock();
        $wpdb->prefix = 'wp_';
        $container = new Container($wpdb);

        $this->assertInstanceOf(DeploymentRepositoryInterface::class, $container->repository());
        $this->assertInstanceOf(DeployLogInterface::class, $container->log());
        $this->assertInstanceOf(GitHubClientInterface::class, $container->github());
        $this->assertInstanceOf(DeployerInterface::class, $container->deployer());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `"$PHP" vendor/bin/phpunit --filter ContainerTest`
Expected: FAIL with "Class Deployward\Container not found".

- [ ] **Step 3: Write `src/Container.php`** (move the wiring from `Plugin::makeCommand`)

```php
<?php

namespace Deployward;

use Deployward\Config\DeploymentRepository;
use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\Deploy\BackupManager;
use Deployward\Deploy\Deployer;
use Deployward\Deploy\DeployerInterface;
use Deployward\Deploy\Extractor;
use Deployward\Deploy\HealthChecker;
use Deployward\Deploy\MaintenanceMode;
use Deployward\Deploy\PayloadValidator;
use Deployward\GitHub\GitHubClient;
use Deployward\GitHub\GitHubClientInterface;
use Deployward\Log\DeployLog;
use Deployward\Log\DeployLogInterface;
use Deployward\Notify\Notifier;
use Deployward\Security\Encryptor;

final class Container
{
    /** @var object */
    private $wpdb;

    public function __construct($wpdb)
    {
        $this->wpdb = $wpdb;
    }

    public function repository(): DeploymentRepositoryInterface
    {
        return new DeploymentRepository(Encryptor::fromSalts());
    }

    public function log(): DeployLogInterface
    {
        return new DeployLog($this->wpdb);
    }

    public function github(): GitHubClientInterface
    {
        return new GitHubClient();
    }

    public function deployer(): DeployerInterface
    {
        $uploads = wp_upload_dir();
        $backupsBase = trailingslashit($uploads['basedir'])
            . 'deployward-backups-' . substr(md5(AUTH_SALT), 0, 8);
        $muDir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';

        return new Deployer(
            $this->github(),
            new Extractor(get_temp_dir()),
            new PayloadValidator(),
            new BackupManager($backupsBase),
            new HealthChecker(),
            new MaintenanceMode(ABSPATH),
            $this->log(),
            $this->repository(),
            new Notifier((string) get_option('admin_email', '')),
            array(
                'plugin' => WP_PLUGIN_DIR,
                'theme' => get_theme_root(),
                'mu-plugin' => $muDir,
            ),
            home_url('/'),
            get_temp_dir(),
            defined('DEPLOYWARD_SLUG') ? DEPLOYWARD_SLUG : 'deployward',
            3
        );
    }
}
```

- [ ] **Step 4: Update `src/Plugin.php` `makeCommand()` to delegate to `Container`**

Replace the body of `makeCommand()` so it builds a `Container` and pulls the wired services from it (keep the method signature and the `DeployCommand` return):

```php
    public static function makeCommand(): DeployCommand
    {
        global $wpdb;
        $container = new Container($wpdb);

        return new DeployCommand($container->repository(), $container->deployer(), $container->log());
    }
```

Add `use Deployward\Container;` to `Plugin.php` if a `use` block is present (it is namespaced `Deployward`, so `Container` is in the same namespace and needs no import; remove any now-unused imports such as the individual engine classes that moved to `Container`). Confirm the file still parses.

- [ ] **Step 5: Run tests to verify they pass**

Run: `"$PHP" vendor/bin/phpunit`
Expected: PASS, including the existing DeployCommandTest (unchanged behavior) and the new ContainerTest. 0 risky.

- [ ] **Step 6: Commit**

```bash
git add src/Container.php src/Plugin.php tests/Unit/ContainerTest.php
git commit -m "refactor(deployward): extract Container service factory shared by CLI and REST"
```

---

### Task 2: `GitHubClient::listBranches()`

Add branch listing so the Add-Deployment form can offer a branch picker.

**Files:**
- Modify: `src/GitHub/GitHubClient.php`, `src/GitHub/GitHubClientInterface.php`
- Test: `tests/Unit/GitHub/GitHubClientTest.php`

**Interfaces:**
- Produces: `listBranches(string $repo, ?string $token): Result` on both the class and interface. On success `data()` is a list of branch-name strings; on failure a `Result::fail` with a message (no token leak).

- [ ] **Step 1: Add the failing tests** to `tests/Unit/GitHub/GitHubClientTest.php`

```php
    public function test_list_branches_returns_names(): void
    {
        Functions\when('wp_remote_get')->justReturn(array());
        Functions\when('wp_remote_retrieve_body')->justReturn('[{"name":"main"},{"name":"develop"}]');

        $result = (new GitHubClient())->listBranches('Nara-IT/nara-core', 'ghp_x');

        $this->assertTrue($result->isOk());
        $this->assertSame(array('main', 'develop'), $result->data());
    }

    public function test_list_branches_fails_on_non_200(): void
    {
        Functions\when('wp_remote_retrieve_response_code')->justReturn(404);
        Functions\when('wp_remote_get')->justReturn(array());
        Functions\when('wp_remote_retrieve_body')->justReturn('');

        $result = (new GitHubClient())->listBranches('Nara-IT/missing', null);

        $this->assertFalse($result->isOk());
    }
```

- [ ] **Step 2: Run to verify fail**

Run: `"$PHP" vendor/bin/phpunit --filter GitHubClientTest`
Expected: FAIL ("Call to undefined method ... listBranches").

- [ ] **Step 3: Add `listBranches()` to the interface** (`src/GitHub/GitHubClientInterface.php`)

```php
    public function listBranches(string $repo, ?string $token): \Deployward\Support\Result;
```

- [ ] **Step 4: Implement in `src/GitHub/GitHubClient.php`** (uses the existing private `args()` helper)

```php
    public function listBranches(string $repo, ?string $token): Result
    {
        $url = self::API . '/repos/' . $repo . '/branches?per_page=100';
        $response = wp_remote_get($url, $this->args($token, 30));
        if (is_wp_error($response)) {
            return Result::fail('GitHub request failed: ' . $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return Result::fail('GitHub returned HTTP ' . $code . ' listing branches for ' . $repo);
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (! is_array($body)) {
            return Result::fail('GitHub branches response was not a list');
        }
        $names = array();
        foreach ($body as $branch) {
            if (isset($branch['name'])) {
                $names[] = (string) $branch['name'];
            }
        }

        return Result::ok($names);
    }
```

- [ ] **Step 5: Run to verify pass**

Run: `"$PHP" vendor/bin/phpunit --filter GitHubClientTest`
Expected: PASS (now 9 tests). Full suite still green, 0 risky.

- [ ] **Step 6: Commit**

```bash
git add src/GitHub/GitHubClient.php src/GitHub/GitHubClientInterface.php tests/Unit/GitHub/GitHubClientTest.php
git commit -m "feat(deployward): add GitHubClient::listBranches for the branch picker"
```

---

### Task 3: `ApiResponse` value object

**Files:**
- Create: `src/Http/ApiResponse.php`
- Test: `tests/Unit/Http/ApiResponseTest.php`

**Interfaces:**
- Produces: `Deployward\Http\ApiResponse` with `static ok($data = array(), int $status = 200): ApiResponse`, `static error(string $message, int $status = 400, array $extra = array()): ApiResponse`, `status(): int`, `data()` (array; for errors it is `array('error' => $message) + $extra`), `isOk(): bool` (status < 400).

- [ ] **Step 1: Failing test `tests/Unit/Http/ApiResponseTest.php`**

```php
<?php

namespace Deployward\Tests\Unit\Http;

use Deployward\Http\ApiResponse;
use PHPUnit\Framework\TestCase;

final class ApiResponseTest extends TestCase
{
    public function test_ok_defaults_to_200(): void
    {
        $response = ApiResponse::ok(array('a' => 1));

        $this->assertSame(200, $response->status());
        $this->assertSame(array('a' => 1), $response->data());
        $this->assertTrue($response->isOk());
    }

    public function test_error_carries_message_and_status(): void
    {
        $response = ApiResponse::error('bad input', 422, array('field' => 'repo'));

        $this->assertSame(422, $response->status());
        $this->assertFalse($response->isOk());
        $this->assertSame('bad input', $response->data()['error']);
        $this->assertSame('repo', $response->data()['field']);
    }
}
```

- [ ] **Step 2: Run to verify fail** — `"$PHP" vendor/bin/phpunit --filter ApiResponseTest` (class not found).

- [ ] **Step 3: Implement `src/Http/ApiResponse.php`**

```php
<?php

namespace Deployward\Http;

final class ApiResponse
{
    /** @var int */
    private $status;
    /** @var array */
    private $data;

    private function __construct(int $status, array $data)
    {
        $this->status = $status;
        $this->data = $data;
    }

    public static function ok($data = array(), int $status = 200): self
    {
        return new self($status, is_array($data) ? $data : array('data' => $data));
    }

    public static function error(string $message, int $status = 400, array $extra = array()): self
    {
        return new self($status, array_merge(array('error' => $message), $extra));
    }

    public function status(): int
    {
        return $this->status;
    }

    public function data(): array
    {
        return $this->data;
    }

    public function isOk(): bool
    {
        return $this->status < 400;
    }
}
```

- [ ] **Step 4: Run to verify pass**, then **Step 5: Commit**

```bash
git add src/Http/ApiResponse.php tests/Unit/Http/ApiResponseTest.php
git commit -m "feat(deployward): add ApiResponse value object for the REST layer"
```

---

### Task 4: `RestController` — deployments CRUD

**Files:**
- Create: `src/Http/RestController.php`
- Test: `tests/Unit/Http/RestControllerTest.php`

**Interfaces:**
- Consumes: `DeploymentRepositoryInterface`, `DeployerInterface`, `DeployLogInterface`, `GitHubClientInterface`, `Deployment`, `ApiResponse`, `Result`.
- Produces: `Deployward\Http\RestController` with `__construct(DeploymentRepositoryInterface $repository, DeployerInterface $deployer, DeployLogInterface $log, GitHubClientInterface $github)`. CRUD methods in this task: `listDeployments(): ApiResponse`, `saveDeployment(array $params): ApiResponse`, `deleteDeployment(string $id): ApiResponse`. A private `present(Deployment $d): array` returns a token-free dict: `id, repo, branch, visibility, target_type, target_slug, last_deployed_sha, has_token` (bool). `saveDeployment` upserts: with no `id` it generates one (`dw_` + 12 hex from `wp_generate_password`) and a webhook secret; with an existing `id` it preserves the stored token when the incoming `token` is empty and preserves the existing webhook secret. Validation errors from `Deployment::fromArray` map to `ApiResponse::error(..., 422)`.

- [ ] **Step 1: Failing test `tests/Unit/Http/RestControllerTest.php`** (CRUD portion)

```php
<?php

namespace Deployward\Tests\Unit\Http;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Config\Deployment;
use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\Deploy\DeployerInterface;
use Deployward\GitHub\GitHubClientInterface;
use Deployward\Http\RestController;
use Deployward\Log\DeployLogInterface;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class RestControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('wp_generate_password')->justReturn('abcdef123456');
        Functions\when('sanitize_key')->returnArg();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function deployment(string $token = 'ghp_x'): Deployment
    {
        return Deployment::fromArray(array(
            'id' => 'dw_1', 'repo' => 'Nara-IT/nara-core', 'branch' => 'main',
            'visibility' => 'private', 'target_type' => 'plugin', 'target_slug' => 'nara-core',
            'token' => $token, 'webhook_secret' => 'whsec', 'last_deployed_sha' => 'abc1234',
        ));
    }

    private function controller($repo, $deployer = null, $log = null, $github = null): RestController
    {
        return new RestController(
            $repo,
            $deployer ?: Mockery::mock(DeployerInterface::class),
            $log ?: Mockery::mock(DeployLogInterface::class),
            $github ?: Mockery::mock(GitHubClientInterface::class)
        );
    }

    public function test_list_returns_token_free_dicts(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('all')->andReturn(array('dw_1' => $this->deployment()));

        $response = $this->controller($repo)->listDeployments();

        $this->assertSame(200, $response->status());
        $row = $response->data()['deployments'][0];
        $this->assertSame('Nara-IT/nara-core', $row['repo']);
        $this->assertArrayNotHasKey('token', $row);
        $this->assertArrayNotHasKey('webhook_secret', $row);
        $this->assertTrue($row['has_token']);
    }

    public function test_save_creates_with_generated_id_and_secret(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('save')->once()->with(Mockery::type(Deployment::class));

        $response = $this->controller($repo)->saveDeployment(array(
            'repo' => 'Nara-IT/nara-core', 'branch' => 'main', 'visibility' => 'public',
            'target_type' => 'plugin', 'target_slug' => 'nara-core',
        ));

        $this->assertSame(201, $response->status());
        $this->assertArrayNotHasKey('token', $response->data()['deployment']);
    }

    public function test_save_rejects_invalid_repo_with_422(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldNotReceive('save');

        $response = $this->controller($repo)->saveDeployment(array(
            'repo' => 'not-a-repo', 'branch' => 'main', 'visibility' => 'public',
            'target_type' => 'plugin', 'target_slug' => 'nara-core',
        ));

        $this->assertSame(422, $response->status());
    }

    public function test_save_update_preserves_token_when_blank(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->with('dw_1')->andReturn($this->deployment('ghp_existing'));
        $repo->shouldReceive('save')->once()->with(Mockery::on(function ($d) {
            return $d->token() === 'ghp_existing' && $d->webhookSecret() === 'whsec';
        }));

        $response = $this->controller($repo)->saveDeployment(array(
            'id' => 'dw_1', 'repo' => 'Nara-IT/nara-core', 'branch' => 'develop',
            'visibility' => 'private', 'target_type' => 'plugin', 'target_slug' => 'nara-core',
            'token' => '',
        ));

        $this->assertSame(200, $response->status());
    }

    public function test_delete_unknown_returns_404(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->with('nope')->andReturn(null);

        $response = $this->controller($repo)->deleteDeployment('nope');

        $this->assertSame(404, $response->status());
    }
}
```

- [ ] **Step 2: Run to verify fail** — class not found.

- [ ] **Step 3: Implement the CRUD portion of `src/Http/RestController.php`**

```php
<?php

namespace Deployward\Http;

use Deployward\Config\Deployment;
use Deployward\Config\DeploymentRepositoryInterface;
use Deployward\Deploy\DeployerInterface;
use Deployward\GitHub\GitHubClientInterface;
use Deployward\Log\DeployLogInterface;

final class RestController
{
    /** @var DeploymentRepositoryInterface */
    private $repository;
    /** @var DeployerInterface */
    private $deployer;
    /** @var DeployLogInterface */
    private $log;
    /** @var GitHubClientInterface */
    private $github;

    public function __construct(
        DeploymentRepositoryInterface $repository,
        DeployerInterface $deployer,
        DeployLogInterface $log,
        GitHubClientInterface $github
    ) {
        $this->repository = $repository;
        $this->deployer = $deployer;
        $this->log = $log;
        $this->github = $github;
    }

    public function listDeployments(): ApiResponse
    {
        $rows = array();
        foreach ($this->repository->all() as $deployment) {
            $rows[] = $this->present($deployment);
        }

        return ApiResponse::ok(array('deployments' => $rows));
    }

    public function saveDeployment(array $params): ApiResponse
    {
        $id = isset($params['id']) && $params['id'] !== '' ? sanitize_key($params['id']) : '';
        $existing = $id !== '' ? $this->repository->find($id) : null;

        $token = isset($params['token']) ? (string) $params['token'] : '';
        if ($token === '' && $existing !== null) {
            $token = $existing->token();
        }
        $secret = $existing !== null ? $existing->webhookSecret() : wp_generate_password(32, false);
        if ($id === '') {
            $id = 'dw_' . substr(wp_generate_password(24, false), 0, 12);
        }

        try {
            $deployment = Deployment::fromArray(array(
                'id' => $id,
                'repo' => isset($params['repo']) ? $params['repo'] : '',
                'branch' => isset($params['branch']) ? $params['branch'] : 'main',
                'visibility' => isset($params['visibility']) ? $params['visibility'] : 'public',
                'target_type' => isset($params['target_type']) ? $params['target_type'] : 'plugin',
                'target_slug' => isset($params['target_slug']) ? $params['target_slug'] : '',
                'token' => $token,
                'webhook_secret' => $secret,
                'last_deployed_sha' => $existing !== null ? $existing->lastDeployedSha() : '',
            ));
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        $this->repository->save($deployment);

        return ApiResponse::ok(
            array('deployment' => $this->present($deployment)),
            $existing !== null ? 200 : 201
        );
    }

    public function deleteDeployment(string $id): ApiResponse
    {
        if ($this->repository->find($id) === null) {
            return ApiResponse::error('Deployment not found', 404);
        }
        $this->repository->delete($id);

        return ApiResponse::ok(array('deleted' => $id));
    }

    private function present(Deployment $deployment): array
    {
        return array(
            'id' => $deployment->id(),
            'repo' => $deployment->repo(),
            'branch' => $deployment->branch(),
            'visibility' => $deployment->visibility(),
            'target_type' => $deployment->targetType(),
            'target_slug' => $deployment->targetSlug(),
            'last_deployed_sha' => $deployment->lastDeployedSha(),
            'has_token' => $deployment->token() !== '',
        );
    }
}
```

- [ ] **Step 4: Run to verify pass** — `"$PHP" vendor/bin/phpunit --filter RestControllerTest`. Full suite green, 0 risky.

- [ ] **Step 5: Commit**

```bash
git add src/Http/RestController.php tests/Unit/Http/RestControllerTest.php
git commit -m "feat(deployward): add RestController deployments CRUD (token-free output)"
```

---

### Task 5: `RestController` — deploy / rollback / log / branches

**Files:**
- Modify: `src/Http/RestController.php`
- Test: `tests/Unit/Http/RestControllerTest.php`

**Interfaces:**
- Produces (added to `RestController`): `deployNow(string $id, bool $force): ApiResponse`, `rollback(string $id): ApiResponse`, `deploymentLog(string $id, int $page, int $perPage = 20): ApiResponse`, `branches(array $params): ApiResponse`. Mapping: missing deployment -> 404; `Result::isOk()` deploy/rollback -> 200 with `{status, message, sha?}`; failed `Result` -> 502 with the message; `branches` success -> 200 `{branches: [...]}`, failure -> 502 with the message (never the token).

- [ ] **Step 1: Add failing tests** to `RestControllerTest`

```php
    public function test_deploy_now_maps_ok_result_to_200(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->with('dw_1')->andReturn($this->deployment());
        $deployer = Mockery::mock(DeployerInterface::class);
        $deployer->shouldReceive('deploy')->once()
            ->with(Mockery::type(Deployment::class), 'manual', false)
            ->andReturn(\Deployward\Support\Result::ok('newsha', 'Deployed newsha'));

        $response = $this->controller($repo, $deployer)->deployNow('dw_1', false);

        $this->assertSame(200, $response->status());
        $this->assertSame('Deployed newsha', $response->data()['message']);
    }

    public function test_deploy_now_maps_failed_result_to_502(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->with('dw_1')->andReturn($this->deployment());
        $deployer = Mockery::mock(DeployerInterface::class);
        $deployer->shouldReceive('deploy')->andReturn(\Deployward\Support\Result::fail('boom'));

        $response = $this->controller($repo, $deployer)->deployNow('dw_1', false);

        $this->assertSame(502, $response->status());
    }

    public function test_deploy_now_unknown_id_404(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->with('nope')->andReturn(null);

        $this->assertSame(404, $this->controller($repo)->deployNow('nope', false)->status());
    }

    public function test_log_returns_entries(): void
    {
        $repo = Mockery::mock(DeploymentRepositoryInterface::class);
        $repo->shouldReceive('find')->with('dw_1')->andReturn($this->deployment());
        $log = Mockery::mock(DeployLogInterface::class);
        $log->shouldReceive('recent')->with('dw_1', 20, 0)->andReturn(array(array('id' => 1, 'status' => 'success')));

        $response = $this->controller($repo, null, $log)->deploymentLog('dw_1', 1, 20);

        $this->assertSame(200, $response->status());
        $this->assertSame('success', $response->data()['entries'][0]['status']);
    }

    public function test_branches_success(): void
    {
        $github = Mockery::mock(GitHubClientInterface::class);
        $github->shouldReceive('listBranches')->andReturn(\Deployward\Support\Result::ok(array('main', 'dev')));

        $response = $this->controller(Mockery::mock(DeploymentRepositoryInterface::class), null, null, $github)
            ->branches(array('repo' => 'Nara-IT/nara-core', 'visibility' => 'public'));

        $this->assertSame(200, $response->status());
        $this->assertSame(array('main', 'dev'), $response->data()['branches']);
    }

    public function test_branches_failure_502_without_token_leak(): void
    {
        $github = Mockery::mock(GitHubClientInterface::class);
        $github->shouldReceive('listBranches')->andReturn(\Deployward\Support\Result::fail('GitHub returned HTTP 401'));

        $response = $this->controller(Mockery::mock(DeploymentRepositoryInterface::class), null, null, $github)
            ->branches(array('repo' => 'Nara-IT/p', 'visibility' => 'private', 'token' => 'ghp_secret'));

        $this->assertSame(502, $response->status());
        $this->assertStringNotContainsString('ghp_secret', wp_json_encode_safe($response->data()));
    }
```

Add this tiny helper at the bottom of the test file (outside the class) so the leak assertion does not depend on WordPress:

```php
namespace Deployward\Tests\Unit\Http;

if (! function_exists('Deployward\\Tests\\Unit\\Http\\wp_json_encode_safe')) {
    function wp_json_encode_safe($data): string
    {
        return json_encode($data);
    }
}
```

- [ ] **Step 2: Run to verify fail** — undefined methods.

- [ ] **Step 3: Add the methods to `src/Http/RestController.php`**

```php
    public function deployNow(string $id, bool $force): ApiResponse
    {
        $deployment = $this->repository->find($id);
        if ($deployment === null) {
            return ApiResponse::error('Deployment not found', 404);
        }
        $result = $this->deployer->deploy($deployment, 'manual', $force);

        return $this->fromResult($result);
    }

    public function rollback(string $id): ApiResponse
    {
        $deployment = $this->repository->find($id);
        if ($deployment === null) {
            return ApiResponse::error('Deployment not found', 404);
        }
        $result = $this->deployer->rollback($deployment, 'manual-rollback');

        return $this->fromResult($result);
    }

    public function deploymentLog(string $id, int $page, int $perPage = 20): ApiResponse
    {
        if ($this->repository->find($id) === null) {
            return ApiResponse::error('Deployment not found', 404);
        }
        $offset = max(0, ($page - 1)) * $perPage;
        $entries = $this->log->recent($id, $perPage, $offset);

        return ApiResponse::ok(array('entries' => $entries, 'page' => $page));
    }

    public function branches(array $params): ApiResponse
    {
        $repo = isset($params['repo']) ? (string) $params['repo'] : '';
        $visibility = isset($params['visibility']) ? (string) $params['visibility'] : 'public';
        $token = ($visibility === 'private' && isset($params['token'])) ? (string) $params['token'] : null;
        if ($token === '' ) {
            $token = null;
        }
        $result = $this->github->listBranches($repo, $token);
        if (! $result->isOk()) {
            return ApiResponse::error($result->message(), 502);
        }

        return ApiResponse::ok(array('branches' => $result->data()));
    }

    private function fromResult(\Deployward\Support\Result $result): ApiResponse
    {
        $status = $result->isOk() ? ($result->isSkipped() ? 'skipped' : 'success') : 'failed';
        if (! $result->isOk()) {
            return ApiResponse::error($result->message(), 502, array('status' => 'failed'));
        }
        $data = array('status' => $status, 'message' => $result->message());
        if (! $result->isSkipped() && is_string($result->data())) {
            $data['sha'] = $result->data();
        }

        return ApiResponse::ok($data);
    }
```

- [ ] **Step 4: Run to verify pass**; full suite green, 0 risky.

- [ ] **Step 5: Commit**

```bash
git add src/Http/RestController.php tests/Unit/Http/RestControllerTest.php
git commit -m "feat(deployward): add deploy/rollback/log/branches REST handlers"
```

---

### Task 6: REST route registrar + admin page (WP glue)

Wire the controller into WordPress: register the routes with capability + sanitization, register the menu, render the app container, enqueue assets, and localize the REST settings. This task is mostly WP integration; unit-test what is unit-testable (the permission callback and the `ApiResponse` adapter), verify the rest in the browser in Task 9.

**Files:**
- Create: `src/Http/RestRoutes.php`
- Create: `src/Admin/AdminPage.php`
- Modify: `src/Plugin.php` (register on `rest_api_init`, `admin_menu`, `admin_enqueue_scripts`)
- Test: `tests/Unit/Http/RestRoutesTest.php`

**Interfaces:**
- Produces: `Deployward\Http\RestRoutes` with `__construct(RestController $controller)`, `register(): void` (calls `register_rest_route` for each endpoint), `static canManage(): bool` (returns `current_user_can('manage_options')`, the shared `permission_callback`), and `respond(ApiResponse $response)` returning a `WP_REST_Response`. `Deployward\Admin\AdminPage` with `register(): void` (adds the menu), `render(): void` (echoes the container div with the nonce/REST root as data attributes), `enqueue(string $hook): void` (enqueues `assets/admin.css` + `assets/admin.js` and localizes `window.DeploywardSettings = { root, nonce }` only on the Deployward page).

- [ ] **Step 1: Failing test `tests/Unit/Http/RestRoutesTest.php`** (the unit-testable seams)

```php
<?php

namespace Deployward\Tests\Unit\Http;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Http\ApiResponse;
use Deployward\Http\RestController;
use Deployward\Http\RestRoutes;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class RestRoutesTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        if (! class_exists('WP_REST_Response')) {
            eval('class WP_REST_Response { public $data; public $status; public function __construct($d = null, $s = 200) { $this->data = $d; $this->status = $s; } }');
        }
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_can_manage_reflects_capability(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        $this->assertFalse(RestRoutes::canManage());

        Functions\when('current_user_can')->justReturn(true);
        $this->assertTrue(RestRoutes::canManage());
    }

    public function test_respond_maps_apiresponse_to_wp_rest_response(): void
    {
        $routes = new RestRoutes(Mockery::mock(RestController::class));
        $wp = $routes->respond(ApiResponse::error('nope', 404));

        $this->assertSame(404, $wp->status);
        $this->assertSame('nope', $wp->data['error']);
    }
}
```

- [ ] **Step 2: Run to verify fail** — class not found.

- [ ] **Step 3: Implement `src/Http/RestRoutes.php`**

```php
<?php

namespace Deployward\Http;

final class RestRoutes
{
    const NAMESPACE = 'deployward/v1';

    /** @var RestController */
    private $controller;

    public function __construct(RestController $controller)
    {
        $this->controller = $controller;
    }

    public static function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    public function respond(ApiResponse $response): \WP_REST_Response
    {
        return new \WP_REST_Response($response->data(), $response->status());
    }

    public function register(): void
    {
        $permission = array(__CLASS__, 'canManage');

        register_rest_route(self::NAMESPACE, '/deployments', array(
            array(
                'methods' => 'GET',
                'permission_callback' => $permission,
                'callback' => function () {
                    return $this->respond($this->controller->listDeployments());
                },
            ),
            array(
                'methods' => 'POST',
                'permission_callback' => $permission,
                'callback' => function ($request) {
                    return $this->respond($this->controller->saveDeployment($this->params($request)));
                },
            ),
        ));

        register_rest_route(self::NAMESPACE, '/deployments/(?P<id>[a-zA-Z0-9_-]+)', array(
            array(
                'methods' => 'DELETE',
                'permission_callback' => $permission,
                'callback' => function ($request) {
                    return $this->respond($this->controller->deleteDeployment(sanitize_key($request['id'])));
                },
            ),
        ));

        register_rest_route(self::NAMESPACE, '/deployments/(?P<id>[a-zA-Z0-9_-]+)/deploy', array(
            'methods' => 'POST',
            'permission_callback' => $permission,
            'callback' => function ($request) {
                $force = (bool) $request->get_param('force');
                return $this->respond($this->controller->deployNow(sanitize_key($request['id']), $force));
            },
        ));

        register_rest_route(self::NAMESPACE, '/deployments/(?P<id>[a-zA-Z0-9_-]+)/rollback', array(
            'methods' => 'POST',
            'permission_callback' => $permission,
            'callback' => function ($request) {
                return $this->respond($this->controller->rollback(sanitize_key($request['id'])));
            },
        ));

        register_rest_route(self::NAMESPACE, '/deployments/(?P<id>[a-zA-Z0-9_-]+)/log', array(
            'methods' => 'GET',
            'permission_callback' => $permission,
            'callback' => function ($request) {
                $page = max(1, (int) $request->get_param('page'));
                return $this->respond($this->controller->deploymentLog(sanitize_key($request['id']), $page));
            },
        ));

        register_rest_route(self::NAMESPACE, '/branches', array(
            'methods' => 'POST',
            'permission_callback' => $permission,
            'callback' => function ($request) {
                return $this->respond($this->controller->branches($this->params($request)));
            },
        ));
    }

    private function params($request): array
    {
        $raw = $request->get_json_params();
        if (! is_array($raw)) {
            $raw = $request->get_params();
        }
        $clean = array();
        foreach ((array) $raw as $key => $value) {
            $clean[sanitize_key($key)] = is_string($value) ? sanitize_text_field($value) : $value;
        }

        return $clean;
    }
}
```

Note: the token arrives via `sanitize_text_field`, which preserves GitHub fine-grained PAT characters (alphanumeric and underscore). The route regex restricts ids to `[a-zA-Z0-9_-]`.

- [ ] **Step 4: Implement `src/Admin/AdminPage.php`**

```php
<?php

namespace Deployward\Admin;

final class AdminPage
{
    const SLUG = 'deployward';
    const HOOK_SUFFIX = 'toplevel_page_deployward';

    public function register(): void
    {
        add_menu_page(
            'Deployward',
            'Deployward',
            'manage_options',
            self::SLUG,
            array($this, 'render'),
            'dashicons-update',
            81
        );
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        echo '<div class="wrap"><div id="deployward-app" '
            . 'data-root="' . esc_attr(esc_url_raw(rest_url('deployward/v1/'))) . '" '
            . 'data-nonce="' . esc_attr(wp_create_nonce('wp_rest')) . '">'
            . '<noscript>Deployward requires JavaScript.</noscript>'
            . '</div></div>';
    }

    public function enqueue(string $hook): void
    {
        if ($hook !== self::HOOK_SUFFIX) {
            return;
        }
        $base = plugin_dir_url(DEPLOYWARD_FILE);
        wp_enqueue_style('deployward-admin', $base . 'assets/admin.css', array(), DEPLOYWARD_VERSION);
        wp_enqueue_script('deployward-admin', $base . 'assets/admin.js', array(), DEPLOYWARD_VERSION, true);
    }
}
```

- [ ] **Step 5: Wire into `src/Plugin.php` `boot()`**

Add, alongside the existing WP-CLI registration, the admin + REST hooks. `boot()` becomes:

```php
    public static function boot(): void
    {
        global $wpdb;
        $container = new Container($wpdb);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('deployward', self::makeCommand());
        }

        $routes = new RestRoutes(new RestController(
            $container->repository(),
            $container->deployer(),
            $container->log(),
            $container->github()
        ));
        add_action('rest_api_init', array($routes, 'register'));

        $page = new AdminPage();
        add_action('admin_menu', array($page, 'register'));
        add_action('admin_enqueue_scripts', array($page, 'enqueue'));
    }
```

Add the imports to `Plugin.php`: `use Deployward\Http\RestController; use Deployward\Http\RestRoutes; use Deployward\Admin\AdminPage;` (Container is same-namespace).

- [ ] **Step 6: Run tests** — `"$PHP" vendor/bin/phpunit`. RestRoutesTest passes; full suite green, 0 risky.

- [ ] **Step 7: Commit**

```bash
git add src/Http/RestRoutes.php src/Admin/AdminPage.php src/Plugin.php tests/Unit/Http/RestRoutesTest.php
git commit -m "feat(deployward): register admin-only REST routes and the admin page"
```

---

### Task 7: `assets/admin.css` (modern design)

A presentation asset, verified in the browser (Task 9). Build it to the Design Language section above.

**Files:**
- Create: `assets/admin.css`

- [ ] **Step 1: Write `assets/admin.css`** implementing, all scoped under `#deployward-app`:
  - The token custom properties (light set on `#deployward-app`, dark set under `@media (prefers-color-scheme: dark)`).
  - System font stack; base text/muted colors; comfortable line-height.
  - A sticky tab bar (`.dw-tabs`) with an active-tab underline in `--dw-accent` and `:focus-visible` rings.
  - Cards (`.dw-card`) with `--dw-radius`, `--dw-border`, `--dw-shadow`, and internal padding.
  - A deployments grid/table (`.dw-deployments`) of cards: repo title, `repo@branch -> type/slug` meta line, a status pill, last-deploy time, and an action row.
  - Status pills (`.dw-pill.is-ok|is-failed|is-deploying|is-never`) with the green/red/amber/muted tokens; `.is-deploying` shows an animated three-dot pulse.
  - Buttons: `.dw-btn`, `.dw-btn--primary` (accent fill), `.dw-btn--ghost` (outline), `.dw-btn--danger`; disabled state; an inline spinner (`.dw-spin`) using a CSS `@keyframes` rotation.
  - The Add-Deployment form: labeled fields, a segmented public/private control, a token field that is revealed only for private, a branch row with a "Fetch branches" button and a `<select>`/text fallback, helper text styling (`.dw-help`), inline error styling (`.dw-error`).
  - A toast/notice area (`.dw-toast.is-ok|is-error`) for action results.
  - The Getting Started panel: numbered step cards for the public flow and the private flow.
  - Responsive: single column under ~782px (WP's mobile admin breakpoint).

- [ ] **Step 2: Verify it loads without console errors** (visual verification happens in Task 9, but confirm valid CSS by loading the page): defer the live check to Task 9.

- [ ] **Step 3: Commit**

```bash
git add assets/admin.css
git commit -m "feat(deployward): modern scoped admin stylesheet"
```

---

### Task 8: `assets/admin.js` (vanilla SPA)

A presentation asset, verified in the browser (Task 9). No framework, no build. Plain ES6, enqueued as a static file. Reads `#deployward-app[data-root][data-nonce]`.

**Files:**
- Create: `assets/admin.js`

- [ ] **Step 1: Write `assets/admin.js`** implementing exactly this behavior:
  - On `DOMContentLoaded`, read `root` and `nonce` from the container's data attributes. Define `api(method, path, body)` using `fetch(root + path, { method, headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined })`, returning `{ status, data }`; never log the token.
  - Render a tab bar with four tabs: `Deployments`, `Add Deployment`, `Activity Log`, `Getting Started`. Switching tabs swaps the rendered panel with no page reload and updates `location.hash` (`#deployments`, etc.) so tabs are linkable; honor the hash on load.
  - **Deployments tab:** `GET deployments`; render one card per deployment with the status pill (derive `is-never` when `last_deployed_sha` is empty, else `is-ok`; `is-deploying` while an action is in flight), the `repo@branch -> type/slug` meta, and buttons: **Deploy now**, **Rollback**, **View log** (switches to Activity Log for that id), **Edit** (switches to Add Deployment prefilled), **Delete** (confirm dialog). Deploy/Rollback call `POST .../deploy` or `.../rollback`, show the button spinner, then a toast with the returned message and refresh the list. Delete calls `DELETE` after a typed/confirmed prompt.
  - **Add Deployment tab:** a form with repo, a public/private segmented control, a token field shown only when private (placeholder "Leave blank to keep the saved token" in edit mode), a branch row with a "Fetch branches" button (calls `POST branches` with `{repo, visibility, token}` and fills a `<select>`; on failure show the message and reveal a plain text branch input), target type (`plugin`/`theme`/`mu-plugin`) and target slug. Client-side: block submit if `target_type === 'plugin' && target_slug === 'deployward'` with an inline error. Submit calls `POST deployments`; on 422 show the server error inline; on 200/201 toast success and switch to Deployments.
  - **Activity Log tab:** a deployment selector; on choose, `GET deployments/{id}/log?page=N` and render rows (time, status pill, short sha, message) with simple Prev/Next paging.
  - **Getting Started tab:** static, accessible step cards. Public flow: 1) Add Deployment, 2) paste the public repo URL, 3) pick branch, 4) choose target plugin/theme + slug, 5) Save and click Deploy now. Private flow: same, plus a step "Create a GitHub fine-grained token (Settings -> Developer settings -> Fine-grained tokens) with Contents: Read-only on this repository, and paste it in the Token field; it is stored encrypted." End with a one-line note that automatic deploy-on-push arrives in the next release (the triggers module).
  - All DOM built with `document.createElement` / `textContent` (no `innerHTML` with interpolated server data) to avoid XSS; the nonce and token are never written into the DOM or console.
  - Graceful errors: any non-2xx shows a `.dw-toast.is-error` with `data.error`.

- [ ] **Step 2: Verify** in Task 9 (live browser). Defer here.

- [ ] **Step 3: Commit**

```bash
git add assets/admin.js
git commit -m "feat(deployward): vanilla-JS tabbed admin SPA"
```

---

### Task 9: Live verification on nara.local (browser) with a test plugin

The PHP is unit-tested; this task proves the whole UI works end to end against a REAL but DISPOSABLE target. NEVER target a real in-use plugin (no nara-core, no theme in use). Use a throwaway slug.

**Files:** none (verification + any fixes it surfaces).

- [ ] **Step 1: Activate and smoke-test the page**
  - `./wp-cli.phar plugin activate deployward` (from the WP root).
  - Open `https://nara.local/wp-admin/admin.php?page=deployward`. Confirm the tabbed UI renders with no console errors and the REST calls return 200 (Network tab). Take a screenshot of each tab.

- [ ] **Step 2: Public-repo deploy of a disposable test plugin**
  - In Add Deployment, enter the provided PUBLIC test repo, visibility Public, fetch branches, choose `main`, target type `plugin`, target slug `deployward-test` (a slug that does NOT exist yet, so the first deploy is a clean install with no prior version). Save.
  - Click Deploy now. Confirm: success toast, `wp-content/plugins/deployward-test/` now contains the repo files, an Activity Log `success` row appears, and a backup is created on a second deploy.
  - Click Rollback after a second deploy; confirm the previous version is restored.

- [ ] **Step 3: Private-repo deploy (if a private test repo + token were provided)**
  - Repeat with visibility Private and the fine-grained PAT into a slug like `deployward-test-private`. Confirm the token round-trips (deploy succeeds) and that the token never appears in any REST response, the DOM, page source, or console.

- [ ] **Step 4: Safety checks**
  - Confirm a malformed repo shows the 422 inline error.
  - Confirm trying target slug `deployward` (plugin) is blocked client-side and, if forced via the API, refused server-side.
  - Confirm a deploy of a repo that is not a valid plugin/theme is refused by the validator (no partial swap).

- [ ] **Step 5: Clean up the test artifacts**
  - Delete the `deployward-test*` deployment(s) in the UI and remove the `wp-content/plugins/deployward-test*` directories. These are disposable; nothing of value is lost.

- [ ] **Step 6: Record the verification** in a short note (commit a `docs/superpowers/verification-admin-ui.md` capturing what was tested, screenshots referenced, and the result). Commit any fixes the verification surfaced as their own `fix(deployward): ...` commits.

---

## Self-Review

**1. Spec coverage (Plan 3 / spec section 9 Admin UX + the user's onboarding requirement):**
- Admin menu + list with per-row Deploy now / Rollback / View log / Edit / Delete: Task 6 (page) + Task 8 (list rendering + actions). Covered.
- Add-Deployment wizard with public/private onboarding, branch auto-fetch, token entry, exact-effort flow: Tasks 5 (branches), 8 (form), and the Getting Started tab. Covered. (The "exact webhook URL to paste into GitHub" is deferred to Plan 2 by decision; Getting Started notes auto-deploy is next-release. Documented gap, intentional.)
- Inline errors, no silent failures: Task 4/5 (422/404/502 mapping) + Task 8 (toasts/inline errors). Covered.
- Encrypted token never exposed: `present()` strips it (Task 4); REST never returns it; JS never writes it (Task 8); leak test (Task 5). Covered.
- Capability + nonce on every action: `canManage` permission_callback on all routes (Task 6) + `X-WP-Nonce` from the JS (Task 8). Covered.
- Manual deploy/rollback via the engine: Task 5. Covered.

**2. Placeholder scan:** PHP steps contain complete code. The CSS/JS tasks are deliberately spec-driven (no JS unit harness by design); their behavior is enumerated concretely (every screen, endpoint, state, and the XSS/token rules) and accepted via the Task 9 browser verification, which is the honest verification path for presentation assets in this project. This is a deliberate, stated choice, not a "TODO".

**3. Type consistency:** `ApiResponse::ok/error/status/data/isOk` are used consistently across Tasks 3-6. `RestController` constructor (repository, deployer, log, github) matches the `Container` getters (Task 1) and the `Plugin::boot` wiring (Task 6). `listBranches(repo, ?token): Result` is consistent between the interface, the implementation (Task 2), and the controller call (Task 5). Route ids are constrained to `[a-zA-Z0-9_-]` and `sanitize_key`'d, matching the `dw_`-prefixed ids generated in `saveDeployment` (Task 4).

Identified intentional gap (not a missing task): the webhook/auto-on-push onboarding and the background (non-blocking) deploy runner are Plan 2, by decision; the manual deploy runs synchronously in the REST request, acceptable for a human-triggered action and noted in the spec.
