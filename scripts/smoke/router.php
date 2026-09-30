<?php
declare(strict_types=1);

// Local fixture only: synthetic credentials and a loopback API, never production.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Shugoi\{ApiClient, Config, ConfigCache, Core, CspBuilder, GuardCache, GuardInjector, HtmlStore, Middleware, Pow, TokenSigner};

$config = new Config([
    'siteKey' => 'sg_sk_test_php_smoke',
    'secret' => 'synthetic-local-php-smoke-secret-not-production',
    'baseUrl' => 'http://127.0.0.1:4196/api/v1',
    'internalUrl' => 'http://127.0.0.1:4196/api/v1',
    'powDifficulty' => 4,
    'powWebSocketUrl' => 'ws://127.0.0.1:4197/__sg_challenge/ws',
    'locale' => 'fr',
    'debug' => true,
    'verifyBots' => true,
]);
$api = new ApiClient($config);
$configCache = new ConfigCache($api);
$guardCache = new GuardCache($api);
$store = new HtmlStore(sys_get_temp_dir() . '/shugoi-php-local-smoke');
$signer = new TokenSigner($config);
$pow = new Pow($config);
$core = new Core($config, $api, $pow, $configCache);
$injector = new GuardInjector($config, $signer, $store, $guardCache, $configCache, new \Shugoi\SkeletonGenerator($signer, new \Shugoi\Obfuscator()));
$middleware = new Middleware($config, $core, $api, $configCache, $guardCache, $store, new CspBuilder($config), $injector, $signer, $pow);

$request = new ServerRequest($_SERVER['REQUEST_METHOD'], 'http://127.0.0.1:4195' . $_SERVER['REQUEST_URI'], getallheaders(), file_get_contents('php://input'), '1.1', $_SERVER);
$request = $request->withQueryParams($_GET)->withCookieParams($_COOKIE);
if (str_starts_with($request->getUri()->getPath(), '/nested/')) {
    $request = $request->withAttribute('shugoi.basePath', '/nested');
}
$handler = new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = htmlspecialchars($request->getUri()->getPath(), ENT_QUOTES, 'UTF-8');
        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SDK PHP · smoke local</title><style>body{font:18px system-ui;background:#121117;color:#f7f1e8;max-width:740px;margin:10vh auto;padding:24px}h1{font-size:42px}a{color:#bcafff}button{font:inherit;padding:12px 18px;background:#d2c6ff;border:0;border-radius:6px;color:#171122}nav{display:flex;gap:24px;flex-wrap:wrap;margin-top:28px}code{color:#c6e6c4}</style></head><body><p>FIXTURE LOCALE · HTTP + WEBSOCKET</p><h1 id="smoke-success">Le HTML PHP a été libéré.</h1><p>La décision et le grant signés sont synthétiques. Le transport navigateur et la validation PHP utilisent le SDK réel.</p><p>Route : <code>' . $path . '</code></p><button id="smoke-button" type="button">Vérifier l’interaction</button><p id="smoke-result" role="status">En attente</p><nav><a href="/next">Page suivante</a><a href="/slow">Réponse lente (1,9 s)</a><a href="/nested/page">Chemin imbriqué</a><a href="/denied">Scénario de refus</a></nav><script>document.getElementById("smoke-button").addEventListener("click",function(){document.getElementById("smoke-result").textContent="Interaction validée"})</script></body></html>');
    }
};
$response = $middleware->process($request, $handler);
http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) header($name . ': ' . $value, false);
}
echo $response->getBody();
