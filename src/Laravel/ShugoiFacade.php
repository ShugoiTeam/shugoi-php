<?php
declare(strict_types=1);
namespace Shugoi\Laravel;

use Illuminate\Support\Facades\Facade;

class ShugoiFacade extends Facade
{
    protected static function getFacadeAccessor(): string { return 'shugoi.middleware'; }
}
