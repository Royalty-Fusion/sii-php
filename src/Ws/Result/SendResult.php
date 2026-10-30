<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Ws\Result;

/** Resultado del envío de un sobre (EnvioDTE o EnvioBOLETA) al SII. */
final class SendResult
{
    /** Glosas de STATUS de DTEUpload. */
    public const UPLOAD_STATUS = [
        '0' => 'Upload OK',
        '1' => 'El Sender no tiene permiso para enviar',
        '2' => 'Error en tamaño del archivo (muy grande o muy chico)',
        '3' => 'Archivo cortado (tamaño distinto al informado)',
        '5' => 'No está autenticado (token inválido o vencido)',
        '6' => 'Empresa no autorizada a enviar archivos',
        '7' => 'Esquema inválido',
        '8' => 'Error en la firma del documento',
        '9' => 'Sistema bloqueado',
    ];

    public function __construct(
        public readonly bool $success,
        public readonly ?string $trackId,
        /** STATUS del upload (DTE) o estado ("REC") de la API de boletas. */
        public readonly ?string $status,
        public readonly string $message,
        /** XML enviado (ISO-8859-1). */
        public readonly string $xml,
        public readonly string $fileName,
        public readonly string $rawResponse,
        public readonly ?string $receivedAt = null,
        /** @var list<string> */
        public readonly array $errors = [],
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getTrackId(): ?string
    {
        return $this->trackId;
    }

    public function getXml(): string
    {
        return $this->xml;
    }
}
