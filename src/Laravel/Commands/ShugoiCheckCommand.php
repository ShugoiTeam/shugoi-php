<?php
declare(strict_types=1);
namespace Shugoi\Laravel\Commands;

use Illuminate\Console\Command;
use Shugoi\ApiClient;
use Shugoi\Config;

class ShugoiCheckCommand extends Command
{
    protected $signature = 'shugoi:check {action : Action name (e.g. login, purchase)} {--ip= : Client IP} {--email= : Client email}';
    protected $description = 'Check a license/action against Shugoi API';

    public function handle(ApiClient $api, Config $config): int
    {
        $action = $this->argument('action');
        $ip = $this->option('ip');
        $email = $this->option('email');

        $this->line("Checking action '{$action}'...");
        try {
            $result = $api->checkLicense([
                'action' => $action,
                'metadata' => array_filter(['ip' => $ip, 'email' => $email]),
            ]);
            if ($result['allowed'] ?? false) {
                $this->info('Allowed');
                $this->line("  Remaining: {$result['remaining']}");
                $this->line("  Request ID: {$result['requestId']}");
            } elseif ($result['blocked'] ?? false) {
                $this->warn("Blocked: {$result['blocked_reason']}");
                $this->line("  Reason: {$result['reason']}");
                if ($result['captcha'] ?? false) {
                    $this->line("  CAPTCHA required (difficulty: {$result['captcha']['difficulty']})");
                }
            } else {
                $this->error("Error: {$result['error']}");
                return Command::FAILURE;
            }
        } catch (\Throwable $e) {
            $this->error('API error: ' . $e->getMessage());
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }
}
