<?php
declare(strict_types=1);

namespace Shugoi;

/** Browser glue only: authorization and HTML delivery remain server-side. */
final class BrowserTransport
{
    public static function script(): string
    {
        static $script = null;
        if ($script === null) {
            $script = file_get_contents(__DIR__ . '/../resources/transport.js');
            if ($script === false) {
                throw new \RuntimeException('Shugoi browser transport resource is missing');
            }
        }
        return $script;
    }
}
