<?php
declare(strict_types=1);

namespace Shugoi;

class Locales
{
    private const FR = [
        'rateLimitTitle' => 'Trop de requêtes',
        'rateLimitBody' => "Vous avez effectué trop de requêtes en peu de temps. Il reste %s avant de pouvoir réessayer.",
        'rateLimitBadge' => 'Rate Limit',
        'blockedTitle' => 'Accès bloqué',
        'blockedBadge' => 'Blocage',
        'tamperTitle' => 'Remplacement de contenu client détecté',
        'tamperBody' => "Nous avons remarqué que vous avez tenté de modifier manuellement le rendu client côté navigateur via les DevTools. Cette pratique est évidemment bloquée par nos services.",
        'devtoolsBody' => "L'utilisation des DevTools pour remplacer le contenu ou modifier les requêtes réseau a été détectée. L'intégrité de la page est protégée et toute altération est immédiatement bloquée.",
        'retryInSeconds' => 'Il reste %ds avant de pouvoir réessayer.',
        'restrictedTitle' => 'Accès restreint',
        'restrictedBody' => "L'accès à ce site est limité. Votre appareil n'est pas autorisé. Contactez le support à %s en communiquant le machineId ci-dessous.",
        'serviceUnavailableTitle' => 'Service temporairement indisponible',
        'renderFailedBody' => 'Le rendu a échoué, veuillez réessayer.',
    ];
    private const EN = [
        'rateLimitTitle' => 'Too Many Requests',
        'rateLimitBody' => "You have made too many requests in a short time. %s remaining before you can try again.",
        'rateLimitBadge' => 'Rate Limit',
        'blockedTitle' => 'Access Blocked',
        'blockedBadge' => 'Blocked',
        'tamperTitle' => 'Client Content Replacement Detected',
        'tamperBody' => "We noticed you attempted to manually modify the client-side rendering via DevTools. This practice is obviously blocked by our services.",
        'devtoolsBody' => "Using DevTools to replace content or modify network requests has been detected. Page integrity is protected and any alteration is immediately blocked.",
        'retryInSeconds' => 'Retry in %ds.',
        'restrictedTitle' => 'Restricted Access',
        'restrictedBody' => 'Access to this site is limited. Your device is not authorized. Contact support at %s and provide the machineId below.',
        'serviceUnavailableTitle' => 'Service temporarily unavailable',
        'renderFailedBody' => 'Rendering failed. Please try again.',
    ];
    public static function get(string $locale, string $key, mixed ...$args): string
    {
        $messages = $locale === 'fr' ? self::FR : self::EN;
        $msg = $messages[$key] ?? $key;
        return empty($args) ? $msg : sprintf($msg, ...$args);
    }
    public static function getAll(string $locale): array
    {
        return $locale === 'fr' ? self::FR : self::EN;
    }
}
