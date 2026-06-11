<?php

namespace App\Services\Analytics;

use Illuminate\Http\Request;

/**
 * Heurísticas para marcar (no descartar) escaneos sospechosos del menú público.
 *
 * El flag `is_bot` se persiste en menu_scan_events y los índices parciales de
 * reportes filtran `WHERE is_bot = false` → los bots quedan auditables sin
 * contaminar agregados. Mejor que descartar porque permite auditar la calidad
 * del filtro en cualquier momento.
 *
 * Heurísticas combinadas (cualquiera marca como bot):
 *  1. UA en blocklist (regex sobre fragmentos comunes de scrapers).
 *  2. Sin Referer o Referer que no apunte a la URL del menú público.
 *  3. Honeypot: campo `_h` vino con valor (frontend siempre lo manda vacío).
 *  4. Frecuencia anómala (>3 POSTs en 1s del mismo ip_hash) — ver MenuController.
 */
class BotDetectionService
{
    private const UA_BLOCKLIST_REGEX = '/(bot|crawl|spider|slurp|curl|wget|python-requests?|scrapy|headless|phantomjs|httpclient|monitoring|uptime|preview|fetch|http_request)/i';

    public function isBot(Request $request, string $companyNit): bool
    {
        if ($this->honeypotTriggered($request)) {
            return true;
        }

        if ($this->userAgentBlocklisted((string) $request->userAgent())) {
            return true;
        }

        if (! $this->refererPointsToPublicMenu($request, $companyNit)) {
            return true;
        }

        return false;
    }

    /**
     * Hash determinístico de la IP con APP_KEY como sal — sin PII directa, pero
     * útil para detectar misma fuente sin almacenar la IP cruda.
     */
    public function hashIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        return hash('sha256', $ip.'|'.config('app.key'));
    }

    private function honeypotTriggered(Request $request): bool
    {
        $value = $request->input('_h');

        return is_string($value) && $value !== '';
    }

    private function userAgentBlocklisted(string $userAgent): bool
    {
        if ($userAgent === '') {
            return true;
        }

        return preg_match(self::UA_BLOCKLIST_REGEX, $userAgent) === 1;
    }

    /**
     * El escáner abre /menus/{nit} primero; al hacer fetch de telemetría el navegador
     * envía Referer apuntando a esa URL. Un curl directo al endpoint no.
     */
    private function refererPointsToPublicMenu(Request $request, string $companyNit): bool
    {
        $referer = (string) $request->header('Referer', '');
        if ($referer === '') {
            return false;
        }

        $expectedHost = parse_url(config('app.url'), PHP_URL_HOST);
        $refererHost = parse_url($referer, PHP_URL_HOST);

        if ($refererHost !== $expectedHost) {
            return false;
        }

        $path = parse_url($referer, PHP_URL_PATH) ?: '';

        return str_starts_with($path, '/menus/'.$companyNit);
    }
}
