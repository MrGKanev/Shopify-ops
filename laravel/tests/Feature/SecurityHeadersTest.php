<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_responses_include_security_headers_and_a_request_id(): void
    {
        $requestId = '018f5e8d-7f3b-7a1c-8d9e-123456789abc';

        $this->withHeader('X-Request-ID', $requestId)->get('/up')
            ->assertOk()
            ->assertHeader('X-Request-ID', $requestId)
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function test_hsts_is_sent_only_for_secure_requests_when_enabled(): void
    {
        config()->set('security.hsts', true);

        $this->get('/up')->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://localhost/up')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
