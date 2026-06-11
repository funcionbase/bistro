<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Backfill data-only de `orders.contact_id`.
 *
 * Para cada `orders.client_phone` busca un `Contact` único en la misma
 * empresa y popula `orders.contact_id`. Si el phone matchea 0 o múltiples
 * contactos, deja NULL — el cajero los reasigna desde el detalle de orden.
 *
 * Pensada para correr una sola vez (idempotente: solo toca filas con
 * `contact_id IS NULL`).
 */
return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL: subquery con LATERAL para resolver el lookup único
        // sin traer todo a PHP. Si hay más de un contact por phone, la
        // subquery LIMIT 2 lo detecta y dejamos NULL.
        $updated = DB::statement(<<<'SQL'
            UPDATE orders o
            SET contact_id = sub.id
            FROM (
                SELECT c.id, c.company_nit, c.phone
                FROM contacts c
                WHERE c.phone IS NOT NULL
                  AND NOT EXISTS (
                      SELECT 1 FROM contacts c2
                      WHERE c2.company_nit = c.company_nit
                        AND c2.phone = c.phone
                        AND c2.id <> c.id
                  )
            ) sub
            WHERE o.contact_id IS NULL
              AND o.client_phone IS NOT NULL
              AND o.company_nit = sub.company_nit
              AND o.client_phone = sub.phone
        SQL);

        $resolved = DB::table('orders')->whereNotNull('contact_id')->count();
        $unresolved = DB::table('orders')
            ->whereNull('contact_id')
            ->whereNotNull('client_phone')
            ->count();

        Log::info('orders.contact_id backfill', [
            'resolved' => $resolved,
            'unresolved_with_phone' => $unresolved,
            'note' => 'Órdenes sin resolver: phone ambiguo (varios contactos) o sin contacto registrado. Caja las reasigna.',
        ]);
    }

    public function down(): void
    {
        // No-op: el rollback del schema en la migración anterior ya tira
        // la columna `contact_id`. Aquí solo limpiamos por defensa si la
        // columna persistiera por alguna razón.
        if (DB::getSchemaBuilder()->hasColumn('orders', 'contact_id')) {
            DB::table('orders')->update(['contact_id' => null]);
        }
    }
};
