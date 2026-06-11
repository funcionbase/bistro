<?php

declare(strict_types=1);

namespace App\Services\Dian;

use App\Services\Dian\DTOs\DocumentDto;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

/**
 * Representación gráfica del documento DIAN (PDF + QR).
 *
 * Renderiza `dian.representation` Blade con los datos del DTO + QR PNG en
 * base64. El QR apunta al catálogo público DIAN
 * (`https://catalogo-vpfe[-hab].dian.gov.co/document/searchqr?documentkey=`)
 * para que el adquirente pueda validar el documento desde su celular.
 *
 * El PDF se persiste en S3 como `companies/{nit}/dian/{yyyy}/{mm}/{full_number}.pdf`.
 * Storage se decide vía `config('dian.storage_disk')` (s3 en PDN, s3-minio
 * en dev — jamás local, regla §12 CLAUDE.md).
 */
class DianRepresentationPdfBuilder
{
    public function build(DocumentDto $dto, string $cufeOrCude): string
    {
        $qrText = $this->qrUrl($dto, $cufeOrCude);
        $qrPng = $this->renderQrPng($qrText);

        $pdf = Pdf::loadView('dian.representation', [
            'dto' => $dto,
            'cufeOrCude' => $cufeOrCude,
            'qrBase64' => base64_encode($qrPng),
            'environmentLabel' => $dto->environment === 'produccion'
                ? null
                : 'AMBIENTE DE HABILITACIÓN — DOCUMENTO DE PRUEBA',
        ]);

        return $pdf->output();
    }

    private function qrUrl(DocumentDto $dto, string $cufeOrCude): string
    {
        $base = (string) config("dian.qr_base_url.{$dto->environment}");

        return $base.$cufeOrCude;
    }

    private function renderQrPng(string $text): string
    {
        $qr = new QrCode(
            data: $text,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 320,
            margin: 12,
        );

        return (new PngWriter)->write($qr)->getString();
    }
}
