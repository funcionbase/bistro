<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de municipios DANE de Colombia — respalda el selector de ciudad
 * ("Ciudad, Departamento") del formulario de dirección del contacto.
 *
 * `dane_code` (5) = código departamento (2) + código municipio (3), el mismo
 * que usa el perfil fiscal DIAN (`contacts.municipality_dane_code`). Así el
 * selector produce directamente un código válido para FEV, sin mapeos.
 *
 * Se puebla desde el CSV embebido en el repo
 * (`database/data/dane_municipalities.csv`, fuente panchicore/dane_colombia),
 * no de la red: la migración corre en el bootstrap de pdn y no debe depender de
 * conectividad externa. ~1.119 filas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipalities', function (Blueprint $table) {
            $table->char('dane_code', 5)->primary();          // '05001'
            $table->string('city', 120);                      // 'MEDELLIN'
            $table->char('department_code', 2);               // '05'
            $table->string('department', 120);                // 'ANTIOQUIA'

            // Búsqueda por nombre de ciudad o departamento (ILIKE). Con ~1.100
            // filas el scan es trivial; los índices ayudan al prefijo.
            $table->index('city');
            $table->index('department');
        });

        $this->seedFromCsv();
    }

    public function down(): void
    {
        Schema::dropIfExists('municipalities');
    }

    private function seedFromCsv(): void
    {
        $path = database_path('data/dane_municipalities.csv');
        if (! is_readable($path)) {
            throw new RuntimeException("CSV de municipios DANE no encontrado en {$path}.");
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("No se pudo abrir {$path}.");
        }

        fgetcsv($handle, 0, ';');  // descarta el header

        $rows = [];
        while (($cols = fgetcsv($handle, 0, ';')) !== false) {
            // Columnas: Código Departamento; Nombre Departamento; Código Municipio; Nombre Municipio
            if (count($cols) < 4) {
                continue;
            }
            $deptCode = str_pad(trim((string) $cols[0]), 2, '0', STR_PAD_LEFT);
            $muniCode = str_pad(trim((string) $cols[2]), 3, '0', STR_PAD_LEFT);

            $rows[] = [
                'dane_code' => $deptCode.$muniCode,
                'city' => trim((string) $cols[3]),
                'department_code' => $deptCode,
                'department' => trim((string) $cols[1]),
            ];
        }
        fclose($handle);

        // Bulk insert en chunks; upsert por si la tabla ya tuviera filas.
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('municipalities')->upsert($chunk, ['dane_code'], ['city', 'department_code', 'department']);
        }
    }
};
