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
