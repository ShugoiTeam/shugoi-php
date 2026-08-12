<?php
declare(strict_types=1);
namespace Shugoi\Laravel\Commands;

use Illuminate\Console\Command;
use Shugoi\ApiClient;
use Shugoi\Config;

class ShugoiSetupCommand extends Command
{
    protected $signature = 'shugoi:setup';
    protected $description = 'Validate Shugoi configuration and test API connectivity';

    public function handle(ApiClient $api, Config $config): int
    {
        $this->info('Shugoi Setup Verification');
        $this->newLine();
        $this->line("Site Key: {$config->siteKey}");
        $this->line("API URL: {$config->baseUrl}");
        $this->line("Internal URL: {$config->internalUrl}");
        $this->line("Secret: " . ($config->secret ? '<info>configured</info>' : '<error>not set</error>'));
        $this->newLine();

        $this->line('Validating site key...');
        try {
            $result = $api->validateKey();
            if ($result['valid'] ?? false) {
                $this->info('Site key is valid (mode: ' . ($result['mode'] ?? 'unknown') . ')');
            } else {
                $this->error('Site key validation failed: ' . ($result['error'] ?? 'unknown error'));
                return Command::FAILURE;
            }
        } catch (\Throwable $e) {
            $this->error('API unreachable: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $this->newLine();
        $this->line('Fetching guard scripts...');
        try {
            $detect = $api->fetchGuardDetect();
            $guard = $api->fetchGuard();
            $this->info('Guard-detect: ' . strlen($detect) . ' bytes');
            $this->info('Guard: ' . strlen($guard) . ' bytes');
        } catch (\Throwable $e) {
            $this->warn('Could not fetch guards: ' . $e->getMessage());
        }

        $this->newLine();
        $this->info('Setup verification complete.');
        return Command::SUCCESS;
    }
}
