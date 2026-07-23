<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F3 — dos columnas que la UI necesita y que F1 no previó.
 *
 * Van juntas en UNA migración a propósito: cada `ALTER TABLE` en pdn es una
 * ventana de riesgo sin entorno de qa, y partirlas en dos archivos duplicaría
 * esa ventana sin ganar nada.
 *
 * 1. `failure_reason` — motivo real del fallo de un saliente (§8.4b punto 4).
 *    `WhatsappOutboundMessageSender::markFailed()` ya lo CALCULA
 *    (`recipient_not_on_whatsapp` vs `evolution_api_error`) pero solo lo escribe
 *    en el log: el operador ve un ícono rojo mudo y no sabe si corregir el
 *    teléfono o reintentar. Es un dato que ya existe y se tira.
 *    Se guarda el código corto, NO el texto del proveedor: el copy en español lo
 *    arma el frontend. El mensaje crudo de Evolution puede traer el número del
 *    cliente, y esta columna se renderiza en pantalla.
 *
 * 2. `from_device` — el mensaje lo mandó el dueño desde su celular, no desde el
 *    panel (§8.4 punto 4). Sin la columna el único discriminante sería
 *    `sender='operator' AND sent_by_user_id IS NULL`, que también matchea a
 *    TODOS los mensajes anteriores a F1 — los rotularía "desde el celular"
 *    siendo falso. Una etiqueta equivocada es peor que ninguna: el operador
 *    concluiría que el dueño contestó cuando no lo hizo.
 *
 * Ambas aditivas y con default: compatibles hacia atrás. Durante un instance
 * refresh conviven dos versiones de la app y la vieja las ignora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->string('failure_reason', 60)->nullable()->after('status');
            $table->boolean('from_device')->default(false)->after('sent_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->dropColumn(['failure_reason', 'from_device']);
        });
    }
};
