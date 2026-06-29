<?php

namespace Deployward\GitHub;

use Deployward\Support\Result;

final class GitHubClient implements GitHubClientInterface
{
    private const API = 'https://api.github.com';

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
        if (! is_file($destFile) || filesize($destFile) < 1) {
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
