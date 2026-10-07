<?php
declare(strict_types=1);

namespace Shugoi\Laravel\Commands;

use Illuminate\Console\Command;
use Shugoi\Config;
use Shugoi\ApiClient;
use Shugoi\RenderService;
use Shugoi\WebSocket\OpenSwooleRenderServer;

final class ShugoiWebSocketCommand extends Command
{
    protected $signature = 'shugoi:websocket';
    protected $description = 'Run the optional Shugoi WebSocket render sidecar';

    public function handle(RenderService $renderService, Config $config, ApiClient $apiClient): int
    {
        try {
            (new OpenSwooleRenderServer($config, $renderService, $apiClient))->start();
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
