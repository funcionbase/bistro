<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unifica el catálogo de tipos de documento legal.
 *
 * Hasta hoy `user_acceptances.document_type` aceptaba `['tos','privacy','contract']`
 * mientras `legal_documents.type` ya usa `['terms','privacy','contract']`. El
 * controller `LegalDocumentController` ya normalizaba el alias `tos → terms`,
 * pero los registros históricos seguían persistidos con `'tos'`. Esta migración:
 *
 *   1. Hace UPDATE de filas existentes (`tos → terms`).
 *   2. Reemplaza el enum/CHECK por la lista canónica `['terms','privacy','contract']`.
 *
 * Inmutabilidad legal preservada: los snapshots (`document_content`,
 * `document_version`, `accepted_at`, `ip_address`, `user_agent`) NO se tocan.
 * Solo se renombra el slug del tipo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        DB::transaction(function () use ($driver) {
            $beforeTos = (int) DB::table('user_acceptances')
                ->where('document_type', 'tos')
                ->count();

            if ($driver === 'pgsql') {
                $this->renameEnumValuePostgres($beforeTos);

                return;
            }

            DB::table('user_acceptances')
                ->where('document_type', 'tos')
                ->update(['document_type' => 'terms']);

            $afterTos = (int) DB::table('user_acceptances')
                ->where('document_type', 'tos')
                ->count();

            if ($afterTos !== 0) {
                throw new RuntimeException(
                    "Backfill incompleto: quedan {$afterTos} filas con document_type='tos' (antes: {$beforeTos})."
                );
            }
        });
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        DB::transaction(function () use ($driver) {
            if ($driver === 'pgsql') {
                $this->renameEnumValueBackToTosPostgres();

                return;
            }

            DB::table('user_acceptances')
                ->where('document_type', 'terms')
                ->update(['document_type' => 'tos']);
        });
    }

    /**
     * En Postgres, $table->enum() crea un CHECK constraint sobre un varchar.
     * Drop el constraint, hace UPDATE, y recrea con el set canónico.
     */
    private function renameEnumValuePostgres(int $beforeTos): void
    {
        $constraintName = $this->findCheckConstraintPostgres('user_acceptances', 'document_type');

        if ($constraintName !== null) {
            DB::statement("ALTER TABLE user_acceptances DROP CONSTRAINT {$constraintName}");
        }

        DB::table('user_acceptances')
            ->where('document_type', 'tos')
            ->update(['document_type' => 'terms']);

        $afterTos = (int) DB::table('user_acceptances')
            ->where('document_type', 'tos')
            ->count();

        if ($afterTos !== 0) {
            throw new RuntimeException(
                "Backfill incompleto: quedan {$afterTos} filas con document_type='tos' (antes: {$beforeTos})."
            );
        }

        DB::statement(<<<'SQL'
            ALTER TABLE user_acceptances
            ADD CONSTRAINT user_acceptances_document_type_check
            CHECK (document_type IN ('terms', 'privacy', 'contract'))
        SQL);
    }

    private function renameEnumValueBackToTosPostgres(): void
    {
        $constraintName = $this->findCheckConstraintPostgres('user_acceptances', 'document_type');

        if ($constraintName !== null) {
            DB::statement("ALTER TABLE user_acceptances DROP CONSTRAINT {$constraintName}");
        }

        DB::table('user_acceptances')
            ->where('document_type', 'terms')
            ->update(['document_type' => 'tos']);

        DB::statement(<<<'SQL'
            ALTER TABLE user_acceptances
            ADD CONSTRAINT user_acceptances_document_type_check
            CHECK (document_type IN ('tos', 'privacy', 'contract'))
        SQL);
    }

    /**
     * Localiza el nombre del CHECK constraint que Laravel auto-genera para
     * `$table->enum()`. El nombre depende de la versión de pgsql; lo buscamos
     * por la definición.
     */
    private function findCheckConstraintPostgres(string $table, string $column): ?string
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        $rows = DB::select(<<<'SQL'
            SELECT con.conname AS name
            FROM pg_constraint con
            JOIN pg_class cls ON cls.oid = con.conrelid
            JOIN pg_namespace nsp ON nsp.oid = cls.relnamespace
            WHERE cls.relname = ?
              AND con.contype = 'c'
              AND pg_get_constraintdef(con.oid) ILIKE ?
        SQL, [$table, "%{$column}%"]);

        return $rows[0]->name ?? null;
    }
};
