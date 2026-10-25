<?php

namespace Tests\Feature;

use App\Support\ContentSecurityPolicy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ContentSecurityPolicyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_chart_styles_and_preview_scripts_use_the_response_nonce_and_it_changes_per_request(): void
    {
        [$operator, $store] = $this->userWithStore(true);
        $store->auditSnapshots()->create(['tool' => 'run_audit', 'report_date' => today(), 'start_date' => today(), 'end_date' => today(), 'rows_found' => 1, 'result' => ['missing' => []]]);
        $response = $this->actingAs($operator)->get(route('dashboard'))->assertOk();
        $this->assertSame(1, preg_match("/'nonce-([^']+)'/", $response->headers->get('Content-Security-Policy'), $first));
        $response->assertSee('<style nonce="'.$first[1].'">#audit-chart-bar-', false);

        $preview = $this->get(route('orders.push.create'))->assertOk();
        $this->assertSame(1, preg_match("/'nonce-([^']+)'/", $preview->headers->get('Content-Security-Policy'), $second));
        $preview->assertSee('<script nonce="'.$second[1].'">', false);
        $this->assertNotSame($first[1], $second[1]);
    }

    public function test_policy_enforces_script_and_style_nonces_by_default(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk()->assertHeaderMissing('Content-Security-Policy-Report-Only');

        $policy = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9+\/=]+'/", $policy);
        $this->assertStringNotContainsString('unsafe-inline', $policy);
        $this->assertStringNotContainsString('unsafe-eval', $policy);
    }

    public function test_report_only_mode_can_be_enabled_explicitly(): void
    {
        config()->set('csp.presets', []);
        config()->set('csp.report_only_presets', [ContentSecurityPolicy::class]);

        $response = $this->get(route('login'));

        $response->assertOk()
            ->assertHeader('Content-Security-Policy-Report-Only')
            ->assertHeaderMissing('Content-Security-Policy');
    }
}
