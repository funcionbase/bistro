<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F2b — índice para la pestaña "Actividad" de una conversación
 * (plan 8-whatsapp.md §7.6).
 *
 * `audit_logs` es cross-company y ya crece con toda la app; F2b le suma 16
 * acciones nuevas de chat. Sin índice, listar los últimos 50 eventos de UNA
 * conversación obliga a un seq scan de la tabla entera.
 *
 * Índice de EXPRESIÓN y PARCIAL:
 *  - expresión, porque `company_nit` vive dentro de `data`, no en una columna;
 *  - parcial, porque solo cubre las acciones de este módulo. El resto de la app
 *    no paga el costo de escritura ni el tamaño en disco.
 *
 * `data` es `json`, NO `jsonb` (verificado contra la BD, no deducido). Para el
 * índice de expresión da igual: `json ->> text` es IMMUTABLE en los dos tipos.
 * Se descarta migrar la columna a `jsonb`: reescribe la tabla entera bajo un
 * deploy con dos versiones vivas, a cambio de habilitar un GIN que nadie
 * necesita — las consultas de la pestaña son por igualdad, no por contenido.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE INDEX audit_logs_chat_company_idx
                       ON audit_logs ((data->>'company_nit'), created_at DESC)
                       WHERE action LIKE 'chat.%' OR action LIKE 'whatsapp.%'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS audit_logs_chat_company_idx');
    }
};
