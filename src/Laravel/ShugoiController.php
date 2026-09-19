<?php
declare(strict_types=1);
namespace Shugoi\Laravel;

use Illuminate\Http\Request;
use Shugoi\Pow;
use Shugoi\RenderService;

class ShugoiController
{
    public function __construct(
        private RenderService $renderService,
        private Pow $pow,
    ) {}

    public function render(Request $request)
    {
        $token = (string)$request->query('token', '');
        $mid = (string)$request->query('mid', '');
        $grant = (string)$request->query('grant', '');
        $ip = $request->header('X-Forwarded-For', '') ?: (string)($request->server('REMOTE_ADDR', ''));
        $ip = trim(explode(',', $ip)[0]);

        $data = $this->renderService->render($token, $mid, $grant, $ip);

        $headers = [];
        if (isset($data['html'])) {
            $headers['Referrer-Policy'] = 'strict-origin-when-cross-origin';
            $headers['Cache-Control'] = 'no-store, no-cache, must-revalidate, no-transform';
            $headers['Pragma'] = 'no-cache';
            $headers['Set-Cookie'] = $this->pow->sgAuthorizedCookie();
        }
        return response()->json($data, 200, $headers);
    }

}
