<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Ws\Result;

/** Estado de un DTE consultado en el SII (QueryEstDte / QueryEstDteAv / API boletas). */
final class DteStatus
{
    public const ESTADOS = [
        'DOK' => 'Documento recibido por el SII. Datos coinciden con los registrados',
        'DNK' => 'Documento recibido por el SII pero datos NO coinciden con los registrados',
        'FAU' => 'Documento no recibido por el SII',
        'FNA' => 'Documento no autorizado',
        'FAN' => 'Documento anulado',
        'EMP' => 'Empresa no autorizada a emitir documentos tributarios electrónicos',
        'TMD' => 'Existe nota de débito que modifica texto del documento',
        'TMC' => 'Existe nota de crédito que modifica textos del documento',
        'MMD' => 'Existe nota de débito que modifica montos del documento',
        'MMC' => 'Existe nota de crédito que modifica montos del documento',
        'AND' => 'Existe nota de débito que anula documento',
        'ANC' => 'Existe nota de crédito que anula documento',
    ];

    public function __construct(
        public readonly ?string $estado,
        public readonly ?string $glosa,
        public readonly ?string $codigoError = null,
        public readonly ?string $glosaError = null,
        public readonly ?string $numeroAtencion = null,
        public readonly string $rawResponse = '',
    ) {
    }

    /** El SII tiene el documento y sus datos coinciden. */
    public function isOk(): bool
    {
        return \in_array($this->estado, ['DOK', 'TMD', 'TMC', 'MMD', 'MMC'], true);
    }

    public function getDescripcion(): string
    {
        return $this->glosa ?? (self::ESTADOS[$this->estado ?? ''] ?? (string) $this->estado);
    }
}
