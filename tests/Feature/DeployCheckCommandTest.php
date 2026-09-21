<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeployCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_deploy_check_passes_when_the_deployment_is_complete(): void
    {
        $this->artisan('app:deploy-check')
            ->expectsOutputToContain('dashboards tables migrated')
            ->expectsOutputToContain('WidgetWizard autoloadable')
            ->assertSuccessful();
    }
}
