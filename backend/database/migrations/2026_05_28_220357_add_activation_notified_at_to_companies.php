<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marker para notifyOnce de CompanyRegistrationApprovedNotification.
 *
 * Se setea al disparar la notificacion en BillingService::activateCompany().
 * Defensa idempotente para que reactivaciones manuales (companies:approve
 * en una empresa ya activa) no spameen al cliente con el mismo correo.
 *
 * Hermano de welcome_email_sent_at y de las otras *_notified_at billing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestampTz('activation_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('activation_notified_at');
        });
    }
};
