<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Feature;
use App\Models\PermissionTemplate;
use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Throwable;

/**
 * Auditoría RBAC.
 *
 * Cruza `routes/api.php` contra el catálogo de permisos del sistema y
 * detecta tres categorías de problema:
 *
 *  1. Rutas mutadoras (POST/PUT/PATCH/DELETE) sin middleware
 *     `permission:<slug>,<action>` y que no estén en la allow-list del
 *     config `rbac.audit.public_routes` ni cubiertas por un middleware
 *     `bypass_middlewares` (bot.jwt / table.guest).
 *
 *  2. Rutas que declaran `permission:<slug>` apuntando a un slug que NO
 *     existe en `features.slug` (catálogo de la BD). Esto en runtime es un
 *     403 silencioso (`EnsureFeaturePermission` simplemente niega el
 *     acceso) — peor todavía: si el slug es typo, ningún rol tiene el
 *     permiso, ergo la ruta queda inaccesible salvo `is_system`.
 *
 *  3. Drift de catálogo: slugs en `FeatureSeeder` que no tienen ningún
 *     `PermissionTemplate` asociado (no se asigna a ningún rol del
 *     PermissionTemplateSeeder).
 *
 * Diseñado para correrse local antes de cada PR. El output JSON
 * (`--json`) se guarda como snapshot baseline en
 * `application/constants/RBAC_AUDIT_LATEST.md`. Puede atarse a un
 * pre-commit hook o re-introducirse en CI con `--fail-on-gap` cuando
 * existan colaboradores externos o entorno de producción.
 *
 * N-instance safety: comando de solo lectura. Si en el futuro se programa
 * para correr periódicamente, debe llevar `->onOneServer()` (regla raíz
 * CLAUDE.md §12).
 */
class RbacAuditCommand extends Command
{
    /** @var string */
    protected $signature = 'rbac:audit
        {--fail-on-gap : Falla (exit 1) en BRECHA + INVALID-SLUG + INVALID-ACTION + slugs sin template. Uso CI estricto.}
        {--fail-on-invalid : Falla solo en INVALID-SLUG + INVALID-ACTION (errores duros). No falla en BRECHA.}
        {--json : Output JSON máquina-legible (sirve para snapshot).}
        {--verbose-ok : Incluye rutas OK en la tabla. Default: solo BRECHA / problemas.}
        {--skip-catalog : Omite el cross-check de catálogo (slugs sin template). Útil si la BD no está sembrada.}';

    /** @var string */
    protected $description = 'Audita rutas API contra el catálogo RBAC: detecta brechas de permission: middleware, slugs inválidos y drift de catálogo.';

