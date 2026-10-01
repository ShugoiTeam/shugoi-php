<?php
declare(strict_types=1);
namespace Shugoi\Laravel;

use Illuminate\Support\Facades\Blade;

class ShugoiBladeDirective
{
    public static function register(): void
    {
        Blade::directive('shugoiHead', function () {
            return "<?php \$__sg_result = app('shugoi.scripts')->generate(); echo \$__sg_result['guardDetect']; ?>";
        });
        Blade::directive('shugoiBody', function () {
            return "<?php // Shugoi body injection handled by middleware (autoInject) ?>";
        });
    }
}
