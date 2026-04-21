<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallCommand extends Command
{
    protected $signature = 'gunma:install';
    protected $description = 'Install the Gunma AI Agent package';

    public function handle()
    {
        $this->info('Installing Gunma AI Agent...');

        $this->publishConfig();
        $this->publishMigrations();
        
        $this->info('Gunma AI Agent installed successfully.');
        $this->info('Please run [php artisan migrate] to create the necessary tables.');
        $this->info('Configure your OpenAI and Qdrant credentials in [.env] or [config/gunma-agent.php].');

        return 0;
    }

    private function publishConfig()
    {
        $this->call('vendor:publish', [
            '--provider' => 'Anwar\GunmaAgent\GunmaAgentServiceProvider',
            '--tag' => 'config',
        ]);
    }

    private function publishMigrations()
    {
        $this->call('vendor:publish', [
            '--provider' => 'Anwar\GunmaAgent\GunmaAgentServiceProvider',
            '--tag' => 'migrations',
        ]);
    }
}
