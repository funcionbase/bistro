<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * Resultado inmutable de un intento de envío de SMS (#275).
 */
final readonly class SmsSendResult
{
    public function __construct(
        public bool $sent,
        public ?string $messageId = null,
        public int $segments = 1,
        public ?string $error = null,
    ) {}

    public static function ok(?string $messageId, int $segments): self
    {
        return new self(sent: true, messageId: $messageId, segments: $segments);
    }

    public static function failed(string $error): self
    {
        return new self(sent: false, error: $error);
    }
}
