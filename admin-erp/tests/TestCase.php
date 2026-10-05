<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No test may reach the real network. Found 2026-10-05: one carousel test flushed its queued revalidation
        // BEFORE faking HTTP, so every run of the suite — including every build-release.sh — sent a real (wrongly
        // signed, so rejected) POST to https://provatferi.org/api/revalidate. A request that no Http::fake() stub
        // answers now fails the test that made it instead of leaving the building.
        Http::preventStrayRequests();
    }
}
