<?php

namespace Tests\Feature;

use App\Services\HostingerMailService;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Regression test for a real bug caught while building this app's own
 * pre-deploy pipeline: HostingerMailService is bound as a container
 * singleton, so simply type-hinting it — even just to ask isConfigured() —
 * forces its factory to run. The factory used to throw immediately when no
 * token was configured, which meant isConfigured() itself was unreachable
 * in any environment without the token (CI, this app's own test suite
 * before this fix, a pre-deploy build's isolated tree copied without .env).
 */
class HostingerMailServiceUnconfiguredTest extends TestCase
{
    public function test_resolving_the_service_without_a_token_does_not_throw(): void
    {
        Config::set('services.hostinger_mail.token', null);

        $service = app(HostingerMailService::class);

        $this->assertFalse($service->isConfigured());
    }

    public function test_isConfigured_is_true_once_a_token_is_set(): void
    {
        Config::set('services.hostinger_mail.token', 'fake-token-for-test');

        $service = app(HostingerMailService::class);

        $this->assertTrue($service->isConfigured());
    }
}