    public function handle(): int
    {
        $config = config('rbac.audit');
        if (! is_array($config)) {
            $this->error('rbac.audit no está configurado. Revisar config/rbac.php.');

            return self::FAILURE;
        }

        $publicRoutes = (array) ($config['public_routes'] ?? []);
        $bypassMiddlewares = (array) ($config['bypass_middlewares'] ?? []);
        $mutatorVerbs = (array) ($config['mutator_verbs'] ?? ['POST', 'PUT', 'PATCH', 'DELETE']);
        $validActions = (array) ($config['valid_actions'] ?? ['read', 'create', 'update', 'delete']);

        $catalogSlugs = $this->loadCatalogSlugs();

        $routeReport = $this->auditRoutes(
            $publicRoutes,
            $bypassMiddlewares,
            $mutatorVerbs,
            $validActions,
            $catalogSlugs,
        );

        $catalogReport = $this->option('skip-catalog')
            ? ['skipped' => true, 'slugs_without_template' => [], 'templates_without_feature' => []]
            : $this->auditCatalog();

        $summary = $this->buildSummary($routeReport);

        if ($this->option('json')) {
            $this->emitJson($routeReport, $catalogReport, $summary);
        } else {
            $this->emitTable($routeReport, $catalogReport, $summary);
        }

        $hasInvalid = $summary['invalid_slug'] > 0 || $summary['invalid_action'] > 0;
        $hasGap = $hasInvalid
            || $summary['brecha'] > 0
            || count($catalogReport['slugs_without_template'] ?? []) > 0;

        if ($this->option('fail-on-gap') && $hasGap) {
            return self::FAILURE;
        }

        if ($this->option('fail-on-invalid') && $hasInvalid) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string> Slugs cargados desde la BD; vacío si no hay features sembradas.
     */
    private function loadCatalogSlugs(): array
    {
        try {
            return Feature::query()->pluck('slug')->all();
        } catch (Throwable $e) {
            $this->warn('No se pudo cargar `features` desde BD ('.$e->getMessage().'). Skipping slug-existence check.');

            return [];
        }
    }

    /**
     * @param  array<string, string>  $publicRoutes  uri => razón
     * @param  array<int, string>  $bypassMiddlewares
     * @param  array<int, string>  $mutatorVerbs
     * @param  array<int, string>  $validActions
     * @param  array<int, string>  $catalogSlugs
     * @return array<int, array{verb: string, uri: string, name: string|null, action: string|null, permission: string|null, status: string, reason: string|null}>
     */
    private function auditRoutes(
        array $publicRoutes,
        array $bypassMiddlewares,
        array $mutatorVerbs,
        array $validActions,
        array $catalogSlugs,
    ): array {
        $report = [];

        foreach (RouteFacade::getRoutes() as $route) {
            /** @var Route $route */
            $uri = $route->uri();

            // Solo auditamos la API. Las rutas web (Inertia / blade) tienen su
            // propio modelo (gates/policies, `EnsureCompanyVerified`).
            if (! str_starts_with($uri, 'api/')) {
                continue;
            }

            $methods = array_diff($route->methods(), ['HEAD']);
            $mutators = array_values(array_intersect($methods, $mutatorVerbs));
            if ($mutators === []) {
                continue;
            }

            $middlewares = $route->gatherMiddleware();
            $action = $route->getActionName();

            foreach ($mutators as $verb) {
                $row = $this->classifyRoute(
                    $verb,
                    $uri,
                    $route->getName(),
                    $action,
                    $middlewares,
                    $publicRoutes,
                    $bypassMiddlewares,
                    $validActions,
                    $catalogSlugs,
                );
                $report[] = $row;
            }
        }

        usort($report, fn (array $a, array $b): int => [$a['status'], $a['uri'], $a['verb']] <=> [$b['status'], $b['uri'], $b['verb']]);

        return $report;
    }

    /**
     * @param  array<int, string>  $middlewares
     * @param  array<string, string>  $publicRoutes
     * @param  array<int, string>  $bypassMiddlewares
     * @param  array<int, string>  $validActions
     * @param  array<int, string>  $catalogSlugs
     * @return array{verb: string, uri: string, name: string|null, action: string|null, permission: string|null, status: string, reason: string|null}
     */
    private function classifyRoute(
        string $verb,
        string $uri,
        ?string $name,
        string $controllerAction,
        array $middlewares,
        array $publicRoutes,
        array $bypassMiddlewares,
        array $validActions,
        array $catalogSlugs,
    ): array {
        $permissionMiddleware = $this->extractPermissionMiddleware($middlewares);

        // 1. Allow-list explícita.
        if (array_key_exists($uri, $publicRoutes)) {
            return $this->row($verb, $uri, $name, $controllerAction, $permissionMiddleware, 'ALLOW-LIST', $publicRoutes[$uri]);
        }

        // 2. Bypass por middleware paralelo (bot.jwt, table.guest).
        $bypassHit = array_values(array_intersect($middlewares, $bypassMiddlewares))[0] ?? null;
        if ($bypassHit !== null) {
            return $this->row($verb, $uri, $name, $controllerAction, $permissionMiddleware, 'BYPASS', "Cubierto por middleware paralelo: {$bypassHit}");
        }

        // 3. Sin permission: middleware → BRECHA.
        if ($permissionMiddleware === null) {
            return $this->row($verb, $uri, $name, $controllerAction, null, 'BRECHA', 'Sin middleware permission: y fuera de allow-list / bypass.');
        }

        // 4. Permission declarado: validar slug y acción.
        [$slug, $action] = $this->parsePermissionDirective($permissionMiddleware);

        if ($action !== null && ! in_array($action, $validActions, true)) {
            return $this->row($verb, $uri, $name, $controllerAction, $permissionMiddleware, 'INVALID-ACTION', "Acción `{$action}` no está en valid_actions.");
        }

        if ($catalogSlugs !== [] && ! in_array($slug, $catalogSlugs, true)) {
            return $this->row($verb, $uri, $name, $controllerAction, $permissionMiddleware, 'INVALID-SLUG', "Slug `{$slug}` no existe en `features` (catalog).");
        }

        return $this->row($verb, $uri, $name, $controllerAction, $permissionMiddleware, 'OK', null);
    }

    /**
     * @param  array<int, string>  $middlewares
     */
    private function extractPermissionMiddleware(array $middlewares): ?string
    {
        foreach ($middlewares as $mw) {
            if (str_starts_with($mw, 'permission:') || str_contains($mw, '\\EnsureFeaturePermission')) {
                return $mw;
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string|null} [slug, action] — action puede ser null si la declaración no la incluyó.
     */
    private function parsePermissionDirective(string $middleware): array
    {
        // Formato esperado: `permission:slug,action` o `permission:slug`.
        // (también soporta `App\\Http\\Middleware\\EnsureFeaturePermission:slug,action`).
        $colonPos = strpos($middleware, ':');
        if ($colonPos === false) {
            return ['', null];
        }

        $params = substr($middleware, $colonPos + 1);
        $parts = array_map('trim', explode(',', $params, 2));

        return [$parts[0] ?? '', $parts[1] ?? null];
    }

    /**
     * @return array{verb: string, uri: string, name: string|null, action: string|null, permission: string|null, status: string, reason: string|null}
     */
    private function row(
        string $verb,
        string $uri,
        ?string $name,
        string $controllerAction,
        ?string $permission,
        string $status,
        ?string $reason,
    ): array {
        return [
            'verb' => $verb,
            'uri' => $uri,
            'name' => $name,
            'action' => $controllerAction,
            'permission' => $permission,
            'status' => $status,
            'reason' => $reason,
        ];
    }

    /**
     * @return array{skipped: bool, slugs_without_template: list<string>, templates_without_feature: list<int>}
     */
    private function auditCatalog(): array
    {
        try {
            $allFeatures = Feature::query()->get(['id', 'slug']);
            $featureIdsWithTemplate = PermissionTemplate::query()
                ->distinct()
                ->pluck('feature_id')
                ->all();
        } catch (Throwable $e) {
            $this->warn('No se pudo verificar catálogo ('.$e->getMessage().'). Skipping.');

            return ['skipped' => true, 'slugs_without_template' => [], 'templates_without_feature' => []];
        }

        $allFeatureIds = $allFeatures->pluck('id')->all();

        $slugsWithoutTemplate = $allFeatures
            ->reject(fn (Feature $f) => in_array($f->id, $featureIdsWithTemplate, true))
            ->pluck('slug')
            ->values()
            ->all();

        $templatesWithoutFeature = array_values(array_diff($featureIdsWithTemplate, $allFeatureIds));

        return [
            'skipped' => false,
            'slugs_without_template' => $slugsWithoutTemplate,
            'templates_without_feature' => $templatesWithoutFeature,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $report
     * @return array<string, int>
     */
    private function buildSummary(array $report): array
    {
        $summary = [
            'total' => count($report),
            'ok' => 0,
            'allow_list' => 0,
            'bypass' => 0,
            'brecha' => 0,
            'invalid_slug' => 0,
            'invalid_action' => 0,
        ];

        foreach ($report as $row) {
            $key = match ($row['status']) {
                'OK' => 'ok',
                'ALLOW-LIST' => 'allow_list',
                'BYPASS' => 'bypass',
                'BRECHA' => 'brecha',
                'INVALID-SLUG' => 'invalid_slug',
                'INVALID-ACTION' => 'invalid_action',
                default => null,
            };
            if ($key !== null) {
                $summary[$key]++;
            }
        }

        return $summary;
    }

    /**
     * @param  array<int, array<string, mixed>>  $routes
     * @param  array<string, mixed>  $catalog
     * @param  array<string, int>  $summary
     */
    private function emitJson(array $routes, array $catalog, array $summary): void
    {
        $payload = [
            'generated_at' => now()->toIso8601String(),
            'summary' => $summary,
            'routes' => $routes,
            'catalog' => $catalog,
        ];

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<int, array<string, mixed>>  $routes
     * @param  array<string, mixed>  $catalog
     * @param  array<string, int>  $summary
     */
    private function emitTable(array $routes, array $catalog, array $summary): void
    {
        $verbose = (bool) $this->option('verbose-ok');

        $displayable = $verbose
            ? $routes
            : array_values(array_filter(
                $routes,
                fn (array $row): bool => in_array($row['status'], ['BRECHA', 'INVALID-SLUG', 'INVALID-ACTION'], true),
            ));

        if ($displayable === []) {
            $this->info('✓ Ninguna ruta con BRECHA / slug inválido / acción inválida.'.($verbose ? '' : ' (Usa --verbose-ok para listar las OK).'));
        } else {
            $rows = array_map(
                fn (array $r) => [
                    $r['status'],
                    $r['verb'],
                    $r['uri'],
                    $r['permission'] ?? '—',
                    $r['reason'] ?? '',
                ],
                $displayable,
            );
            $this->table(['Status', 'Verb', 'URI', 'Permission', 'Reason'], $rows);
        }

        // Resumen.
        $this->newLine();
        $this->info(sprintf(
            'Resumen: total=%d · ok=%d · allow-list=%d · bypass=%d · BRECHA=%d · invalid-slug=%d · invalid-action=%d',
            $summary['total'],
            $summary['ok'],
            $summary['allow_list'],
            $summary['bypass'],
            $summary['brecha'],
            $summary['invalid_slug'],
            $summary['invalid_action'],
        ));

        // Catálogo.
        $this->newLine();
        if ($catalog['skipped'] ?? false) {
            $this->warn('Catálogo: omitido.');
        } else {
            $slugsMissingTpl = $catalog['slugs_without_template'] ?? [];
            $tplsMissingFeature = $catalog['templates_without_feature'] ?? [];

            if ($slugsMissingTpl === [] && $tplsMissingFeature === []) {
                $this->info('Catálogo: sin drift. Todos los slugs tienen template y todos los templates apuntan a un feature válido.');
            } else {
                if ($slugsMissingTpl !== []) {
                    $this->error('Slugs en `features` sin entry en `permission_templates`:');
                    foreach ($slugsMissingTpl as $slug) {
                        $this->line("  · {$slug}");
                    }
                }
                if ($tplsMissingFeature !== []) {
                    $this->error('Templates apuntando a feature_id inexistente:');
                    foreach ($tplsMissingFeature as $fid) {
                        $this->line("  · feature_id={$fid}");
                    }
                }
            }
        }
    }
}
