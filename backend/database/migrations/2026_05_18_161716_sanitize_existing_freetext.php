<?php

use App\Rules\SafePlainText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migración one-off de saneamiento de datos históricos.
 *
 * Aplica `strip_tags` + NFC + colapso de control characters defensive
 * sweep a las columnas de texto libre identificadas como críticas en
 * `docs/wiki/SECURITY_INPUT_HANDLING.md`.
 *
 * Garantías:
 * - Idempotente: compara antes de mutar. Si el saneamiento no cambia la
 *   fila, no se hace UPDATE.
 * - Trazable: cada fila tocada se registra en `audit_logs` con
 *   `action = 'sanitize.migrated'`, hash SHA-256 before/after y metadatos
 *   para forensia.
 * - Batched (500 por chunk) vía `chunkById`: seguro en tablas grandes
 *   de PDN sin reventar memoria.
 * - down() no restaura: la sanitización es one-way por diseño contable
 *   (auditoría DIAN exige inmutabilidad). El audit_log es el rastro.
 *
 * NO toca `legal_documents.content` — ese es markdown_trusted y solo se
 * actualiza vía deploy con bump de versión (ver `legal/README.md`).
 */
return new class extends Migration
{
    /**
     * @var array<int, array{table: string, column: string, allowWhitespace: bool}>
     */
    private array $targets = [
        ['table' => 'chat_messages', 'column' => 'body', 'allowWhitespace' => true],
        ['table' => 'order_items', 'column' => 'notes', 'allowWhitespace' => true],
        ['table' => 'order_notes', 'column' => 'body', 'allowWhitespace' => true],
        ['table' => 'client_notes', 'column' => 'note', 'allowWhitespace' => true],
        ['table' => 'cart_items', 'column' => 'notes', 'allowWhitespace' => true],
        ['table' => 'delivery_status_logs', 'column' => 'reason', 'allowWhitespace' => true],
        ['table' => 'branches', 'column' => 'address', 'allowWhitespace' => true],
    ];

    public function up(): void
    {
        foreach ($this->targets as $target) {
            $this->sanitizeColumn($target['table'], $target['column'], $target['allowWhitespace']);
        }
    }

    public function down(): void
    {
        // No reversible. El saneamiento es one-way (DIAN exige inmutabilidad
        // del texto persistido). El rastro queda en audit_logs.
    }

    private function sanitizeColumn(string $table, string $column, bool $allowWhitespace): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $now = now();
        $touched = 0;

        DB::table($table)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table, $column, $allowWhitespace, $now, &$touched) {
                foreach ($rows as $row) {
                    $original = (string) $row->{$column};
                    $sanitized = SafePlainText::sanitize($original, allowWhitespace: $allowWhitespace);

                    if ($sanitized === $original) {
                        continue;
                    }

                    DB::table($table)
                        ->where('id', $row->id)
                        ->update([$column => $sanitized]);

                    DB::table('audit_logs')->insert([
                        'user_id' => null,
                        'action' => 'sanitize.migrated',
                        'auditable_type' => $table,
                        'auditable_id' => (string) $row->id,
                        'data' => json_encode([
                            'column' => $column,
                            'before_sha256' => hash('sha256', $original),
                            'after_sha256' => hash('sha256', $sanitized),
                            'before_length' => strlen($original),
                            'after_length' => strlen($sanitized),
                            'migration' => '2026_05_18_161716_sanitize_existing_freetext',
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'ip_address' => null,
                        'user_agent' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $touched++;
                }
            }, 'id');

        if ($touched > 0) {
            echo "  sanitize: {$table}.{$column} -> {$touched} filas saneadas\n";
        }
    }
};
