<?php

namespace App\Models;

use Database\Factories\UserAcceptanceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro inmutable de aceptación de documentos legales por parte de un usuario.
 *
 * Se crea al completar el enrollment (terms, privacy) y al crear una empresa (contract).
 * Almacena una copia del contenido del documento en el momento de la aceptación
 * para trazabilidad legal incluso si el documento se actualiza posteriormente.
 * document_type: 'terms', 'privacy', 'contract' (valores canónicos).
 * El alias histórico 'tos' fue migrado a 'terms' en la migración
 * `2026_05_18_202926_unify_user_acceptances_document_type_terms.php`.
 */
class UserAcceptance extends Model
{
    /** @use HasFactory<UserAcceptanceFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'document_type',
        'document_version',
        'document_content',
        'accepted_at',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
