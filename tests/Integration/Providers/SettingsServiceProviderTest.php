<?php

namespace Tests\Integration\Providers;

use App\Contracts\Repository\SettingsRepositoryInterface;
use App\Providers\SettingsServiceProvider;
use App\Support\InstallationState;
use Tests\Integration\IntegrationTestCase;

class SettingsServiceProviderTest extends IntegrationTestCase
{
    public function test_it_does_not_load_database_settings_when_environment_only_mode_is_enabled(): void
    {
        $settings = $this->app->make(SettingsRepositoryInterface::class);
        $settings->set('settings::app:name', 'Database Name');

        config()->set('app.name', 'Environment Name');
        config()->set('panel.load_environment_only', true);

        (new SettingsServiceProvider($this->app))->boot(
            config(),
            $this->app->make(InstallationState::class),
            $settings,
        );

        $this->assertSame('Environment Name', config('app.name'));
    }
}
