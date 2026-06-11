<?php

declare(strict_types=1);

namespace App\Services\Sms;

use Aws\Sns\SnsClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Wrapper de `SnsClient::publish` para enviar SMS al cliente (#275).
 *
 * Origen "internacional best-effort" (Decisión 2 del issue): Colombia no
 * soporta Sender ID alfanumérico ni long codes, así que NO se setea SenderID —
 * el cliente verá un número desconocido y el nombre comercial viaja en el
 * cuerpo. SMSType = Transactional (entrega prioritaria).
 *
 * Credenciales: en qa/pdn provienen del IAM instance profile (IMDS); si
 * `services.sns.key/secret` están presentes (local) se usan explícitas. Mismo
 * patrón que SES/S3.
 *
 * N-instance safe: el sender es stateless; toda la deduplicación vive en la
 * tabla `order_sms_notifications` (UNIQUE order_id+to_status) y en el job.
 */
class SnsSmsSender
{
    private ?SnsClient $client = null;

    /**
     * ¿Está habilitado el envío real? En local/qa el master switch viene en
     * false para no gastar saldo SNS; el job lo registra como skipped.
     */
    public function isEnabled(): bool
    {
        return (bool) config('services.sns.sms_enabled', false);
    }

    /**
     * Publica un SMS a un número ya normalizado a E.164. El texto debe venir
     * saneado; aquí se transifere a ASCII (GSM-7) para mantener 1 segmento y
     * controlar el costo por segmento.
     */
    public function send(string $e164Phone, string $message): SmsSendResult
    {
        $body = $this->toGsmSafe($message);
        $segments = $this->estimateSegments($body);

        try {
            $result = $this->client()->publish([
                'PhoneNumber' => $e164Phone,
                'Message' => $body,
                'MessageAttributes' => [
                    'AWS.SNS.SMS.SMSType' => [
                        'DataType' => 'String',
                        'StringValue' => (string) config('services.sns.sms_type', 'Transactional'),
                    ],
                ],
            ]);

            $messageId = $result['MessageId'] ?? null;

            return SmsSendResult::ok(is_string($messageId) ? $messageId : null, $segments);
        } catch (\Throwable $e) {
            Log::channel('single')->error('order.sms.publish_failed', [
                'message' => $e->getMessage(),
            ]);

            return SmsSendResult::failed($e->getMessage());
        }
    }

    /**
     * Transifere el mensaje a ASCII (GSM-7 seguro). Las tildes y la `ñ`
     * empujarían el SMS a codificación UCS-2 (70 chars/segmento), duplicando
     * costo; `Str::ascii` las normaliza (á→a, ñ→n).
     */
    private function toGsmSafe(string $message): string
    {
        return Str::ascii($message);
    }

    /**
     * Estima segmentos GSM-7: 160 chars el primero, 153 los siguientes
     * (cabecera de concatenación UDH). Para mensajes de 1 segmento devuelve 1.
     */
    private function estimateSegments(string $body): int
    {
        $length = strlen($body);
        if ($length <= 160) {
            return 1;
        }

        return (int) ceil($length / 153);
    }

    private function client(): SnsClient
    {
        if ($this->client instanceof SnsClient) {
            return $this->client;
        }

        $config = [
            'version' => 'latest',
            // Blindaje: si la región quedara vacía (env vacío + cache), caemos a
            // us-east-1 en vez de pasar '' al SDK (que rompería el publish).
            'region' => (string) (config('services.sns.region') ?: 'us-east-1'),
        ];

        $key = config('services.sns.key');
        $secret = config('services.sns.secret');
        if (! empty($key) && ! empty($secret)) {
            $config['credentials'] = ['key' => $key, 'secret' => $secret];
        }

        return $this->client = new SnsClient($config);
    }
}
