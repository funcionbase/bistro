<?php

use App\Jobs\AggregateMenuScansJob;
use App\Jobs\DropOldMenuScanPartitionsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Programación de menú por sede. La VISIBILIDAD del menú público ya no depende
// de este tick (se valida active_days en lectura en showPublic); este cron solo
// mantiene el `status` para el editor. Cada 15 min para acotar la ventana de
// staleness tras el cambio de día. onOneServer + withoutOverlapping = N-instance.
Schedule::command('menus:sync-schedule')->everyFifteenMinutes()->onOneServer()->withoutOverlapping(10);

// Limpia chats con > 60 dias sin actividad (conserva contactos, notas, ordenes).
Schedule::command('chats:purge-old')->dailyAt('03:00')->onOneServer();

// Rollup diario de eventos de escaneo del menú (lectura barata para reportes).
Schedule::job(new AggregateMenuScansJob)->dailyAt('03:15')->onOneServer();

// Poda eventos crudos de menu_scan_events > 90 días con DELETE por rango
// (post-#193: se retiró el particionamiento por mes para evitar la acumulación
// de tablas hijas con el tiempo; el rollup diario conserva los agregados).
Schedule::job(new DropOldMenuScanPartitionsJob)->dailyAt('03:30')->onOneServer();

// Snapshot diario del food cost por ítem de menú (issue #113). Si el scheduler
// no está corriendo, FoodCostMetricsService::ensureTodaySnapshot() ejecuta el
// mismo cálculo en demand al primer acceso al endpoint del día.
Schedule::command('foodcost:snapshot-daily')->dailyAt('04:00')->onOneServer();

// Snapshot diario del stock por bodega (issue #120). Idempotente vía upsert.
// Si no corre, WarehouseStockHistoryService::valuationOn() reconstruye desde
// movements al primer query del día. El cron se programa entre el rollup de
// menu_scans y el food cost para no solapar con tareas pesadas.
Schedule::command('inventory:snapshot-daily')->dailyAt('03:45')->onOneServer();

// Fidelización (#122): expira balances inactivos (>N meses sin earn) y cupones
// de canje vencidos. Diario en horario bajo. Si el scheduler no corre, los
// canjes vencidos siguen apareciendo como 'issued' pero el cupón ya está
// expirado por valid_until — la UI igualmente los rechaza.
Schedule::command('loyalty:expire-stale')->dailyAt('04:15')->onOneServer();

// Alertas accionables (#124): evalúa las 4 reglas (margin_below, cost_increase,
// item_low_volume, low_stock) por empresa y persiste eventos con dedup diario.
// Se programa tras los snapshots de food cost/inventory para usar datos frescos.
Schedule::command('alerts:evaluate')->dailyAt('05:00')->onOneServer();

// Canary multi-EC2 (#43): loguea host+timestamp cada minuto. Con onOneServer()
// + cache_locks compartido en Supabase, CloudWatch Logs debe mostrar 1 entrada
// por minuto. Si aparecen 2 con hosts distintos → bug de coordinacion.
Schedule::command('healthcheck:heartbeat')->everyMinute()->onOneServer();

// Past-due #175: marca facturas vencidas y recalcula el status de la empresa
// (active → past_due → suspended → active). Idempotente — correr varias veces
// el mismo día es seguro. onOneServer() previene doble ejecución en el ASG.
Schedule::command('billing:mark-overdue-invoices')->dailyAt('04:30')->onOneServer();

// Generación mensual de invoices (#175 + #246 post-pago).
// Corre el día 1 de cada mes a las 03:00 Bogota y factura el mes ANTERIOR
// (post-pago: factura de junio cubre 1-31 mayo).
//
// N-instance safe (add-on CLAUDE.md §12):
//   - ->onOneServer() requiere CACHE_STORE compartido (este proyecto usa
//     CACHE_STORE=database sobre postgres, stack canónico — sin Redis).
//   - ->withoutOverlapping(60) impide que el comando se solape consigo
//     mismo en la misma EC2 si el run anterior toma >24h (defensa rare-case).
//   - Defensa adicional en BillingService::generateMonthlyInvoices: UNIQUE
//     parcial DB sobre (subscription_id, period_from, period_to) WHERE
//     status!='voided' rechaza cualquier carrera entre workers.
//   - Cada invoice se crea en DB::transaction con lockForUpdate sobre la
//     fila a mutar. EmitDianInvoiceJob queue dispatchado vía DB::afterCommit
//     (ShouldBeUnique por invoice_id — re-enqueue idempotente).
Schedule::command('billing:generate-monthly-invoices')
    ->cron('0 3 1 * *')
    ->timezone(config('app.timezone', 'America/Bogota'))
    ->onOneServer()
    ->withoutOverlapping(60);

// One-time (#alza-precio) — el alza del snapshot de las subscriptions
// existentes ($100.000 → $300.000) NO se agenda: se ejecuta a mano UNA sola vez
// el 2026-07-01 con `php artisan billing:apply-plan-price-hike`. El comando es
// idempotente (solo toca snapshots en 100k), así que un segundo disparo es
// no-op. Se deja este bloque comentado por trazabilidad — no borrar.
// Schedule::command('billing:apply-plan-price-hike')
//     ->dailyAt('04:00')
//     ->timezone(config('app.timezone', 'America/Bogota'))
//     ->onOneServer()
//     ->withoutOverlapping(15);

