<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Normaliza los teléfonos de cliente ya existentes al canónico
 * `57XXXXXXXXXX` (con indicativo de país, SIN `+`, sin espacios) — el mismo que
 * a partir de ahora imponen los mutators de Contact/Order/Chat y
 * `PhoneNumber::toColombianCanonical`.
 *
 * Motivo: había tres formatos conviviendo (`+57 315 270 1319` del cajero,
 * `+573152701319` del webhook de WhatsApp, `573152701319` del CRM), lo que
 * duplicaba el mismo cliente y descuadraba el match contacto↔orden↔chat. Esta
 * migración deja un solo formato en los datos viejos.
 *
 * Solo formato: NO cambia montos, estados ni identidad (doc_number). En pdn el
 * deploy corre pg_dump antes de migrar (safe-migrate.sh).
 *
 * Tablas con UNIQUE sobre el teléfono (chats, loyalty_accounts, client_tags):
 * se saltan las filas cuya normalización chocaría con otra ya canónica (guard
 * NOT EXISTS) para no abortar la migración; quedan en su formato viejo y las
 * resuelve el match por variantes / el merge manual. Se loguea cuántas.
 */
return new class extends Migration
{
    /** Expresión SQL que normaliza `$col` a `57XXXXXXXXXX` (espejo de toColombianCanonical). */
    private function canon(string $col): string
    {
        $digits = "regexp_replace($col, '[^0-9]', '', 'g')";

        return "CASE
            WHEN length($digits) = 10 AND left($digits, 1) = '3' THEN '57' || $digits
            ELSE $digits
        END";
    }

    public function up(): void
    {
        // Tablas SIN unique sobre el teléfono → UPDATE directo (set-based).
        $simple = [
            ['contacts', 'phone'],
            ['orders', 'client_phone'],
            ['cart_sessions', 'client_phone'],
            ['client_notes', 'client_phone'],
            ['coupons', 'locked_to_phone'],
            ['coupon_redemptions', 'client_phone'],
            ['table_session_guests', 'phone'],
        ];

        foreach ($simple as [$table, $col]) {
            $canon = $this->canon($col);
            $affected = DB::update(
                "UPDATE $table SET $col = $canon
                 WHERE $col IS NOT NULL AND $col <> '' AND $col <> ($canon)"
            );
            Log::channel('single')->info('phones.normalized', ['table' => $table, 'column' => $col, 'rows' => $affected]);
        }

        // Tablas CON unique sobre el teléfono → saltar filas que colisionarían.
        // El guard NOT EXISTS busca otra fila (distinta id) que ya tenga el valor
        // canónico dentro del mismo alcance del índice único.
        $this->normalizeGuarded(
            'chats',
            'client_phone',
            // Cubre ambos índices parciales: (whatsapp_account_id, client_phone) y
            // (company_nit, client_phone WHERE account IS NULL). IS NOT DISTINCT
            // FROM iguala null=null.
            'o.company_nit = t.company_nit AND o.whatsapp_account_id IS NOT DISTINCT FROM t.whatsapp_account_id'
        );
        $this->normalizeGuarded('loyalty_accounts', 'client_phone', 'o.company_nit = t.company_nit');
        $this->normalizeGuarded('client_tags', 'client_phone', 'o.company_nit = t.company_nit AND o.tag = t.tag');
    }

    private function normalizeGuarded(string $table, string $col, string $scopeMatch): void
    {
        $canon = $this->canon("t.$col");
        $skipped = DB::selectOne(
            "SELECT COUNT(*) AS n FROM $table t
             WHERE t.$col IS NOT NULL AND t.$col <> '' AND t.$col <> ($canon)
               AND EXISTS (SELECT 1 FROM $table o WHERE o.id <> t.id AND $scopeMatch AND o.$col = ($canon))"
        );

        $affected = DB::update(
            "UPDATE $table t SET $col = ($canon)
             WHERE t.$col IS NOT NULL AND t.$col <> '' AND t.$col <> ($canon)
               AND NOT EXISTS (SELECT 1 FROM $table o WHERE o.id <> t.id AND $scopeMatch AND o.$col = ($canon))"
        );

        Log::channel('single')->info('phones.normalized', [
            'table' => $table,
            'column' => $col,
            'rows' => $affected,
            'skipped_collision' => (int) ($skipped->n ?? 0),
        ]);
    }

    public function down(): void
    {
        // Irreversible por diseño: no se puede reconstruir el formato original
        // (espacios/`+`) desde el canónico. El canónico es un superconjunto válido.
    }
};
