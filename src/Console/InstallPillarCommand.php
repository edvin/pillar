<?php
// @codeCoverageIgnoreStart
namespace Pillar\Console;


use Illuminate\Console\Command;

class InstallPillarCommand extends Command
{
    protected $signature = 'pillar:install';
    protected $description = 'Install Pillar (publish config, migrations, etc.)';

    public function handle(): int
    {
        $this->info('⚙️  Installing Pillar...');

        if ($this->confirm('Would you like to publish the events and aggregate_versions table migrations?', true)) {
            $this->call('vendor:publish', [
                '--provider' => 'Pillar\\Provider\\PillarServiceProvider',
                '--tag' => 'migrations',
            ]);
        }

        if ($this->confirm('Would you like to publish the configuration file?', true)) {
            $this->call('vendor:publish', [
                '--provider' => 'Pillar\\Provider\\PillarServiceProvider',
                '--tag' => 'config',
            ]);
        }

        $this->newLine();
        $this->info('✅ Pillar installation complete!');
        $this->newLine();
        $this->line('You can now run your database migrations as usual:');
        $this->comment('php artisan migrate');

        return self::SUCCESS;
    }
}
// @codeCoverageIgnoreEnd