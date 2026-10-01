<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Shugoi\Config;
use Shugoi\Obfuscator;
use Shugoi\SkeletonGenerator;
use Shugoi\TokenSigner;

$obfuscator = new Obfuscator();
$examples = [
    <<<'JS'
function compact(){return"ok"} function thrown(){try{throw"error"}catch(e){return e}} window.result=[compact(),thrown(),typeof"text"];
JS,
    <<<'JS'
window.result = /n\'est pas autorisé|not authorized/i.test("not authorized");
JS,
    <<<'JS'
var hit = false; if (true) hit = /['"/*]+/.test("'/*"); window.result = [hit, 12 / 3 / 2];
JS,
    <<<'JS'
window.result = `literal ' // /* ${`nested ${"value"}`} */`;
JS,
    <<<'JS'
function value(){return/*
leave this line break */"not returned"} window.result = [value() === undefined, typeof/**/window];
JS,
    <<<'JS'
window.result = ["http:\/\/127.0.0.1:4196\/api\/v1", "caf\u00e9", "Accès restreint", "世界 👋", "\ud83d\ude00", "\u{1F600}", "\ud800", "\x2f", "\n\r\t\b\f\v\0", "\\u0061", "\\n", "quote\"apostrophe'", 'apostrophe\'"', "\141\377\400\08\9"];
JS,
];
$pairs = array_map(fn(string $source) => ['source' => $source, 'generated' => $obfuscator->obfuscate($source, 'syntax-fixture')], $examples);
$config = new Config(['siteKey' => 'sg_fixture', 'secret' => 'synthetic-fixture-secret']);
$signer = new TokenSigner($config);
$bootstrap = (new SkeletonGenerator($signer, $obfuscator))->generate(
    $signer->sign((int)(microtime(true) * 1000)),
    ['detect' => 'window.__sg_guardsReady=true;', 'guard' => ''],
    ['detectionFlags' => []], false, 'fr', 'https://shugoi.com/api/v1', '/__shugoi/render', 'sg_fixture',
);
echo json_encode(['pairs' => $pairs, 'bootstrap' => substr($bootstrap, 8, -9)], JSON_THROW_ON_ERROR);