// #246 — Expira `company_promo_codes.status='active'` cuya ends_at < hoy.
//
// N-instance safe:
//   - PromoCodeService::expireOverdue procesa fila-a-fila con DB::transaction
//     + lockForUpdate; aunque dos workers entren al mismo tiempo, solo uno
//     ve status='active' y muta, el segundo skipea.
//   - onOneServer + withoutOverlapping(15) son defensa primaria en el ASG.
//   - Sin EmitDianInvoiceJob dispatch acá — no muta invoices.
// Idempotente — ejecutar varias veces el mismo día deja todo igual.
Schedule::command('billing:expire-discounts')
    ->dailyAt('04:45')
    ->onOneServer()
    ->withoutOverlapping(15);

// Reactivación post-pago #193: recalcula status de empresas en past_due/suspended
// cada 4 horas para que un comprobante aprobado a media tarde se refleje sin
// esperar al cron diario. Cubre tres transiciones: past_due → active (deuda
// liquidada), past_due → suspended (gracia expirada), suspended → active
// (deuda liquidada después del bloqueo). Idempotente por empresa.
// onOneServer + withoutOverlapping(30) garantizan ejecución única en el ASG —
// requieren cache store compartido (CACHE_STORE=database sobre postgres,
// stack canónico — el proyecto no usa Redis/DynamoDB).
Schedule::command('companies:recalculate-statuses')
    ->everyFourHours()
    ->onOneServer()
    ->withoutOverlapping(30);

// Mesa con QR #191: marca como `expired` las sesiones que pasaron de su
// expires_at sin pago ni cierre manual. Cada 5 min con onOneServer +
// withoutOverlapping (requiere cache store compartido — CACHE_STORE=database
// sobre postgres, el proyecto no usa Redis/DynamoDB). El batch=200 limita
// el lock por ejecución; sesiones nuevas se procesan en ticks siguientes.
Schedule::command('tables:purge-expired-sessions')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10);

// Past-due #175: export diario CSV con foto de empresas en past_due/suspended
// a S3 interno (uso operativo/contable). Siempre escribe archivo aunque haya
// 0 filas — sirve de heartbeat para ops. Corre 1h después del cron de
// past_due para tomar el estado ya recalculado.
Schedule::command('billing:export-delinquent')->dailyAt('05:30')->onOneServer();

// Aislamiento por sede #192: canario diario que reporta filas con branch_id
// NULL en cualquier tabla operativa. La migración fundacional fuerza
// NOT NULL, por lo que el reporte esperado es 0. Si el comando falla
// (exit code 1), CloudWatch Logs lo muestra y la operación lo revisa
// manualmente con `php artisan branches:audit-orphans` para ver detalle.
// READ-ONLY — la versión --fix-default jamás se programa automática.
Schedule::command('branches:audit-orphans')->dailyAt('04:45')->onOneServer();

// Push notifications #149: recordatorios de items pending_approval con
// `submitted_at` > 5 min. Cada minuto con onOneServer (lock cross-instance
// EC2) + withoutOverlapping(5) (timeout en el mismo nodo). Triple defensa
// vs duplicados: el comando además toma un Cache::lock per-item con TTL
// throttle_minutes (default 5min) antes de encolar el job, así dos ticks
// consecutivos no encolan el mismo item dos veces. REQUIERE
// CACHE_STORE=database y QUEUE_CONNECTION=database (postgres es el stack
// canónico del proyecto — no se usa Redis/SQS/DynamoDB).
Schedule::command('notifications:remind-pending-approvals')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(5);

// Facturación electrónica DIAN #235. Todos los schedules con onOneServer +
// withoutOverlapping (regla §12 CLAUDE.md + add-on §5 N-instance). Requieren
// CACHE_STORE=database para el lock cross-EC2.
//
// dispatch-pending: reintenta documentos en pending/error con backoff
// exponencial — el job EmitDianDocumentJob es ShouldBeUnique por
// (order_id, document_type) así que aunque dos ticks colisionen, solo uno corre.
Schedule::command('dian:dispatch-pending')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10);

// check-pending-acceptance: para documentos en `sent` sin webhook tras 15+
// min — hoy solo loguea, futuro `PollProviderStatusJob` por cada provider real.
Schedule::command('dian:check-pending-acceptance')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->withoutOverlapping(20);

// resolution-expiration-alert: revisa resoluciones próximas a vencer y deja
// trace en logs. Banner amarillo en dashboard también lo refleja
// (ResolutionExpirationAlert componente).
Schedule::command('dian:resolution-expiration-alert')
    ->dailyAt('05:15')
    ->onOneServer()
    ->withoutOverlapping(60);

// SMS huérfanos: `dispatch()` puede fallar DESPUÉS del insertOrIgnore, dejando
// la notificación en 'queued' sin job en la tabla jobs. La corrección primaria
// vive en OrderController::dispatchOrderStatusSms (marca 'failed' en el catch).
// Este schedule es red de seguridad para cualquier edge case no capturado.
// onOneServer + withoutOverlapping(5) — N-instance safe (CACHE_STORE=database).
Schedule::call(function () {
    DB::table('order_sms_notifications')
        ->where('status', 'queued')
        ->where('created_at', '<', now()->subHours(24))
        ->update(['status' => 'skipped', 'error' => 'expired: no job dispatched within 24h', 'updated_at' => now()]);
})->hourly()->name('sms:expire-queued')->onOneServer()->withoutOverlapping(5);
