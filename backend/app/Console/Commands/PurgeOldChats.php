<?php

namespace App\Console\Commands;

use App\Models\Chat;
use App\Models\ChatMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Borra conversaciones (chats + chat_messages) sin actividad por mas de N dias.
 *
 * Conservado intencionalmente:
 *   - contacts:  chats.contact_id → nullOnDelete, los contactos sobreviven con sus notas
 *   - orders:    no tienen FK a chats (los relaciona client_phone), no se tocan
 *
 * Cascade automatica:
 *   - chat_messages: chat_id → cascadeOnDelete
 *
 * Limpieza adicional:
 *   - Archivos en storage/app/public/chat-media/{chat_id}/ se borran del disk
 *     antes de eliminar los chats — cascadeOnDelete solo afecta filas, no
 *     archivos.
 *
 * Uso:
 *   php artisan chats:purge-old           — borra chats con > 60 dias sin actividad
 *   php artisan chats:purge-old --days=90 — umbral custom
 *   php artisan chats:purge-old --dry-run — solo reporta cuantos borraria
 */
class PurgeOldChats extends Command
{
    protected $signature = 'chats:purge-old
                            {--days=60 : Umbral en dias de inactividad (default 60)}
                            {--dry-run : No borra nada, solo reporta}';

    protected $description = 'Elimina chats sin actividad por > N dias preservando contactos, notas y ordenes';

    public function handle(): int
    {
        // Guard DIAN: este comando borra media de chat (assets, no documentos
        // contables). NUNCA debe ejecutarse contra el bucket de documentos
        // (facturas). Si alguien reconfigura FILESYSTEM_DISK apuntando a
        // s3_documents, fallar antes de tocar nada.
        $disk = (string) config('filesystems.default');
        if (in_array($disk, ['s3_documents'], true)) {
            $this->error("DIAN retention: PurgeOldChats no puede correr con FILESYSTEM_DISK={$disk}. Ver issue #43 y CLAUDE.md → REGLAS CONTABLES.");

            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $threshold = now()->subDays($days);

        // Usamos last_message_at; si esta nulo (chat creado pero sin mensajes)
        // caemos al created_at para no acumular chats fantasma.
        $query = Chat::query()
            ->where(function ($q) use ($threshold): void {
                $q->where('last_message_at', '<', $threshold)
                    ->orWhere(function ($q2) use ($threshold): void {
                        $q2->whereNull('last_message_at')->where('created_at', '<', $threshold);
                    });
            });

        $totalCount = (clone $query)->count();

        if ($totalCount === 0) {
            $this->info("No hay chats con mas de {$days} dias de antiguedad.");

            return self::SUCCESS;
        }

        $this->info("Chats candidatos a eliminacion (sin actividad > {$days} dias): {$totalCount}");

        if ($dryRun) {
            $this->warn('--dry-run: no se elimino nada.');

            return self::SUCCESS;
        }

        $mediaFilesDeleted = 0;
        $chatsDeleted = 0;

        // Procesamos en chunks para no inflar memoria con conversaciones masivas.
        $query->orderBy('id')->chunkById(200, function ($chats) use (&$mediaFilesDeleted, &$chatsDeleted, $disk): void {
            $chatIds = $chats->pluck('id')->all();

            // Limpiamos archivos de media asociados antes de borrar el chat.
            $mediaPaths = ChatMessage::query()
                ->whereIn('chat_id', $chatIds)
                ->whereNotNull('media_path')
                ->pluck('media_path');

            foreach ($mediaPaths as $path) {
                if (Storage::disk($disk)->exists($path)) {
                    Storage::disk($disk)->delete($path);
                    $mediaFilesDeleted++;
                }
            }

            // Borrar la carpeta completa por chat (limpia archivos huerfanos
            // que pudieron quedar de descargas a medio camino).
            foreach ($chatIds as $chatId) {
                $folder = "chat-media/{$chatId}";
                if (Storage::disk($disk)->exists($folder)) {
                    Storage::disk($disk)->deleteDirectory($folder);
                }
            }

            // Una sola DELETE por chunk; las cascadas se encargan de chat_messages.
            $deleted = DB::table('chats')->whereIn('id', $chatIds)->delete();
            $chatsDeleted += $deleted;
        });

        $this->info("Chats eliminados: {$chatsDeleted}");
        $this->info("Archivos de media eliminados: {$mediaFilesDeleted}");
        $this->info('Contactos, notas y ordenes preservados.');

        return self::SUCCESS;
    }
}
