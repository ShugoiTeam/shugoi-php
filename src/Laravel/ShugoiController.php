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
        $headers = [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, no-transform',
            'Pragma' => 'no-cache',
        ];
        if ($request->isMethod('HEAD')) {
            return response('', 200, ['Content-Type' => 'application/json'] + $headers);
        }
        if (!$request->isMethod('GET')) {
            return response()->json(['error' => 'method_not_allowed'], 405, ['Allow' => 'GET, HEAD'] + $headers);
        }
        $params = $request->query->all();
        $token = is_string($params['token'] ?? null) ? $params['token'] : '';
        $mid = is_string($params['mid'] ?? null) ? $params['mid'] : '';
        $grant = is_string($params['grant'] ?? null) ? $params['grant'] : '';
        $ip = $request->getClientIp() ?? '';

        $data = $this->renderService->render($token, $mid, $grant, $ip);

        if (isset($data['html'])) {
            $headers['Referrer-Policy'] = 'strict-origin-when-cross-origin';
            $headers['Cache-Control'] = 'no-store, no-cache, must-revalidate, no-transform';
            $headers['Pragma'] = 'no-cache';
            $headers['Set-Cookie'] = $this->pow->sgAuthorizedCookie($request->isSecure());
        }
        return response()->json($data, 200, $headers);
    }

}
