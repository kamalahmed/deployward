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
