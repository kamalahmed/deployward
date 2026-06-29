# Deployward v1: Foundations + Safe Deploy Engine + WP-CLI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a usable, safe deploy engine for the Deployward plugin: configure a GitHub deployment with an encrypted token and run a safe deploy (resolve commit, download, validate, atomic swap, health check, auto-rollback, versioned backups, audit log, email) driven by WP-CLI.

**Architecture:** A per-site WordPress plugin with a small set of focused, dependency-injected classes under a `Deployward\` namespace. A composition root (`Plugin`) wires collaborators using real WordPress paths; the `Deployer` orchestrates a safe-swap pipeline against injected collaborators so every unit is testable in isolation. Triggers (webhook/cron) and the admin UI are deliberately deferred to later plans; this plan exposes the engine via WP-CLI.

**Tech Stack:** PHP 7.4, WordPress 6.0+, libsodium (PHP core) for encryption, WordPress HTTP API for GitHub calls, WordPress `unzip_file`/`WP_Filesystem` for extraction, PHPUnit 9 + Brain Monkey + Mockery for tests.

## Global Constraints

These apply to every task. Copied verbatim from the spec.

- PHP 7.4 or newer floor. Do NOT use PHP 8.0+ syntax (no constructor property promotion, no union/`mixed` types, no `match`, no enums, no named arguments, no nullsafe `?->`).
- WordPress 6.0 or newer.
- GitHub only (public and private repos). No other providers in this plan.
- Files under 400 lines, functions under 50 lines, no nesting deeper than 4 levels.
- Immutability: build new arrays/objects, never mutate shared state.
- Sanitize all input at the boundary; escape all output at the echo point; all DB access through `$wpdb->prepare()`.
- Nonce plus capability checks on every state-changing handler (no admin/HTTP handlers in this plan; applies to later plans).
- Secrets are never stored in plaintext and never appear in a DB export.
- Deployward refuses to deploy or overwrite itself (`plugin` target with slug `deployward`).
- Author: Kamal Ahmed. Repo: `wp-content/plugins/deployward` (separate from nara-core). No GitHub remote is created unless the user asks.
- No em-dashes anywhere (code, commits, docs).
- TDD: write the failing test first. Target at least 80% coverage on new code. The tests shown per task are the core behaviors; expand each task's tests to reach coverage before its commit.

---

### Task 1: Project scaffolding, autoloader, Result value object, test harness

**Files:**
- Create: `deployward.php`
- Create: `src/Autoloader.php`
- Create: `src/Support/Result.php`
- Create: `composer.json`
- Create: `phpunit.xml.dist`
- Create: `tests/bootstrap.php`
- Test: `tests/Unit/Support/ResultTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `Deployward\Autoloader::register(string $baseDir): void`
  - `Deployward\Support\Result` with: `Result::ok($data = null, string $message = ''): Result`, `Result::fail(string $message, $data = null): Result`, `Result::skip(string $message, $data = null): Result`, `isOk(): bool`, `isSkipped(): bool`, `message(): string`, `data()` (returns mixed).

- [ ] **Step 1: Write `composer.json`**

```json
{
    "name": "kamal-ahmed/deployward",
    "description": "Safely auto-deploys plugins and themes from GitHub into WordPress.",
    "type": "wordpress-plugin",
    "license": "GPL-2.0-or-later",
    "authors": [{ "name": "Kamal Ahmed" }],
    "require": { "php": ">=7.4" },
    "require-dev": {
        "phpunit/phpunit": "^9.6",
        "brain/monkey": "^2.6",
        "mockery/mockery": "^1.6"
    },
    "autoload": { "psr-4": { "Deployward\\": "src/" } },
    "autoload-dev": { "psr-4": { "Deployward\\Tests\\": "tests/" } },
    "config": { "sort-packages": true }
}
```

- [ ] **Step 2: Write `phpunit.xml.dist`**

```xml
<?xml version="1.0"?>
<phpunit bootstrap="tests/bootstrap.php" colors="true" cacheResultFile=".phpunit.result.cache">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 3: Write `tests/bootstrap.php`**

```php
<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';
```

- [ ] **Step 4: Write `src/Autoloader.php`**

```php
<?php

namespace Deployward;

final class Autoloader
{
    public static function register(string $baseDir): void
    {
        $root = rtrim($baseDir, '/');
        spl_autoload_register(function ($class) use ($root) {
            $prefix = 'Deployward\\';
            if (strpos($class, $prefix) !== 0) {
                return;
            }
            $relative = substr($class, strlen($prefix));
            $path = $root . '/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($path)) {
                require $path;
            }
        });
    }
}
```

- [ ] **Step 5: Write the failing test `tests/Unit/Support/ResultTest.php`**

```php
<?php

namespace Deployward\Tests\Unit\Support;

use Deployward\Support\Result;
use PHPUnit\Framework\TestCase;

final class ResultTest extends TestCase
{
    public function test_ok_is_successful_and_not_skipped(): void
    {
        $result = Result::ok('payload', 'done');

        $this->assertTrue($result->isOk());
        $this->assertFalse($result->isSkipped());
        $this->assertSame('payload', $result->data());
        $this->assertSame('done', $result->message());
    }

    public function test_fail_is_not_successful(): void
    {
        $result = Result::fail('boom');

        $this->assertFalse($result->isOk());
        $this->assertSame('boom', $result->message());
    }

    public function test_skip_is_successful_but_skipped(): void
    {
        $result = Result::skip('nothing to do');

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->isSkipped());
    }
}
```

- [ ] **Step 6: Run test to verify it fails**

Run: `composer install && vendor/bin/phpunit --filter ResultTest`
Expected: FAIL with "Class Deployward\Support\Result not found".

- [ ] **Step 7: Write `src/Support/Result.php`**

```php
<?php

namespace Deployward\Support;

final class Result
{
    /** @var bool */
    private $ok;
    /** @var bool */
    private $skipped;
    /** @var string */
    private $message;
    /** @var mixed */
    private $data;

    private function __construct(bool $ok, bool $skipped, string $message, $data)
    {
        $this->ok = $ok;
        $this->skipped = $skipped;
        $this->message = $message;
        $this->data = $data;
    }

    public static function ok($data = null, string $message = ''): self
    {
        return new self(true, false, $message, $data);
    }

    public static function fail(string $message, $data = null): self
    {
        return new self(false, false, $message, $data);
    }

