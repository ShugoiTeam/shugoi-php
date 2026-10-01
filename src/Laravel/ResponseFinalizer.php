<?php
declare(strict_types=1);

namespace Shugoi\Laravel;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;

final class ResponseFinalizer
{
    private const ATTRIBUTE = 'shugoi.finalizeResponse';
    private bool $registered = false;

    public function register(Dispatcher $events): void
    {
        if ($this->registered) return;
        $this->registered = true;
        // Laravel runs wildcard listeners after ordinary listeners, including
        // Livewire's @assets injection, even when those are registered later.
        $events->listen(RequestHandled::class . '*', function (string $name, array $payload): void {
            if ($name !== RequestHandled::class || !(($payload[0] ?? null) instanceof RequestHandled)) return;
            $event = $payload[0];
            $finalize = $event->request->attributes->get(self::ATTRIBUTE);
            $event->request->attributes->remove(self::ATTRIBUTE);
            if (!$finalize instanceof Closure) return;
            try {
                $finalize($event->response);
            } catch (\Throwable $error) {
                // Never send the temporarily unprotected application document.
                $event->response->setContent('Shugoi protection is temporarily unavailable. Please try again.');
                $event->response->setStatusCode(503);
                $event->response->headers->set('Content-Type', 'text/plain; charset=UTF-8');
                $event->response->headers->set('Cache-Control', 'no-store');
                $event->response->headers->remove('Content-Length');
                report($error);
            }
        });
    }

    public function isRegistered(): bool
    {
        return $this->registered;
    }

    public function defer(Request $request, Closure $finalize): void
    {
        $request->attributes->set(self::ATTRIBUTE, $finalize);
    }
}
