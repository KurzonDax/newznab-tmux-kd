<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\UpdateNNTmux;
use Tests\TestCase;

class UpdateNNTmuxMaintenanceTest extends TestCase
{
    public function test_maintenance_options_request_a_generated_secret(): void
    {
        $options = $this->commandMaintenanceOptions();

        $this->assertSame([
            '--render' => 'errors::maintenance',
            '--retry' => 120,
            '--with-secret' => true,
        ], $options);
        $this->assertArrayNotHasKey('--secret', $options);
    }

    public function test_down_with_maintenance_options_uses_a_random_bypass_secret(): void
    {
        // `down` writes framework/down and framework/maintenance.php under
        // storage_path(), so keep both out of the shared storage directory.
        $storage = $this->makeTempDirectory('nntmux-storage');
        mkdir($storage.'/framework');
        $this->app->useStoragePath($storage);

        try {
            $this->artisan('down', $this->commandMaintenanceOptions())->assertSuccessful();

            $secret = $this->app->maintenanceMode()->data()['secret'] ?? null;

            $this->assertIsString($secret);
            $this->assertNotSame('', $secret);
            $this->assertNotSame(config('app.key'), $secret);
        } finally {
            $this->artisan('up');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function commandMaintenanceOptions(): array
    {
        $command = new class extends UpdateNNTmux
        {
            /**
             * @return array<string, mixed>
             */
            public function exposedMaintenanceOptions(): array
            {
                return $this->maintenanceOptions();
            }
        };

        return $command->exposedMaintenanceOptions();
    }
}
