<?php
// @codeCoverageIgnoreStart
namespace Pillar\Console;

use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;

final class InstallPillarCommand extends Command
{
    protected $signature = 'pillar:install';
    protected $description = 'Install Pillar (publish config, migrations, etc.)';

    public function handle(): int
    {
        $this->info('⚙️  Installing Pillar...');

        // Publish migrations
        if (confirm('Publish the events and aggregate_versions table migrations?', default: true)) {
            $this->call('vendor:publish', [
                '--provider' => 'Pillar\\Provider\\PillarServiceProvider',
                '--tag' => 'migrations',
            ]);

            // Optionally run migrations right away
            if (confirm('Run database migrations now?', default: true)) {
                $this->call('migrate');
            }
        }

        // Publish config
        if (confirm('Publish the configuration file?', default: true)) {
            $this->call('vendor:publish', [
                '--provider' => 'Pillar\\Provider\\PillarServiceProvider',
                '--tag' => 'config',
            ]);
        }

        $this->newLine();
        $this->info('✅ Pillar installation complete!');
        $this->newLine();

        return self::SUCCESS;
    }
}
// @codeCoverageIgnoreEnd