    public static function skip(string $message, $data = null): self
    {
        return new self(true, true, $message, $data);
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    public function isSkipped(): bool
    {
        return $this->skipped;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function data()
    {
        return $this->data;
    }
}
```

- [ ] **Step 8: Write `deployward.php`**

```php
<?php
/**
 * Plugin Name: Deployward
 * Description: Safely auto-deploys selected plugins and themes from GitHub, with validation, atomic swap, health check, and auto-rollback.
 * Version:     0.1.0
 * Author:      Kamal Ahmed
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * License:     GPL-2.0-or-later
 * Text Domain: deployward
 */

if (! defined('ABSPATH')) {
    exit;
}

define('DEPLOYWARD_VERSION', '0.1.0');
define('DEPLOYWARD_FILE', __FILE__);
define('DEPLOYWARD_PATH', plugin_dir_path(__FILE__));
define('DEPLOYWARD_SLUG', 'deployward');

require_once DEPLOYWARD_PATH . 'src/Autoloader.php';
\Deployward\Autoloader::register(DEPLOYWARD_PATH . 'src');

register_activation_hook(__FILE__, array('\\Deployward\\Plugin', 'activate'));
add_action('plugins_loaded', array('\\Deployward\\Plugin', 'boot'));
```

Note: `\Deployward\Plugin` is created in Task 12. Until then the plugin file is not activated in WordPress; tests do not load it. This is expected.

- [ ] **Step 9: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter ResultTest`
Expected: PASS (3 tests).

- [ ] **Step 10: Commit**

```bash
git add composer.json composer.lock phpunit.xml.dist tests/bootstrap.php src/Autoloader.php src/Support/Result.php deployward.php tests/Unit/Support/ResultTest.php
git commit -m "feat(deployward): scaffold plugin, autoloader, Result, test harness"
```

---

### Task 2: Encryptor (secrets at rest)

**Files:**
- Create: `src/Security/Encryptor.php`
- Test: `tests/Unit/Security/EncryptorTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `Deployward\Security\Encryptor` with `__construct(string $key)` (key must be `SODIUM_CRYPTO_SECRETBOX_KEYBYTES` bytes, else `\InvalidArgumentException`), `static fromSalts(): Encryptor` (derives key from `AUTH_KEY . AUTH_SALT`), `encrypt(string $plaintext): string` (returns base64), `decrypt(string $ciphertext): string` (throws `\RuntimeException` on tamper/format error).

- [ ] **Step 1: Write the failing test `tests/Unit/Security/EncryptorTest.php`**

```php
<?php

namespace Deployward\Tests\Unit\Security;

use Deployward\Security\Encryptor;
use PHPUnit\Framework\TestCase;

final class EncryptorTest extends TestCase
{
    private function encryptor(): Encryptor
    {
        return new Encryptor(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public function test_round_trips_a_secret(): void
    {
        $enc = $this->encryptor();
        $cipher = $enc->encrypt('ghp_secret_token');

        $this->assertNotSame('ghp_secret_token', $cipher);
        $this->assertSame('ghp_secret_token', $enc->decrypt($cipher));
    }

    public function test_two_encryptions_differ_due_to_nonce(): void
    {
        $enc = $this->encryptor();

        $this->assertNotSame($enc->encrypt('same'), $enc->encrypt('same'));
    }

    public function test_tampered_ciphertext_throws(): void
    {
        $enc = $this->encryptor();
        $this->expectException(\RuntimeException::class);

        $enc->decrypt(base64_encode('not-a-valid-box'));
    }

    public function test_wrong_key_size_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Encryptor('too-short');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter EncryptorTest`
Expected: FAIL with "Class Deployward\Security\Encryptor not found".

- [ ] **Step 3: Write `src/Security/Encryptor.php`**

```php
<?php

namespace Deployward\Security;

final class Encryptor
{
    /** @var string */
    private $key;

    public function __construct(string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException(
                'Encryptor key must be ' . SODIUM_CRYPTO_SECRETBOX_KEYBYTES . ' bytes.'
            );
        }
        $this->key = $key;
    }

    public static function fromSalts(): self
    {
        $material = (defined('AUTH_KEY') ? AUTH_KEY : '') . (defined('AUTH_SALT') ? AUTH_SALT : '');
        if ($material === '') {
            throw new \RuntimeException('WordPress salts are not defined.');
        }
        $key = sodium_crypto_generichash($material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

        return new self($key);
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce . $cipher);
    }

    public function decrypt(string $ciphertext): string
    {
        $decoded = base64_decode($ciphertext, true);
        if ($decoded === false || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Invalid ciphertext.');
        }
        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
        if ($plain === false) {
            throw new \RuntimeException('Decryption failed.');
        }

        return $plain;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter EncryptorTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Security/Encryptor.php tests/Unit/Security/EncryptorTest.php
git commit -m "feat(deployward): add salts-derived Encryptor for secrets at rest"
```

---

### Task 3: Deployment value object

**Files:**
- Create: `src/Config/Deployment.php`
- Test: `tests/Unit/Config/DeploymentTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `Deployward\Config\Deployment` with:
  - `static fromArray(array $data): Deployment` (throws `\InvalidArgumentException` on invalid input). Accepted keys: `id`, `repo`, `branch`, `visibility`, `target_type`, `target_slug`, `token` (optional), `webhook_secret` (optional), `last_deployed_sha` (optional).
  - `toArray(): array` (keys above; includes `token`, `webhook_secret`, `last_deployed_sha`).
  - getters: `id(): string`, `repo(): string`, `branch(): string`, `visibility(): string`, `targetType(): string`, `targetSlug(): string`, `token(): string`, `webhookSecret(): string`, `lastDeployedSha(): string`.
  - `withLastDeployedSha(string $sha): Deployment`, `withToken(string $token): Deployment` (both return new instances).
  - constants `Deployment::TYPES` = `['plugin','theme','mu-plugin']`, `Deployment::VISIBILITIES` = `['public','private']`.

- [ ] **Step 1: Write the failing test `tests/Unit/Config/DeploymentTest.php`**

```php
<?php

namespace Deployward\Tests\Unit\Config;

use Deployward\Config\Deployment;
use PHPUnit\Framework\TestCase;

final class DeploymentTest extends TestCase
{
    private function validData(array $overrides = array()): array
    {
        return array_merge(array(
            'id' => 'abc123',
            'repo' => 'Nara-IT/nara-core',
            'branch' => 'main',
            'visibility' => 'private',
            'target_type' => 'plugin',
            'target_slug' => 'nara-core',
            'token' => 'ghp_x',
            'webhook_secret' => 'whsec',
            'last_deployed_sha' => '',
        ), $overrides);
    }

    public function test_builds_from_valid_array(): void
    {
        $deployment = Deployment::fromArray($this->validData());

        $this->assertSame('Nara-IT/nara-core', $deployment->repo());
        $this->assertSame('plugin', $deployment->targetType());
        $this->assertSame('nara-core', $deployment->targetSlug());
        $this->assertSame('ghp_x', $deployment->token());
    }

    public function test_rejects_bad_repo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Deployment::fromArray($this->validData(array('repo' => 'not-a-repo')));
    }

    public function test_rejects_unknown_target_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Deployment::fromArray($this->validData(array('target_type' => 'mustache')));
    }

    public function test_with_last_deployed_sha_returns_new_instance(): void
    {
        $deployment = Deployment::fromArray($this->validData());
        $next = $deployment->withLastDeployedSha('deadbeef');

        $this->assertSame('', $deployment->lastDeployedSha());
        $this->assertSame('deadbeef', $next->lastDeployedSha());
        $this->assertNotSame($deployment, $next);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter DeploymentTest`
Expected: FAIL with "Class Deployward\Config\Deployment not found".

- [ ] **Step 3: Write `src/Config/Deployment.php`**

```php
<?php

namespace Deployward\Config;

final class Deployment
{
    const TYPES = array('plugin', 'theme', 'mu-plugin');
    const VISIBILITIES = array('public', 'private');

    /** @var string */
    private $id;
    /** @var string */
    private $repo;
    /** @var string */
    private $branch;
    /** @var string */
    private $visibility;
    /** @var string */
    private $targetType;
    /** @var string */
    private $targetSlug;
    /** @var string */
    private $token;
    /** @var string */
    private $webhookSecret;
    /** @var string */
    private $lastDeployedSha;

    public function __construct(
        string $id,
        string $repo,
        string $branch,
        string $visibility,
        string $targetType,
        string $targetSlug,
        string $token,
        string $webhookSecret,
        string $lastDeployedSha
    ) {
        $this->id = $id;
        $this->repo = $repo;
        $this->branch = $branch;
        $this->visibility = $visibility;
        $this->targetType = $targetType;
        $this->targetSlug = $targetSlug;
        $this->token = $token;
        $this->webhookSecret = $webhookSecret;
        $this->lastDeployedSha = $lastDeployedSha;
    }

    public static function fromArray(array $data): self
    {
        foreach (array('id', 'repo', 'branch', 'visibility', 'target_type', 'target_slug') as $key) {
            if (empty($data[$key])) {
                throw new \InvalidArgumentException('Missing required field: ' . $key);
            }
        }
        if (! preg_match('#^[\w.-]+/[\w.-]+$#', $data['repo'])) {
            throw new \InvalidArgumentException('repo must be in owner/repo form');
        }
        if (! in_array($data['visibility'], self::VISIBILITIES, true)) {
            throw new \InvalidArgumentException('invalid visibility');
        }
        if (! in_array($data['target_type'], self::TYPES, true)) {
            throw new \InvalidArgumentException('invalid target_type');
        }
        if (! preg_match('#^[a-z0-9][a-z0-9-]*$#', $data['target_slug'])) {
            throw new \InvalidArgumentException('invalid target_slug');
        }

        return new self(
            (string) $data['id'],
            (string) $data['repo'],
            (string) $data['branch'],
            (string) $data['visibility'],
            (string) $data['target_type'],
            (string) $data['target_slug'],
            isset($data['token']) ? (string) $data['token'] : '',
            isset($data['webhook_secret']) ? (string) $data['webhook_secret'] : '',
            isset($data['last_deployed_sha']) ? (string) $data['last_deployed_sha'] : ''
        );
    }

    public function toArray(): array
    {
        return array(
            'id' => $this->id,
            'repo' => $this->repo,
            'branch' => $this->branch,
            'visibility' => $this->visibility,
            'target_type' => $this->targetType,
            'target_slug' => $this->targetSlug,
            'token' => $this->token,
            'webhook_secret' => $this->webhookSecret,
            'last_deployed_sha' => $this->lastDeployedSha,
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function repo(): string
    {
        return $this->repo;
    }

    public function branch(): string
    {
        return $this->branch;
    }

    public function visibility(): string
    {
        return $this->visibility;
    }

    public function targetType(): string
    {
        return $this->targetType;
    }

    public function targetSlug(): string
    {
        return $this->targetSlug;
    }

    public function token(): string
    {
        return $this->token;
    }

    public function webhookSecret(): string
    {
        return $this->webhookSecret;
    }

    public function lastDeployedSha(): string
    {
        return $this->lastDeployedSha;
    }

    public function withLastDeployedSha(string $sha): self
    {
        $next = $this->toArray();
        $next['last_deployed_sha'] = $sha;

        return self::fromArray($next);
    }

    public function withToken(string $token): self
    {
        $next = $this->toArray();
        $next['token'] = $token;

        return self::fromArray($next);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter DeploymentTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Config/Deployment.php tests/Unit/Config/DeploymentTest.php
git commit -m "feat(deployward): add immutable Deployment value object"
```

---

### Task 4: DeploymentRepository (encrypted CRUD over wp_options)

**Files:**
- Create: `src/Config/DeploymentRepository.php`
- Test: `tests/Unit/Config/DeploymentRepositoryTest.php`

**Interfaces:**
- Consumes: `Deployward\Security\Encryptor`, `Deployward\Config\Deployment`.
- Produces: `Deployward\Config\DeploymentRepository` with `__construct(Encryptor $encryptor)`, `all(): array` (array of `Deployment` keyed by id), `find(string $id): ?Deployment`, `save(Deployment $deployment): void`, `delete(string $id): void`. Constant `DeploymentRepository::OPTION = 'deployward_deployments'`. Tokens are encrypted in storage and decrypted on read.

- [ ] **Step 1: Write the failing test `tests/Unit/Config/DeploymentRepositoryTest.php`**

```php
<?php

namespace Deployward\Tests\Unit\Config;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Config\Deployment;
use Deployward\Config\DeploymentRepository;
use Deployward\Security\Encryptor;
use PHPUnit\Framework\TestCase;

final class DeploymentRepositoryTest extends TestCase
{
    private $store = array();

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->store = array();

        Functions\when('get_option')->alias(function ($key, $default = false) {
            return array_key_exists($key, $this->store) ? $this->store[$key] : $default;
        });
        Functions\when('update_option')->alias(function ($key, $value) {
            $this->store[$key] = $value;
            return true;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function repository(): DeploymentRepository
    {
        return new DeploymentRepository(new Encryptor(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    }

    private function deployment(): Deployment
    {
        return Deployment::fromArray(array(
            'id' => 'abc',
            'repo' => 'Nara-IT/nara-core',
            'branch' => 'main',
            'visibility' => 'private',
            'target_type' => 'plugin',
            'target_slug' => 'nara-core',
            'token' => 'ghp_secret',
            'webhook_secret' => 'whsec',
        ));
    }

    public function test_save_stores_token_encrypted(): void
    {
        $this->repository()->save($this->deployment());

        $stored = $this->store[DeploymentRepository::OPTION]['abc'];
        $this->assertNotSame('ghp_secret', $stored['token']);
        $this->assertNotSame('', $stored['token']);
    }

    public function test_find_returns_decrypted_token(): void
    {
        $repo = $this->repository();
        $repo->save($this->deployment());

        $found = $repo->find('abc');
        $this->assertSame('ghp_secret', $found->token());
    }

    public function test_delete_removes_deployment(): void
    {
        $repo = $this->repository();
        $repo->save($this->deployment());
        $repo->delete('abc');

        $this->assertNull($repo->find('abc'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter DeploymentRepositoryTest`
Expected: FAIL with "Class Deployward\Config\DeploymentRepository not found".

- [ ] **Step 3: Write `src/Config/DeploymentRepository.php`**

```php
<?php

namespace Deployward\Config;

use Deployward\Security\Encryptor;

final class DeploymentRepository
{
    const OPTION = 'deployward_deployments';

    /** @var Encryptor */
    private $encryptor;

    public function __construct(Encryptor $encryptor)
    {
        $this->encryptor = $encryptor;
    }

    public function all(): array
    {
        $raw = get_option(self::OPTION, array());
        if (! is_array($raw)) {
            return array();
        }
        $out = array();
        foreach ($raw as $id => $row) {
            if (! is_array($row)) {
                continue;
            }
            $row['token'] = $this->decryptToken(isset($row['token']) ? (string) $row['token'] : '');
            $out[$id] = Deployment::fromArray($row);
        }

        return $out;
    }

    public function find(string $id): ?Deployment
    {
        $all = $this->all();

        return isset($all[$id]) ? $all[$id] : null;
    }

    public function save(Deployment $deployment): void
    {
        $raw = get_option(self::OPTION, array());
        if (! is_array($raw)) {
            $raw = array();
        }
        $row = $deployment->toArray();
        $row['token'] = $deployment->token() !== '' ? $this->encryptor->encrypt($deployment->token()) : '';
        $next = array_merge($raw, array($deployment->id() => $row));
        update_option(self::OPTION, $next, false);
    }

    public function delete(string $id): void
    {
        $raw = get_option(self::OPTION, array());
        if (! is_array($raw)) {
            return;
        }
        $next = array();
        foreach ($raw as $key => $row) {
            if ($key !== $id) {
                $next[$key] = $row;
            }
        }
        update_option(self::OPTION, $next, false);
    }

    private function decryptToken(string $token): string
    {
        if ($token === '') {
            return '';
        }
        try {
            return $this->encryptor->decrypt($token);
        } catch (\Throwable $e) {
            return '';
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter DeploymentRepositoryTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Config/DeploymentRepository.php tests/Unit/Config/DeploymentRepositoryTest.php
git commit -m "feat(deployward): add DeploymentRepository with encrypted token storage"
```

---

### Task 5: DeployLog (custom audit table)

**Files:**
- Create: `src/Log/DeployLog.php`
- Test: `tests/Unit/Log/DeployLogTest.php`

**Interfaces:**
- Consumes: nothing (takes a `$wpdb`-like object).
- Produces: `Deployward\Log\DeployLog` with `__construct($wpdb)`, `table(): string`, `installTable(): void`, `record(array $row): void` (keys: `deployment_id`, `sha`, `trigger`, `status`, `message`), `recent(string $deploymentId, int $limit = 20, int $offset = 0): array`.

- [ ] **Step 1: Write the failing test `tests/Unit/Log/DeployLogTest.php`**

```php
<?php

namespace Deployward\Tests\Unit\Log;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Log\DeployLog;
use Mockery;
use PHPUnit\Framework\TestCase;

final class DeployLogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('current_time')->justReturn('2026-06-29 10:00:00');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_record_inserts_mapped_columns(): void
    {
        $wpdb = Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('insert')->once()->with(
            'wp_deployward_log',
            Mockery::on(function ($data) {
                return $data['deployment_id'] === 'abc'
                    && $data['sha'] === 'deadbeef'
                    && $data['trigger_source'] === 'manual'
                    && $data['status'] === 'success'
                    && $data['created_at'] === '2026-06-29 10:00:00';
            }),
            Mockery::type('array')
        );

        $log = new DeployLog($wpdb);
        $log->record(array(
            'deployment_id' => 'abc',
            'sha' => 'deadbeef',
            'trigger' => 'manual',
            'status' => 'success',
            'message' => 'ok',
        ));
    }

    public function test_recent_uses_prepared_query(): void
    {
        $wpdb = Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->once()->andReturn('PREPARED');
        $wpdb->shouldReceive('get_results')->once()->with('PREPARED', ARRAY_A)->andReturn(array(array('id' => 1)));

        $log = new DeployLog($wpdb);
        $rows = $log->recent('abc', 10, 0);

        $this->assertSame(array(array('id' => 1)), $rows);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter DeployLogTest`
Expected: FAIL with "Class Deployward\Log\DeployLog not found".

- [ ] **Step 3: Write `src/Log/DeployLog.php`**

```php
<?php

namespace Deployward\Log;

final class DeployLog
{
    /** @var object */
    private $wpdb;

    public function __construct($wpdb)
    {
        $this->wpdb = $wpdb;
    }

    public function table(): string
    {
        return $this->wpdb->prefix . 'deployward_log';
    }

    public function installTable(): void
    {
        $table = $this->table();
        $charset = method_exists($this->wpdb, 'get_charset_collate') ? $this->wpdb->get_charset_collate() : '';
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            deployment_id VARCHAR(64) NOT NULL,
            sha VARCHAR(64) NOT NULL DEFAULT '',
            trigger_source VARCHAR(32) NOT NULL DEFAULT '',
            status VARCHAR(16) NOT NULL DEFAULT '',
            message TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY deployment_id (deployment_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function record(array $row): void
    {
        $this->wpdb->insert(
            $this->table(),
            array(
                'deployment_id' => isset($row['deployment_id']) ? (string) $row['deployment_id'] : '',
                'sha' => isset($row['sha']) ? (string) $row['sha'] : '',
                'trigger_source' => isset($row['trigger']) ? (string) $row['trigger'] : '',
                'status' => isset($row['status']) ? (string) $row['status'] : '',
                'message' => isset($row['message']) ? (string) $row['message'] : '',
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s')
        );
    }

    public function recent(string $deploymentId, int $limit = 20, int $offset = 0): array
    {
        $table = $this->table();
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$table} WHERE deployment_id = %s ORDER BY id DESC LIMIT %d OFFSET %d",
            $deploymentId,
            $limit,
            $offset
        );
        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? $rows : array();
    }
}
```

Note: the table name is built from `$wpdb->prefix` (trusted, never user input), so it is safe to interpolate. All user-influenced values pass through `$wpdb->prepare()`.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter DeployLogTest`
Expected: PASS (2 tests). If `ARRAY_A` is undefined under PHPUnit, add `if (! defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }` to `tests/bootstrap.php`.

- [ ] **Step 5: Commit**

```bash
git add src/Log/DeployLog.php tests/Unit/Log/DeployLogTest.php tests/bootstrap.php
git commit -m "feat(deployward): add DeployLog audit table"
```

---

### Task 6: GitHubClient (resolve SHA, download zipball)

**Files:**
- Create: `src/GitHub/GitHubClient.php`
- Test: `tests/Unit/GitHub/GitHubClientTest.php`

**Interfaces:**
- Consumes: `Deployward\Support\Result`.
- Produces: `Deployward\GitHub\GitHubClient` with `resolveSha(string $repo, string $ref, ?string $token): Result` (on success `data()` is the commit SHA string), `downloadZipball(string $repo, string $ref, ?string $token, string $destFile): Result` (on success `data()` is `$destFile`).

- [ ] **Step 1: Write the failing test `tests/Unit/GitHub/GitHubClientTest.php`**

```php
<?php

namespace Deployward\Tests\Unit\GitHub;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\GitHub\GitHubClient;
use PHPUnit\Framework\TestCase;

final class GitHubClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_resolve_sha_returns_commit_sha(): void
    {
        Functions\when('wp_remote_get')->justReturn(array('body' => '{"sha":"abc123"}'));
        Functions\when('wp_remote_retrieve_body')->justReturn('{"sha":"abc123"}');

        $result = (new GitHubClient())->resolveSha('Nara-IT/nara-core', 'main', 'ghp_x');

        $this->assertTrue($result->isOk());
        $this->assertSame('abc123', $result->data());
    }

    public function test_resolve_sha_fails_on_non_200(): void
    {
        Functions\when('wp_remote_retrieve_response_code')->justReturn(404);
        Functions\when('wp_remote_get')->justReturn(array());
        Functions\when('wp_remote_retrieve_body')->justReturn('');

        $result = (new GitHubClient())->resolveSha('Nara-IT/missing', 'main', null);

        $this->assertFalse($result->isOk());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter GitHubClientTest`
Expected: FAIL with "Class Deployward\GitHub\GitHubClient not found".

- [ ] **Step 3: Write `src/GitHub/GitHubClient.php`**

```php
<?php

namespace Deployward\GitHub;

use Deployward\Support\Result;

final class GitHubClient
{
    const API = 'https://api.github.com';

    public function resolveSha(string $repo, string $ref, ?string $token): Result
    {
        $url = self::API . '/repos/' . $repo . '/commits/' . rawurlencode($ref);
        $response = wp_remote_get($url, $this->args($token, 30));
        if (is_wp_error($response)) {
            return Result::fail('GitHub request failed: ' . $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return Result::fail('GitHub returned HTTP ' . $code . ' resolving ' . $repo . '@' . $ref);
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (! is_array($body) || empty($body['sha'])) {
            return Result::fail('GitHub response did not include a commit sha');
        }

        return Result::ok((string) $body['sha']);
    }

    public function downloadZipball(string $repo, string $ref, ?string $token, string $destFile): Result
    {
        $url = self::API . '/repos/' . $repo . '/zipball/' . rawurlencode($ref);
        $args = array_merge(
            $this->args($token, 300),
            array('stream' => true, 'filename' => $destFile)
        );
        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            return Result::fail('Download failed: ' . $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return Result::fail('Download returned HTTP ' . $code);
        }
        if (! is_file($destFile) || filesize($destFile) === 0) {
            return Result::fail('Downloaded archive is empty');
        }

        return Result::ok($destFile);
    }

    private function args(?string $token, int $timeout): array
    {
        $headers = array(
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'Deployward',
            'X-GitHub-Api-Version' => '2022-11-28',
        );
        if ($token !== null && $token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return array('headers' => $headers, 'timeout' => $timeout);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter GitHubClientTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/GitHub/GitHubClient.php tests/Unit/GitHub/GitHubClientTest.php
git commit -m "feat(deployward): add GitHubClient for sha resolution and zipball download"
```

---

### Task 7: Extractor (unzip and flatten)

**Files:**
- Create: `src/Deploy/Extractor.php`
- Test: `tests/Unit/Deploy/ExtractorTest.php`

**Interfaces:**
- Consumes: `Deployward\Support\Result`.
- Produces: `Deployward\Deploy\Extractor` with `__construct(string $workBaseDir)`, `extract(string $zipFile): Result` (on success `data()` is the flattened payload root path), `flatten(string $extractedDir): string` (returns the single wrapped subdirectory, or the dir itself if not wrapped).

- [ ] **Step 1: Write the failing test `tests/Unit/Deploy/ExtractorTest.php`**

```php
<?php

namespace Deployward\Tests\Unit\Deploy;

use Deployward\Deploy\Extractor;
use PHPUnit\Framework\TestCase;

final class ExtractorTest extends TestCase
{
    private $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/dw-extract-' . uniqid();
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        @exec('rm -rf ' . escapeshellarg($this->tmp));
        parent::tearDown();
    }

    public function test_flatten_returns_single_wrapper_subdir(): void
    {
        $extracted = $this->tmp . '/extracted';
        mkdir($extracted . '/Nara-IT-nara-core-deadbeef', 0777, true);
        file_put_contents($extracted . '/Nara-IT-nara-core-deadbeef/nara-core.php', '<?php');

        $root = (new Extractor($this->tmp))->flatten($extracted);

        $this->assertSame($extracted . '/Nara-IT-nara-core-deadbeef', $root);
    }

    public function test_flatten_returns_dir_when_not_wrapped(): void
    {
        $extracted = $this->tmp . '/flat';
        mkdir($extracted, 0777, true);
        file_put_contents($extracted . '/a.php', '<?php');
        file_put_contents($extracted . '/b.php', '<?php');

        $root = (new Extractor($this->tmp))->flatten($extracted);

        $this->assertSame($extracted, $root);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter ExtractorTest`
Expected: FAIL with "Class Deployward\Deploy\Extractor not found".

- [ ] **Step 3: Write `src/Deploy/Extractor.php`**

```php
<?php

namespace Deployward\Deploy;

use Deployward\Support\Result;

final class Extractor
{
    /** @var string */
    private $workBaseDir;

    public function __construct(string $workBaseDir)
    {
        $this->workBaseDir = rtrim($workBaseDir, '/');
    }

    public function extract(string $zipFile): Result
    {
        if (! function_exists('unzip_file')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (! \WP_Filesystem()) {
            return Result::fail('Could not initialise WP_Filesystem');
        }
        $dest = $this->workBaseDir . '/dw-extract-' . wp_generate_password(8, false);
        $unzipped = unzip_file($zipFile, $dest);
        if (is_wp_error($unzipped)) {
            return Result::fail('Unzip failed: ' . $unzipped->get_error_message());
        }
        $root = $this->flatten($dest);
        if ($root === '' || ! is_dir($root)) {
            return Result::fail('Unexpected archive layout');
        }

        return Result::ok($root);
    }

    public function flatten(string $extractedDir): string
    {
        $entries = scandir($extractedDir);
        if ($entries === false) {
            return '';
        }
        $entries = array_values(array_diff($entries, array('.', '..')));
        if (count($entries) === 1 && is_dir($extractedDir . '/' . $entries[0])) {
            return $extractedDir . '/' . $entries[0];
        }

        return $extractedDir;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter ExtractorTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Deploy/Extractor.php tests/Unit/Deploy/ExtractorTest.php
git commit -m "feat(deployward): add Extractor that unzips and flattens GitHub archives"
```

---

### Task 8: PayloadValidator

**Files:**
- Create: `src/Deploy/PayloadValidator.php`
- Test: `tests/Unit/Deploy/PayloadValidatorTest.php`

**Interfaces:**
- Consumes: `Deployward\Support\Result`.
- Produces: `Deployward\Deploy\PayloadValidator` with `validate(string $dir, string $targetType, string $targetSlug): Result`. Plugin payloads require a `*.php` file containing a `Plugin Name:` header; theme payloads require `style.css` containing a `Theme Name:` header.

- [ ] **Step 1: Write the failing test `tests/Unit/Deploy/PayloadValidatorTest.php`**

```php
<?php

namespace Deployward\Tests\Unit\Deploy;

use Deployward\Deploy\PayloadValidator;
use PHPUnit\Framework\TestCase;

final class PayloadValidatorTest extends TestCase
{
    private $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/dw-validate-' . uniqid();
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        @exec('rm -rf ' . escapeshellarg($this->tmp));
        parent::tearDown();
    }

    public function test_accepts_plugin_with_header(): void
    {
        file_put_contents($this->tmp . '/nara-core.php', "<?php\n/**\n * Plugin Name: Nara Core\n */");

        $result = (new PayloadValidator())->validate($this->tmp, 'plugin', 'nara-core');

        $this->assertTrue($result->isOk());
    }

    public function test_rejects_plugin_without_header(): void
    {
        file_put_contents($this->tmp . '/nara-core.php', "<?php\n// nothing here");

        $result = (new PayloadValidator())->validate($this->tmp, 'plugin', 'nara-core');

        $this->assertFalse($result->isOk());
    }

    public function test_accepts_theme_with_style_header(): void
    {
        file_put_contents($this->tmp . '/style.css', "/*\nTheme Name: Nara\n*/");

        $result = (new PayloadValidator())->validate($this->tmp, 'theme', 'nara');

        $this->assertTrue($result->isOk());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter PayloadValidatorTest`
Expected: FAIL with "Class Deployward\Deploy\PayloadValidator not found".

- [ ] **Step 3: Write `src/Deploy/PayloadValidator.php`**

```php
<?php

namespace Deployward\Deploy;

use Deployward\Support\Result;

final class PayloadValidator
{
    public function validate(string $dir, string $targetType, string $targetSlug): Result
    {
        if (! is_dir($dir)) {
            return Result::fail('Payload directory is missing');
        }
        if ($targetType === 'theme') {
            return $this->validateTheme($dir, $targetSlug);
        }

        return $this->validatePlugin($dir, $targetSlug);
    }

    private function validatePlugin(string $dir, string $slug): Result
    {
        $files = glob($dir . '/*.php');
        if ($files === false) {
            $files = array();
        }
        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            if (preg_match('/^[ \t\/*#@]*Plugin Name:/mi', $contents)) {
                return Result::ok($slug);
            }
        }

        return Result::fail('No plugin header found in payload for ' . $slug);
    }

    private function validateTheme(string $dir, string $slug): Result
    {
        $style = $dir . '/style.css';
        if (! is_file($style)) {
            return Result::fail('Theme payload is missing style.css');
        }
        $contents = (string) file_get_contents($style);
        if (! preg_match('/^[ \t\/*#@]*Theme Name:/mi', $contents)) {
            return Result::fail('No theme header found in style.css');
        }

        return Result::ok($slug);
    }
}
```

Note: validation is by header presence, not directory name. GitHub's archive wrapper folder is named `owner-repo-sha`, never the target slug; the swap (Task 11) is what renames the payload into the slug directory.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter PayloadValidatorTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Deploy/PayloadValidator.php tests/Unit/Deploy/PayloadValidatorTest.php
git commit -m "feat(deployward): add PayloadValidator for plugin and theme headers"
```

---

### Task 9: BackupManager (versioned backups + rollback)

**Files:**
- Create: `src/Deploy/BackupManager.php`
- Test: `tests/Unit/Deploy/BackupManagerTest.php`

**Interfaces:**
- Consumes: `Deployward\Support\Result`.
- Produces: `Deployward\Deploy\BackupManager` with `__construct(string $baseDir)`, `ensureProtected(): void`, `backup(string $targetDir, string $slug, string $sha): Result` (skips if target absent; on success `data()` is the backup path), `restoreLatest(string $slug, string $targetDir): Result`, `latest(string $slug): ?string`, `prune(string $slug, int $keep): void`.

- [ ] **Step 1: Write the failing test `tests/Unit/Deploy/BackupManagerTest.php`**

```php
<?php

namespace Deployward\Tests\Unit\Deploy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Deploy\BackupManager;
use PHPUnit\Framework\TestCase;

final class BackupManagerTest extends TestCase
{
    private $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('wp_mkdir_p')->alias(function ($dir) {
            return is_dir($dir) || mkdir($dir, 0777, true);
        });
        $this->tmp = sys_get_temp_dir() . '/dw-backup-' . uniqid();
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        @exec('rm -rf ' . escapeshellarg($this->tmp));
        Monkey\tearDown();
        parent::tearDown();
    }

    private function makeTarget(string $marker): string
    {
        $target = $this->tmp . '/plugins/nara-core';
        if (! is_dir($target)) {
            mkdir($target, 0777, true);
        }
        file_put_contents($target . '/version.txt', $marker);

        return $target;
    }

    public function test_backup_moves_current_target_aside(): void
    {
        $target = $this->makeTarget('v1');
        $manager = new BackupManager($this->tmp . '/backups');

        $result = $manager->backup($target, 'nara-core', 'aaaaaaaa');

        $this->assertTrue($result->isOk());
        $this->assertDirectoryDoesNotExist($target);
        $this->assertFileExists($result->data() . '/version.txt');
    }

    public function test_backup_skips_when_target_missing(): void
    {
        $manager = new BackupManager($this->tmp . '/backups');

        $result = $manager->backup($this->tmp . '/plugins/none', 'none', 'aaaaaaaa');

        $this->assertTrue($result->isSkipped());
    }

    public function test_restore_latest_brings_back_previous_version(): void
    {
        $target = $this->makeTarget('v1');
        $manager = new BackupManager($this->tmp . '/backups');
        $manager->backup($target, 'nara-core', 'aaaaaaaa');
        $this->makeTarget('v2');

        $restore = $manager->restoreLatest('nara-core', $target);

        $this->assertTrue($restore->isOk());
        $this->assertSame('v1', file_get_contents($target . '/version.txt'));
    }

    public function test_prune_keeps_only_n_newest(): void
    {
        $manager = new BackupManager($this->tmp . '/backups');
        foreach (array('20260101-000001-a', '20260101-000002-b', '20260101-000003-c', '20260101-000004-d') as $name) {
            mkdir($this->tmp . '/backups/nara-core/' . $name, 0777, true);
        }

        $manager->prune('nara-core', 2);

        $remaining = array_values(array_diff(scandir($this->tmp . '/backups/nara-core'), array('.', '..')));
        $this->assertCount(2, $remaining);
        $this->assertContains('20260101-000004-d', $remaining);
        $this->assertContains('20260101-000003-c', $remaining);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter BackupManagerTest`
Expected: FAIL with "Class Deployward\Deploy\BackupManager not found".

- [ ] **Step 3: Write `src/Deploy/BackupManager.php`**

```php
<?php

namespace Deployward\Deploy;

use Deployward\Support\Result;

final class BackupManager
{
    /** @var string */
    private $baseDir;

    public function __construct(string $baseDir)
    {
        $this->baseDir = rtrim($baseDir, '/');
    }

    public function ensureProtected(): void
    {
        if (! is_dir($this->baseDir)) {
            wp_mkdir_p($this->baseDir);
        }
        $index = $this->baseDir . '/index.php';
        if (! is_file($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
        $htaccess = $this->baseDir . '/.htaccess';
        if (! is_file($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n");
        }
    }

    public function backup(string $targetDir, string $slug, string $sha): Result
    {
        if (! is_dir($targetDir)) {
            return Result::skip('Nothing to back up; target does not exist yet');
        }
        $this->ensureProtected();
        $slugDir = $this->baseDir . '/' . $slug;
        wp_mkdir_p($slugDir);
        $dest = $slugDir . '/' . gmdate('Ymd-His') . '-' . substr($sha, 0, 8);
        if (! @rename($targetDir, $dest)) {
            return Result::fail('Could not move current version into backups');
        }

        return Result::ok($dest);
    }

    public function restoreLatest(string $slug, string $targetDir): Result
    {
        $latest = $this->latest($slug);
        if ($latest === null) {
            return Result::fail('No backup available to restore');
        }
        if (is_dir($targetDir)) {
            $this->deleteDir($targetDir);
        }
        if (! @rename($latest, $targetDir)) {
            return Result::fail('Could not restore backup into place');
        }

        return Result::ok($targetDir);
    }

    public function latest(string $slug): ?string
    {
        $backups = $this->sortedBackups($slug);

        return $backups === array() ? null : $backups[0];
    }

    public function prune(string $slug, int $keep): void
    {
        $stale = array_slice($this->sortedBackups($slug), max(0, $keep));
        foreach ($stale as $dir) {
            $this->deleteDir($dir);
        }
    }

    private function sortedBackups(string $slug): array
    {
        $slugDir = $this->baseDir . '/' . $slug;
        if (! is_dir($slugDir)) {
            return array();
        }
        $entries = array_values(array_diff(scandir($slugDir), array('.', '..')));
        $dirs = array();
        foreach ($entries as $entry) {
            $path = $slugDir . '/' . $entry;
            if (is_dir($path)) {
                $dirs[] = $path;
            }
        }
        rsort($dirs);

        return $dirs;
    }

    private function deleteDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
```

Note: `rename()` is atomic on the same filesystem. `wp-content/uploads` and `wp-content/plugins` are normally on the same volume, so this holds. If a host places them on different volumes, a later plan can add a copy-then-delete fallback; flag it if a host is found where rename fails.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter BackupManagerTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Deploy/BackupManager.php tests/Unit/Deploy/BackupManagerTest.php
git commit -m "feat(deployward): add BackupManager with versioned backups and rollback"
```

---

### Task 10: HealthChecker

**Files:**
- Create: `src/Deploy/HealthChecker.php`
- Test: `tests/Unit/Deploy/HealthCheckerTest.php`

**Interfaces:**
- Consumes: `Deployward\Support\Result`.
- Produces: `Deployward\Deploy\HealthChecker` with `check(string $url): Result`. Fails on HTTP >= 500 or when the body contains a WordPress fatal-error signature.

- [ ] **Step 1: Write the failing test `tests/Unit/Deploy/HealthCheckerTest.php`**

```php
<?php

namespace Deployward\Tests\Unit\Deploy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Deploy\HealthChecker;
use PHPUnit\Framework\TestCase;

final class HealthCheckerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('is_wp_error')->justReturn(false);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_healthy_on_200_clean_body(): void
    {
        Functions\when('wp_remote_get')->justReturn(array());
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('<html>ok</html>');

        $this->assertTrue((new HealthChecker())->check('https://nara.local/')->isOk());
    }

    public function test_unhealthy_on_500(): void
    {
        Functions\when('wp_remote_get')->justReturn(array());
        Functions\when('wp_remote_retrieve_response_code')->justReturn(500);
        Functions\when('wp_remote_retrieve_body')->justReturn('');

        $this->assertFalse((new HealthChecker())->check('https://nara.local/')->isOk());
    }

    public function test_unhealthy_on_fatal_signature(): void
    {
        Functions\when('wp_remote_get')->justReturn(array());
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('There has been a critical error on this website.');

        $this->assertFalse((new HealthChecker())->check('https://nara.local/')->isOk());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter HealthCheckerTest`
Expected: FAIL with "Class Deployward\Deploy\HealthChecker not found".

- [ ] **Step 3: Write `src/Deploy/HealthChecker.php`**

```php
<?php

namespace Deployward\Deploy;

use Deployward\Support\Result;

final class HealthChecker
{
    public function check(string $url): Result
    {
        $response = wp_remote_get($url, array(
            'timeout' => 20,
            'redirection' => 2,
            'sslverify' => true,
        ));
        if (is_wp_error($response)) {
            return Result::fail('Health check error: ' . $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code >= 500) {
            return Result::fail('Site returned HTTP ' . $code);
        }
        $body = (string) wp_remote_retrieve_body($response);
        if (stripos($body, 'There has been a critical error') !== false
            || stripos($body, 'Fatal error') !== false) {
            return Result::fail('Detected a fatal error in the page output');
        }

        return Result::ok($code);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter HealthCheckerTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Deploy/HealthChecker.php tests/Unit/Deploy/HealthCheckerTest.php
git commit -m "feat(deployward): add HealthChecker with fatal-error detection"
```

---

### Task 11: MaintenanceMode, Notifier, and the Deployer orchestration

**Files:**
- Create: `src/Deploy/MaintenanceMode.php`
- Create: `src/Notify/Notifier.php`
- Create: `src/Deploy/Deployer.php`
- Test: `tests/Unit/Deploy/DeployerTest.php`

**Interfaces:**
- Consumes: `GitHubClient`, `Extractor`, `PayloadValidator`, `BackupManager`, `HealthChecker`, `MaintenanceMode`, `DeployLog`, `DeploymentRepository`, `Notifier`, `Deployment`, `Result`.
- Produces:
  - `Deployward\Deploy\MaintenanceMode` with `__construct(string $abspath)`, `enable(): void`, `disable(): void`.
  - `Deployward\Notify\Notifier` with `__construct(string $to)`, `notify(string $subject, string $body): void`.
  - `Deployward\Deploy\Deployer` with `__construct(GitHubClient $github, Extractor $extractor, PayloadValidator $validator, BackupManager $backups, HealthChecker $health, MaintenanceMode $maintenance, DeployLog $log, DeploymentRepository $repository, Notifier $notifier, array $targetRoots, string $healthUrl, string $tempDir, string $selfSlug = 'deployward', int $keepBackups = 3)`, `deploy(Deployment $deployment, string $trigger, bool $force = false): Result`, `rollback(Deployment $deployment, string $trigger = 'manual-rollback'): Result`. `$targetRoots` maps `'plugin'`, `'theme'`, `'mu-plugin'` to absolute base directories.

- [ ] **Step 1: Write `src/Deploy/MaintenanceMode.php`**

```php
<?php

namespace Deployward\Deploy;

final class MaintenanceMode
{
    /** @var string */
    private $abspath;

    public function __construct(string $abspath)
    {
        $this->abspath = rtrim($abspath, '/') . '/';
    }

    public function enable(): void
    {
        file_put_contents($this->abspath . '.maintenance', '<?php $upgrading = ' . time() . ';');
    }

    public function disable(): void
    {
        $file = $this->abspath . '.maintenance';
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
```

- [ ] **Step 2: Write `src/Notify/Notifier.php`**

```php
<?php

namespace Deployward\Notify;

final class Notifier
{
    /** @var string */
    private $to;

    public function __construct(string $to)
    {
        $this->to = $to;
    }

    public function notify(string $subject, string $body): void
    {
        if ($this->to === '') {
            return;
        }
        wp_mail($this->to, $subject, $body);
    }
}
```

- [ ] **Step 3: Write the failing test `tests/Unit/Deploy/DeployerTest.php`**

```php
<?php

namespace Deployward\Tests\Unit\Deploy;

use Brain\Monkey;
use Deployward\Config\Deployment;
use Deployward\Config\DeploymentRepository;
use Deployward\Deploy\BackupManager;
use Deployward\Deploy\Deployer;
use Deployward\Deploy\Extractor;
use Deployward\Deploy\HealthChecker;
use Deployward\Deploy\MaintenanceMode;
use Deployward\Deploy\PayloadValidator;
use Deployward\GitHub\GitHubClient;
use Deployward\Log\DeployLog;
use Deployward\Notify\Notifier;
use Deployward\Support\Result;
use Mockery;
use PHPUnit\Framework\TestCase;

final class DeployerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function deployment(string $slug = 'nara-core'): Deployment
    {
        return Deployment::fromArray(array(
            'id' => 'abc',
            'repo' => 'Nara-IT/nara-core',
            'branch' => 'main',
            'visibility' => 'private',
            'target_type' => 'plugin',
            'target_slug' => $slug,
            'token' => 'ghp_x',
            'webhook_secret' => 'whsec',
            'last_deployed_sha' => 'oldsha',
        ));
    }

    private function deployer(array $mocks): Deployer
    {
        return new Deployer(
            $mocks['github'],
            $mocks['extractor'],
            $mocks['validator'],
            $mocks['backups'],
            $mocks['health'],
            $mocks['maintenance'],
            $mocks['log'],
            $mocks['repository'],
            $mocks['notifier'],
            array('plugin' => '/srv/plugins', 'theme' => '/srv/themes', 'mu-plugin' => '/srv/mu'),
            'https://nara.local/',
            sys_get_temp_dir()
        );
    }

    public function test_refuses_to_deploy_itself(): void
    {
        $log = Mockery::mock(DeployLog::class);
        $log->shouldReceive('record')->once();
        $mocks = $this->baseMocks(array('log' => $log));

        $result = $this->deployer($mocks)->deploy($this->deployment('deployward'), 'manual');

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('itself', $result->message());
    }

    public function test_skips_when_already_at_latest_sha(): void
    {
        $github = Mockery::mock(GitHubClient::class);
        $github->shouldReceive('resolveSha')->once()->andReturn(Result::ok('oldsha'));
        $log = Mockery::mock(DeployLog::class);
        $log->shouldReceive('record')->once();
        $mocks = $this->baseMocks(array('github' => $github, 'log' => $log));

        $result = $this->deployer($mocks)->deploy($this->deployment(), 'cron');

        $this->assertTrue($result->isSkipped());
    }

    public function test_rolls_back_when_health_check_fails(): void
    {
        $github = Mockery::mock(GitHubClient::class);
        $github->shouldReceive('resolveSha')->andReturn(Result::ok('newsha'));
        $github->shouldReceive('downloadZipball')->andReturn(Result::ok('/tmp/x.zip'));

        $extractor = Mockery::mock(Extractor::class);
        $extractor->shouldReceive('extract')->andReturn(Result::ok('/tmp/payload'));

        $validator = Mockery::mock(PayloadValidator::class);
        $validator->shouldReceive('validate')->andReturn(Result::ok('nara-core'));

        $backups = Mockery::mock(BackupManager::class);
        $backups->shouldReceive('backup')->andReturn(Result::ok('/srv/backups/x'));
        $backups->shouldReceive('restoreLatest')->once()->andReturn(Result::ok('/srv/plugins/nara-core'));

        $health = Mockery::mock(HealthChecker::class);
        $health->shouldReceive('check')->andReturn(Result::fail('fatal'));

        $maintenance = Mockery::mock(MaintenanceMode::class);
        $maintenance->shouldReceive('enable')->once();
        $maintenance->shouldReceive('disable')->once();

        $log = Mockery::mock(DeployLog::class);
        $log->shouldReceive('record')->once();

        $notifier = Mockery::mock(Notifier::class);
        $notifier->shouldReceive('notify')->once();

        $repository = Mockery::mock(DeploymentRepository::class);
        $repository->shouldNotReceive('save');

        $mocks = array(
            'github' => $github, 'extractor' => $extractor, 'validator' => $validator,
            'backups' => $backups, 'health' => $health, 'maintenance' => $maintenance,
            'log' => $log, 'repository' => $repository, 'notifier' => $notifier,
        );

        // rename() of a non-existent /tmp/payload to /srv/... will fail in CI, so this
        // test asserts the rollback path; run it where /srv is writable, or adapt the
        // targetRoots to a temp dir in setUp. See note below.
        $result = $this->deployer($mocks)->deploy($this->deployment(), 'webhook');

        $this->assertFalse($result->isOk());
    }

    private function baseMocks(array $overrides): array
    {
        $defaults = array(
            'github' => Mockery::mock(GitHubClient::class),
            'extractor' => Mockery::mock(Extractor::class),
            'validator' => Mockery::mock(PayloadValidator::class),
            'backups' => Mockery::mock(BackupManager::class),
            'health' => Mockery::mock(HealthChecker::class),
            'maintenance' => Mockery::mock(MaintenanceMode::class),
            'log' => Mockery::mock(DeployLog::class),
            'repository' => Mockery::mock(DeploymentRepository::class),
            'notifier' => Mockery::mock(Notifier::class),
        );
        foreach ($defaults as $key => $mock) {
            if (method_exists($mock, 'shouldReceive')) {
                $mock->shouldReceive('notify')->andReturnNull();
            }
        }

        return array_merge($defaults, $overrides);
    }
}
```

Note for the implementer: the swap uses real `rename()`. To make `test_rolls_back_when_health_check_fails` deterministic, set `targetRoots` to writable temp dirs in the test and create a real payload directory before calling `deploy()`. The plan keeps the mock-heavy version to show intent; adjust to temp dirs so the rename succeeds and the health-fail branch is the one under test. Add focused tests for the happy path (asserting `repository->save` and a `success` log) using the same temp-dir approach to reach 80% coverage.

- [ ] **Step 4: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter DeployerTest`
Expected: FAIL with "Class Deployward\Deploy\Deployer not found".

- [ ] **Step 5: Write `src/Deploy/Deployer.php`**

```php
<?php

namespace Deployward\Deploy;

use Deployward\Config\Deployment;
use Deployward\Config\DeploymentRepository;
use Deployward\GitHub\GitHubClient;
use Deployward\Log\DeployLog;
use Deployward\Notify\Notifier;
use Deployward\Support\Result;

final class Deployer
{
    /** @var GitHubClient */
    private $github;
    /** @var Extractor */
    private $extractor;
    /** @var PayloadValidator */
    private $validator;
    /** @var BackupManager */
    private $backups;
    /** @var HealthChecker */
    private $health;
    /** @var MaintenanceMode */
    private $maintenance;
    /** @var DeployLog */
    private $log;
    /** @var DeploymentRepository */
    private $repository;
    /** @var Notifier */
    private $notifier;
    /** @var array */
    private $targetRoots;
    /** @var string */
    private $healthUrl;
    /** @var string */
    private $tempDir;
    /** @var string */
    private $selfSlug;
    /** @var int */
    private $keepBackups;

    public function __construct(
        GitHubClient $github,
        Extractor $extractor,
        PayloadValidator $validator,
        BackupManager $backups,
        HealthChecker $health,
        MaintenanceMode $maintenance,
        DeployLog $log,
        DeploymentRepository $repository,
        Notifier $notifier,
        array $targetRoots,
        string $healthUrl,
        string $tempDir,
        string $selfSlug = 'deployward',
        int $keepBackups = 3
    ) {
        $this->github = $github;
        $this->extractor = $extractor;
        $this->validator = $validator;
        $this->backups = $backups;
        $this->health = $health;
        $this->maintenance = $maintenance;
        $this->log = $log;
        $this->repository = $repository;
        $this->notifier = $notifier;
        $this->targetRoots = $targetRoots;
        $this->healthUrl = $healthUrl;
        $this->tempDir = rtrim($tempDir, '/');
        $this->selfSlug = $selfSlug;
        $this->keepBackups = $keepBackups;
    }

    public function deploy(Deployment $deployment, string $trigger, bool $force = false): Result
    {
        $guard = $this->guardSelf($deployment);
        if ($guard !== null) {
            return $this->finish($deployment, $trigger, $guard, '');
        }

        $shaResult = $this->github->resolveSha(
            $deployment->repo(),
            $deployment->branch(),
            $this->tokenOrNull($deployment)
        );
        if (! $shaResult->isOk()) {
            return $this->finish($deployment, $trigger, $shaResult, '');
        }
        $sha = (string) $shaResult->data();

        if (! $force && $sha === $deployment->lastDeployedSha()) {
            return $this->finish($deployment, $trigger, Result::skip('Already at latest commit'), $sha);
        }

        $payload = $this->preparePayload($deployment, $sha);
        if (! $payload->isOk()) {
            return $this->finish($deployment, $trigger, $payload, $sha);
        }

        $swap = $this->swap($deployment, (string) $payload->data(), $sha);
        if ($swap->isOk()) {
            $this->backups->prune($deployment->targetSlug(), $this->keepBackups);
            $this->repository->save($deployment->withLastDeployedSha($sha));
        }

        return $this->finish($deployment, $trigger, $swap, $sha);
    }

    public function rollback(Deployment $deployment, string $trigger = 'manual-rollback'): Result
    {
        $root = isset($this->targetRoots[$deployment->targetType()])
            ? $this->targetRoots[$deployment->targetType()] : '';
        if ($root === '') {
            return $this->finish($deployment, $trigger, Result::fail('Unknown target type'), '');
        }
        $targetDir = rtrim($root, '/') . '/' . $deployment->targetSlug();

        $this->maintenance->enable();
        $restore = $this->backups->restoreLatest($deployment->targetSlug(), $targetDir);
        $this->maintenance->disable();

        return $this->finish($deployment, $trigger, $restore, '');
    }

    private function preparePayload(Deployment $deployment, string $sha): Result
    {
        $zip = $this->tempDir . '/deployward-' . substr($sha, 0, 8) . '.zip';
        $download = $this->github->downloadZipball(
            $deployment->repo(),
            $sha,
            $this->tokenOrNull($deployment),
            $zip
        );
        if (! $download->isOk()) {
            return $download;
        }

        $extract = $this->extractor->extract($zip);
        @unlink($zip);
        if (! $extract->isOk()) {
            return $extract;
        }
        $newDir = (string) $extract->data();

        $valid = $this->validator->validate($newDir, $deployment->targetType(), $deployment->targetSlug());
        if (! $valid->isOk()) {
            return $valid;
        }

        return Result::ok($newDir);
    }

    private function swap(Deployment $deployment, string $newDir, string $sha): Result
    {
        $root = $this->targetRoots[$deployment->targetType()];
        $targetDir = rtrim($root, '/') . '/' . $deployment->targetSlug();

        $this->maintenance->enable();
        $backup = $this->backups->backup($targetDir, $deployment->targetSlug(), $sha);
        if (! $backup->isOk() && ! $backup->isSkipped()) {
            $this->maintenance->disable();
            return $backup;
        }
        if (! @rename($newDir, $targetDir)) {
            if ($backup->isOk()) {
                $this->backups->restoreLatest($deployment->targetSlug(), $targetDir);
            }
            $this->maintenance->disable();
            return Result::fail('Could not move new version into place');
        }
        $this->maintenance->disable();

        $health = $this->health->check($this->healthUrl);
        if (! $health->isOk()) {
            $this->backups->restoreLatest($deployment->targetSlug(), $targetDir);
            return Result::fail('Health check failed, rolled back: ' . $health->message());
        }

        return Result::ok($sha, 'Deployed ' . substr($sha, 0, 8));
    }

    private function guardSelf(Deployment $deployment): ?Result
    {
        if ($deployment->targetType() === 'plugin' && $deployment->targetSlug() === $this->selfSlug) {
            return Result::fail('Deployward will not deploy itself');
        }

        return null;
    }

    private function tokenOrNull(Deployment $deployment): ?string
    {
        return $deployment->visibility() === 'private' ? $deployment->token() : null;
    }

    private function finish(Deployment $deployment, string $trigger, Result $result, string $sha): Result
    {
        $status = $result->isOk() ? ($result->isSkipped() ? 'skipped' : 'success') : 'failed';
        $this->log->record(array(
            'deployment_id' => $deployment->id(),
            'sha' => $sha,
            'trigger' => $trigger,
            'status' => $status,
            'message' => $result->message(),
        ));
        if ($status !== 'skipped') {
            $this->notifier->notify(
                sprintf('[Deployward] %s: %s', strtoupper($status), $deployment->repo()),
                $result->message()
            );
        }

        return $result;
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter DeployerTest`
Expected: PASS. Expand with a happy-path test (temp-dir target roots, real payload dir) asserting `repository->save` is called once and a `success` row is logged.

- [ ] **Step 7: Commit**

```bash
git add src/Deploy/MaintenanceMode.php src/Notify/Notifier.php src/Deploy/Deployer.php tests/Unit/Deploy/DeployerTest.php
git commit -m "feat(deployward): add Deployer safe-swap orchestration with auto-rollback"
```

---

### Task 12: WP-CLI commands, composition root, activation

**Files:**
- Create: `src/Plugin.php`
- Create: `src/Cli/DeployCommand.php`
- Test: `tests/Unit/Cli/DeployCommandTest.php`

**Interfaces:**
- Consumes: `DeploymentRepository`, `Deployer`, `DeployLog`, `Deployment`.
- Produces:
  - `Deployward\Plugin` with `static activate(): void` (creates the log table), `static boot(): void` (registers the WP-CLI command when running under WP-CLI), `static makeCommand(): DeployCommand` (composition root).
  - `Deployward\Cli\DeployCommand` with `__construct(DeploymentRepository $repository, Deployer $deployer, DeployLog $log)` and subcommands `add`, `list`, `deploy`, `rollback`, `log` (each `(array $args, array $assoc): void`).

- [ ] **Step 1: Write the failing test `tests/Unit/Cli/DeployCommandTest.php`**

```php
<?php

namespace Deployward\Tests\Unit\Cli;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Deployward\Cli\DeployCommand;
use Deployward\Config\Deployment;
use Deployward\Config\DeploymentRepository;
use Deployward\Deploy\Deployer;
use Deployward\Log\DeployLog;
use Deployward\Support\Result;
use Mockery;
use PHPUnit\Framework\TestCase;

final class DeployCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('sanitize_key')->returnArg();
        Functions\when('wp_generate_password')->justReturn('generated');
        if (! class_exists('WP_CLI')) {
            eval('class WP_CLI { public static $errors = array(); public static $success = array(); public static function error($m) { self::$errors[] = $m; throw new \Exception($m); } public static function success($m) { self::$success[] = $m; } public static function log($m) {} public static function line($m) {} }');
        }
        \WP_CLI::$errors = array();
        \WP_CLI::$success = array();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_add_saves_a_valid_deployment(): void
    {
        $repository = Mockery::mock(DeploymentRepository::class);
        $repository->shouldReceive('save')->once()->with(Mockery::type(Deployment::class));
        $command = new DeployCommand($repository, Mockery::mock(Deployer::class), Mockery::mock(DeployLog::class));

        $command->add(array(), array(
            'id' => 'abc',
            'repo' => 'Nara-IT/nara-core',
            'branch' => 'main',
            'visibility' => 'private',
            'type' => 'plugin',
            'slug' => 'nara-core',
            'token' => 'ghp_x',
        ));

        $this->assertNotEmpty(\WP_CLI::$success);
    }

    public function test_deploy_invokes_deployer_for_known_id(): void
    {
        $deployment = Deployment::fromArray(array(
            'id' => 'abc', 'repo' => 'Nara-IT/nara-core', 'branch' => 'main',
            'visibility' => 'public', 'target_type' => 'plugin', 'target_slug' => 'nara-core',
        ));
        $repository = Mockery::mock(DeploymentRepository::class);
        $repository->shouldReceive('find')->with('abc')->andReturn($deployment);
        $deployer = Mockery::mock(Deployer::class);
        $deployer->shouldReceive('deploy')->once()->with(Mockery::type(Deployment::class), 'manual', false)
            ->andReturn(Result::ok('newsha', 'Deployed newsha'));
        $command = new DeployCommand($repository, $deployer, Mockery::mock(DeployLog::class));

        $command->deploy(array('abc'), array());

        $this->assertNotEmpty(\WP_CLI::$success);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter DeployCommandTest`
Expected: FAIL with "Class Deployward\Cli\DeployCommand not found".

- [ ] **Step 3: Write `src/Cli/DeployCommand.php`**

```php
<?php

namespace Deployward\Cli;

use Deployward\Config\Deployment;
use Deployward\Config\DeploymentRepository;
use Deployward\Deploy\Deployer;
use Deployward\Log\DeployLog;

final class DeployCommand
{
    /** @var DeploymentRepository */
    private $repository;
    /** @var Deployer */
    private $deployer;
    /** @var DeployLog */
    private $log;

    public function __construct(DeploymentRepository $repository, Deployer $deployer, DeployLog $log)
    {
        $this->repository = $repository;
        $this->deployer = $deployer;
        $this->log = $log;
    }

    /**
     * Adds a deployment.
     *
     * ## OPTIONS
     *
     * --repo=<owner/repo>
     * : The GitHub repository.
     *
     * --slug=<slug>
     * : Target plugin or theme slug (destination directory name).
     *
     * [--id=<id>]
     * : Optional stable id. Generated when omitted.
     *
     * [--branch=<branch>]
     * : Branch to watch. Default: main.
     *
     * [--type=<type>]
     * : plugin, theme, or mu-plugin. Default: plugin.
     *
     * [--visibility=<visibility>]
     * : public or private. Default: public.
     *
     * [--token=<token>]
     * : GitHub fine-grained PAT for private repos.
     */
    public function add(array $args, array $assoc): void
    {
        $id = sanitize_key(isset($assoc['id']) ? $assoc['id'] : wp_generate_password(8, false));
        try {
            $deployment = Deployment::fromArray(array(
                'id' => $id,
                'repo' => isset($assoc['repo']) ? $assoc['repo'] : '',
                'branch' => isset($assoc['branch']) ? $assoc['branch'] : 'main',
                'visibility' => isset($assoc['visibility']) ? $assoc['visibility'] : 'public',
                'target_type' => isset($assoc['type']) ? $assoc['type'] : 'plugin',
                'target_slug' => isset($assoc['slug']) ? $assoc['slug'] : '',
                'token' => isset($assoc['token']) ? $assoc['token'] : '',
                'webhook_secret' => wp_generate_password(32, false),
            ));
        } catch (\InvalidArgumentException $e) {
            \WP_CLI::error($e->getMessage());
            return;
        }
        $this->repository->save($deployment);
        \WP_CLI::success('Added deployment ' . $id . ' (' . $deployment->repo() . ')');
    }

    /**
     * Lists deployments.
     *
     * @subcommand list
     */
    public function list(array $args, array $assoc): void
    {
        foreach ($this->repository->all() as $deployment) {
            \WP_CLI::line(sprintf(
                '%s  %s@%s  -> %s/%s  [%s]',
                $deployment->id(),
                $deployment->repo(),
                $deployment->branch(),
                $deployment->targetType(),
                $deployment->targetSlug(),
                $deployment->lastDeployedSha() === '' ? 'never' : substr($deployment->lastDeployedSha(), 0, 8)
            ));
        }
    }

    /**
     * Deploys a deployment now.
     *
     * ## OPTIONS
     *
     * <id>
     * : The deployment id.
     *
     * [--force]
     * : Deploy even when the latest commit is already deployed.
     */
    public function deploy(array $args, array $assoc): void
    {
        $deployment = $this->require_deployment($args);
        $force = isset($assoc['force']);
        $result = $this->deployer->deploy($deployment, 'manual', $force);
        if ($result->isOk()) {
            \WP_CLI::success($result->message());
            return;
        }
        \WP_CLI::error($result->message());
    }

    /**
     * Rolls a deployment back to its previous version.
     *
     * ## OPTIONS
     *
     * <id>
     * : The deployment id.
     */
    public function rollback(array $args, array $assoc): void
    {
        $deployment = $this->require_deployment($args);
        $result = $this->deployer->rollback($deployment);
        if ($result->isOk()) {
            \WP_CLI::success('Rolled back ' . $deployment->id());
            return;
        }
        \WP_CLI::error($result->message());
    }

    /**
     * Shows recent deploy log entries for a deployment.
     *
     * ## OPTIONS
     *
     * <id>
     * : The deployment id.
     */
    public function log(array $args, array $assoc): void
    {
        $id = isset($args[0]) ? $args[0] : '';
        foreach ($this->log->recent($id, 20, 0) as $row) {
            \WP_CLI::line(sprintf(
                '%s  %s  %s  %s',
                isset($row['created_at']) ? $row['created_at'] : '',
                isset($row['status']) ? $row['status'] : '',
                isset($row['sha']) ? substr((string) $row['sha'], 0, 8) : '',
                isset($row['message']) ? $row['message'] : ''
            ));
        }
    }

    private function require_deployment(array $args): Deployment
    {
        $id = isset($args[0]) ? $args[0] : '';
        $deployment = $this->repository->find($id);
        if ($deployment === null) {
            \WP_CLI::error('No deployment found with id ' . $id);
        }

        return $deployment;
    }
}
```

Note: `WP_CLI::error()` halts execution in real WP-CLI, so `require_deployment` returning after `error()` is only reached in tests (where `error()` is stubbed to throw). Tests for the not-found path should expect the thrown exception.

- [ ] **Step 4: Write `src/Plugin.php`**

```php
<?php

namespace Deployward;

use Deployward\Cli\DeployCommand;
use Deployward\Config\DeploymentRepository;
use Deployward\Deploy\BackupManager;
use Deployward\Deploy\Deployer;
use Deployward\Deploy\Extractor;
use Deployward\Deploy\HealthChecker;
use Deployward\Deploy\MaintenanceMode;
use Deployward\Deploy\PayloadValidator;
use Deployward\GitHub\GitHubClient;
use Deployward\Log\DeployLog;
use Deployward\Notify\Notifier;
use Deployward\Security\Encryptor;

final class Plugin
{
    public static function activate(): void
    {
        global $wpdb;
        $log = new DeployLog($wpdb);
        $log->installTable();
    }

    public static function boot(): void
    {
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('deployward', self::makeCommand());
        }
    }

    public static function makeCommand(): DeployCommand
    {
        global $wpdb;

        $repository = new DeploymentRepository(Encryptor::fromSalts());
        $uploads = wp_upload_dir();
        $backupsBase = trailingslashit($uploads['basedir'])
            . 'deployward-backups-' . substr(md5(AUTH_SALT), 0, 8);

        $deployer = new Deployer(
            new GitHubClient(),
            new Extractor(get_temp_dir()),
            new PayloadValidator(),
            new BackupManager($backupsBase),
            new HealthChecker(),
            new MaintenanceMode(ABSPATH),
            new DeployLog($wpdb),
            $repository,
            new Notifier((string) get_option('admin_email', '')),
            array(
                'plugin' => WP_PLUGIN_DIR,
                'theme' => get_theme_root(),
                'mu-plugin' => defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins',
            ),
            home_url('/'),
            get_temp_dir(),
            defined('DEPLOYWARD_SLUG') ? DEPLOYWARD_SLUG : 'deployward',
            3
        );

        return new DeployCommand($repository, $deployer, new DeployLog($wpdb));
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit`
Expected: PASS (entire suite green).

- [ ] **Step 6: Manual verification on the Local site**

Run from the WordPress root (using the repo's WP-CLI shim):

```bash
./wp-cli.phar plugin activate deployward
./wp-cli.phar deployward add --repo=Nara-IT/nara-core --branch=main --type=plugin --slug=nara-core --visibility=private --token=YOUR_FINE_GRAINED_PAT
./wp-cli.phar deployward list
./wp-cli.phar deployward deploy <id>
./wp-cli.phar deployward log <id>
```

Expected: `add` reports success, `list` shows the deployment, `deploy` resolves the latest commit, swaps safely, and reports the deployed SHA; `log` shows a `success` row. Confirm `wp-content/plugins/nara-core` still loads with no fatal. Do this against the Local site first, never production, until reviewed.

- [ ] **Step 7: Commit**

```bash
git add src/Plugin.php src/Cli/DeployCommand.php tests/Unit/Cli/DeployCommandTest.php
git commit -m "feat(deployward): add WP-CLI commands, composition root, and activation"
```

---

## Self-Review

**1. Spec coverage (Plan 1 portion):**
- Per-site agent model, Deployment config fields: Tasks 3, 4. Covered.
- Raw-branch deploy via GitHub zipball over HTTPS: Task 6. Covered.
- Encrypted PAT at rest (salts-derived), public repos need no token: Tasks 2, 4; `tokenOrNull` in Task 11. Covered. (The optional `wp-config` constant override is deferred to the admin/settings work in a later plan; noted here as a gap to schedule.)
- Validate, atomic swap, maintenance mode, health check, auto-rollback, versioned backups (keep 3), self-protection: Tasks 7, 8, 9, 10, 11. Covered.
- Audit log table + records, email notification: Tasks 5, 11. Covered.
- Manual trigger: Task 12 WP-CLI `deploy`/`rollback`. Covered.
- Webhook, WP-Cron polling, admin UI: deliberately deferred to Plan 2 and Plan 3 (stated in the plan intro). Not gaps in this plan.

**2. Placeholder scan:** No "TBD"/"TODO"/"implement later". Every code step contains complete code. The Deployer test carries an explicit implementer note to convert mock-based swap assertions to temp-dir assertions; that is guidance for reaching coverage, not a placeholder implementation.

**3. Type consistency:** `Result` factory and accessor names are consistent across all tasks. `Deployment` getters (`targetType`, `targetSlug`, `lastDeployedSha`, etc.) match between definition (Task 3) and consumers (Tasks 4, 11, 12). `DeployLog::record` accepts the `trigger` key and stores it as `trigger_source` consistently (Tasks 5, 11). `Deployer` constructor signature matches the composition root in Task 12 and the test in Task 11.

Identified follow-up (not a blocker for Plan 1): schedule the `wp-config` constant token override during Plan 3 (settings UI), since it pairs naturally with token entry.
