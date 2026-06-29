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
        $encryptedToken = $deployment->token() !== '' ? $this->encryptor->encrypt($deployment->token()) : '';
        $row = array_merge($deployment->toArray(), array('token' => $encryptedToken));
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
            error_log('Deployward: failed to decrypt a stored token: ' . $e->getMessage());
            return '';
        }
    }
}
