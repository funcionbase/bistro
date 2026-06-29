<?php

use App\Models\Branch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('menu_qr_token', 13)->nullable()->unique()->after('slug');
        });

        // Backfill: generar token para cada sede existente.
        Branch::query()->whereNull('menu_qr_token')->each(function (Branch $branch) {
            $branch->menu_qr_token = Branch::generateMenuQrToken();
            $branch->saveQuietly();
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->string('menu_qr_token', 13)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('menu_qr_token');
        });
    }
};
