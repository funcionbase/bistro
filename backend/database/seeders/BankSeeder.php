<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BankSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('banks')->exists()) {
            return;
        }

        $banks = [
            ['name' => 'Bancolombia',          'code' => '007', 'swift' => 'COLOCOBM',   'type' => 'banco',    'website' => 'https://www.bancolombia.com',        'phone' => '018000912345', 'logo_url' => null],
            ['name' => 'Banco de Bogotá',      'code' => '001', 'swift' => 'BOGOTACO',   'type' => 'banco',    'website' => 'https://www.bancodebogota.com.co',   'phone' => '018000518877', 'logo_url' => null],
            ['name' => 'Davivienda',           'code' => '022', 'swift' => 'CAVICOBB',   'type' => 'banco',    'website' => 'https://www.davivienda.com',          'phone' => '018000123838', 'logo_url' => null],
            ['name' => 'BBVA Colombia',        'code' => '019', 'swift' => 'BBVACOBB',   'type' => 'banco',    'website' => 'https://www.bbva.com.co',             'phone' => '018000934020', 'logo_url' => null],
            ['name' => 'Banco de Occidente',   'code' => '023', 'swift' => 'OCCICOBB',   'type' => 'banco',    'website' => 'https://www.bancooccidente.com.co',   'phone' => '018000514515', 'logo_url' => null],
            ['name' => 'Banco Popular',        'code' => '018', 'swift' => 'POPUCOBB',   'type' => 'banco',    'website' => 'https://www.bancopopular.com.co',     'phone' => '018000180888', 'logo_url' => null],
            ['name' => 'Scotiabank Colpatria', 'code' => '027', 'swift' => 'COLPCOBB',   'type' => 'banco',    'website' => 'https://www.scotiabankcolpatria.com', 'phone' => '6017561616',   'logo_url' => null],
            ['name' => 'Banco AV Villas',      'code' => '016', 'swift' => 'AVVI CO BB', 'type' => 'banco',    'website' => 'https://www.avvillas.com.co',         'phone' => '018000518000', 'logo_url' => null],
            ['name' => 'Banco Caja Social',    'code' => '014', 'swift' => 'CAJACOBA',   'type' => 'banco',    'website' => 'https://www.bancocajasocial.com',     'phone' => '018000910045', 'logo_url' => null],
            ['name' => 'Nequi',                'code' => '150', 'swift' => null,          'type' => 'neobanco', 'website' => 'https://www.nequi.com.co',            'phone' => '3006000100',   'logo_url' => null],
        ];

        foreach ($banks as &$bank) {
            // PKs siempre UUID v7 (consistente con HasUuids trait).
            $bank['id'] = (string) Str::uuid7();
        }
        unset($bank);

        DB::table('banks')->insert($banks);
    }
}
