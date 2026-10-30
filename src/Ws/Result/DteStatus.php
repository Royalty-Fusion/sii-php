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

    /** Aceptado: recibido y los datos coinciden (aunque una nota posterior lo haya modificado). */
    public function isAccepted(): bool
    {
        return \in_array($this->estado, ['DOK', 'TMD', 'TMC', 'MMD', 'MMC'], true);
    }

    /** Aceptado con reparos (estados RPR/RLV de la API de boletas). */
    public function isAcceptedWithObjections(): bool
    {
        return \in_array($this->estado, ['RPR', 'RLV'], true);
    }

    /** Rechazado o inválido: no autorizado (FNA), empresa no autorizada (EMP) o rechazado (RCH, RSC, RFR, RCT). */
    public function isRejected(): bool
    {
        return \in_array($this->estado, ['FNA', 'EMP', 'RCH', 'RSC', 'RFR', 'RCT'], true);
    }

    /** El SII aún no tiene el documento (FAU): si se perdió el envío, se puede reenviar con el mismo folio. */
    public function isPending(): bool
    {
        return $this->estado === 'FAU' || $this->estado === null || $this->isQueryError();
    }

    /** El SII tiene un documento con ese folio (el folio está usado, aunque los datos consultados no coincidan). */
    public function isReceived(): bool
    {
        return \in_array($this->estado, ['DOK', 'DNK', 'TMD', 'TMC', 'MMD', 'MMC', 'AND', 'ANC', 'FAN', 'RPR', 'RLV'], true);
    }

    /** DNK: el folio existe en el SII pero tipo/RUT/monto/fecha consultados no coinciden. */
    public function isMismatch(): bool
    {
        return $this->estado === 'DNK';
    }

    /** Anulado (FAN) o anulado por una nota de crédito/débito (ANC/AND). */
    public function isAnnulled(): bool
    {
        return \in_array($this->estado, ['FAN', 'ANC', 'AND'], true);
    }

    /** La consulta falló (estado numérico, ej. -3 o 001): revisar codigoError/glosaError y reintentar. */
    public function isQueryError(): bool
    {
        return $this->estado !== null && preg_match('/^-?\d+$/', $this->estado) === 1;
    }

    /** @deprecated use isAccepted() */
    public function isOk(): bool
    {
        return $this->isAccepted();
    }

    public function getDescripcion(): string
    {
        return $this->glosa ?? (self::ESTADOS[$this->estado ?? ''] ?? (string) $this->estado);
    }
}
