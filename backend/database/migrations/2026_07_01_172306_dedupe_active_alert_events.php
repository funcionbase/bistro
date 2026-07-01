<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sanea el feed de alertas: AlertEngine creaba un evento nuevo por día para la
 * misma condición persistente (dedupe solo intra-día), acumulando N copias
 * idénticas activas (bug QA 2026-07-01: 8 alertas "Sin ventas en 14 días ·
 * Plato QA"). El engine ahora refresca el evento activo existente; esta
 * migración descarta los duplicados históricos dejando el más reciente activo
 * por (alert_rule_id, target_type, target_id).
 *
 * Marca dismissed_at (no borra): alert_events es append-only para auditoría.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE alert_events
            SET dismissed_at = NOW()
            WHERE dismissed_at IS NULL
              AND actioned_at IS NULL
              AND id NOT IN (
                  SELECT DISTINCT ON (alert_rule_id, target_type, COALESCE(target_id::text, '')) id
                  FROM alert_events
                  WHERE dismissed_at IS NULL AND actioned_at IS NULL
                  ORDER BY alert_rule_id, target_type, COALESCE(target_id::text, ''), triggered_at DESC
              )
        SQL);
    }

    public function down(): void
    {
        // Irreversible a propósito: no hay forma de distinguir los dismissed
        // por esta migración de los dismissed por usuarios.
    }
};
