<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Регрессия на security-audit-2026-07-24 MAJ-4: ни один security-заголовок
 * раньше не выставлялся вообще.
 */
class SecurityHeadersTest extends TestCase
{
    public function test_response_has_security_headers(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy');
    }
}
