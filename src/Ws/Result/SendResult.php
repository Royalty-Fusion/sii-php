<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Ws\Result;

use RoyaltyFusion\Sii\Exception\InvalidArgumentException;
use RoyaltyFusion\Sii\Util\Clock;

/**
 * Resultado del envío (upload) de un sobre EnvioDTE o EnvioBOLETA.
 *
 * Que el SII reciba el sobre (TrackID) NO significa que los documentos estén aceptados: el resultado
 * final se obtiene después con See::getStatus() / See::resumeStatus().
 *
 * toArray()/fromArray() permiten persistir el envío en el ERP y consultar su estado desde otro proceso.
 */
final class SendResult
{
    public const FORMAT_VERSION = 1;

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

    public readonly \DateTimeImmutable $sentAt;

    /**
     * @param list<string>      $errors
     * @param list<DocumentRef> $documents
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?string $trackId,
        /** STATUS del upload (DTE) o estado ("REC") de la API de boletas. */
        public readonly ?string $status,
        public readonly string $message,
        /** Sobre firmado enviado (ISO-8859-1). */
        public readonly string $xml,
        public readonly string $fileName,
        public readonly string $rawResponse,
        public readonly ?string $receivedAt = null,
        public readonly array $errors = [],
        public readonly ?string $rutEmisor = null,
        public readonly ?string $rutEnvia = null,
        public readonly bool $boleta = false,
        public readonly array $documents = [],
        public readonly ?string $environment = null,
        ?\DateTimeInterface $sentAt = null,
    ) {
        $this->sentAt = $sentAt === null ? Clock::now() : Clock::toChile($sentAt);
    }

    /** El SII recibió el sobre y entregó TrackID (resultado pendiente de procesamiento). */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /** Recibido por el SII, falta conocer el resultado: consultar con getStatus()/resumeStatus(). */
    public function isPending(): bool
    {
        return $this->success;
    }

    /**
     * El SII rechazó la recepción del sobre (STATUS distinto de 0). Los folios NO quedaron registrados
     * en el SII: se puede corregir y reenviar con los mismos folios.
     */
    public function isRejected(): bool
    {
        return !$this->success;
    }

    public function getTrackId(): ?string
    {
        return $this->trackId;
    }

    public function getXml(): string
    {
        return $this->xml;
    }

    /**
     * @param list<DocumentRef> $documents
     */
    public function withContext(array $documents, string $environment, ?\DateTimeInterface $sentAt = null, ?string $rutEmisor = null, ?string $rutEnvia = null, ?bool $boleta = null): self
    {
        return new self(
            $this->success, $this->trackId, $this->status, $this->message, $this->xml, $this->fileName, $this->rawResponse,
            $this->receivedAt, $this->errors, $rutEmisor ?? $this->rutEmisor, $rutEnvia ?? $this->rutEnvia,
            $boleta ?? $this->boleta, $documents, $environment, $sentAt ?? $this->sentAt,
        );
    }

    /**
     * Estado serializable (JSON) para guardar en el ERP. El sobre va en base64 (es ISO-8859-1).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => self::FORMAT_VERSION,
            'ambiente' => $this->environment,
            'track_id' => $this->trackId,
            'exito' => $this->success,
            'estado_envio' => $this->status,
            'mensaje' => $this->message,
            'errores' => $this->errors,
            'rut_emisor' => $this->rutEmisor,
            'rut_envia' => $this->rutEnvia,
            'boleta' => $this->boleta,
            'documentos' => array_map(static fn (DocumentRef $d) => $d->toArray(), $this->documents),
            'enviado_en' => $this->sentAt->format(\DateTimeInterface::ATOM),
            'recibido_en' => $this->receivedAt,
            'archivo' => $this->fileName,
            'sobre' => base64_encode($this->xml),
            'respuesta' => $this->rawResponse,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (($data['version'] ?? null) !== self::FORMAT_VERSION) {
            throw new InvalidArgumentException('Formato de estado de envío desconocido.');
        }
        $xml = base64_decode((string) ($data['sobre'] ?? ''), true);

        return new self(
            success: (bool) ($data['exito'] ?? false),
            trackId: isset($data['track_id']) ? (string) $data['track_id'] : null,
            status: isset($data['estado_envio']) ? (string) $data['estado_envio'] : null,
            message: (string) ($data['mensaje'] ?? ''),
            xml: $xml === false ? '' : $xml,
            fileName: (string) ($data['archivo'] ?? ''),
            rawResponse: (string) ($data['respuesta'] ?? ''),
            receivedAt: isset($data['recibido_en']) ? (string) $data['recibido_en'] : null,
            errors: array_values(array_map('strval', (array) ($data['errores'] ?? []))),
            rutEmisor: isset($data['rut_emisor']) ? (string) $data['rut_emisor'] : null,
            rutEnvia: isset($data['rut_envia']) ? (string) $data['rut_envia'] : null,
            boleta: (bool) ($data['boleta'] ?? false),
            documents: array_map(static fn ($d) => DocumentRef::fromArray((array) $d), array_values((array) ($data['documentos'] ?? []))),
            environment: isset($data['ambiente']) ? (string) $data['ambiente'] : null,
            sentAt: isset($data['enviado_en']) ? new \DateTimeImmutable((string) $data['enviado_en']) : null,
        );
    }
}
