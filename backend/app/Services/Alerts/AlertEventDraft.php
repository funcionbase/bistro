<?php

declare(strict_types=1);

namespace App\Services\Alerts;

/**
 * DTO inmutable que un Evaluator devuelve al AlertEngine.
 *
 * El AlertEngine traduce esto a una fila en alert_events (insert o update si
 * ya existe el evento del día por dedup index).
 */
final class AlertEventDraft
{
    public function __construct(
        public readonly string $type,
        public readonly string $severity,
        public readonly string $targetType,
        public readonly ?string $targetId,
        /** @var array<string, mixed> */
        public readonly array $payload,
    ) {}
}
