<?php

namespace Tests\Feature\Middleware;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttachRequestContextTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_valid_incoming_request_id_is_echoed_back(): void
    {
        $id = (string) Str::uuid();

        $this->withHeader('X-Request-ID', $id)->get(route('login'))->assertHeader('X-Request-ID', $id);
    }

    public function test_a_missing_or_malformed_request_id_is_replaced_by_a_fresh_uuid(): void
    {
        $generated = $this->get(route('login'))->headers->get('X-Request-ID');
        $replaced = $this->withHeader('X-Request-ID', "evil\r\nSet-Cookie: x=1")->get(route('login'))->headers->get('X-Request-ID');

        $this->assertTrue(Str::isUuid((string) $generated));
        $this->assertTrue(Str::isUuid((string) $replaced));
        $this->assertNotSame($generated, $replaced);
    }
}
