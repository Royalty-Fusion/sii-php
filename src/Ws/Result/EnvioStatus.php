<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Ws\Result;

/** Estado de un envío consultado por Track ID (QueryEstUp o API de boletas). */
final class EnvioStatus
{
    /** Estados del envío informados por el SII. */
    public const ESTADOS = [
        'REC' => 'Envío recibido',
        'SOK' => 'Schema validado',
        'CRT' => 'Carátula OK',
        'FOK' => 'Firma de envío validada',
        'PDR' => 'Envío en proceso',
        'EPR' => 'Envío procesado',
        'RPR' => 'Aceptado con reparos',
        'RLV' => 'Aceptado con reparos leves',
        'RCH' => 'Rechazado',
        'RSC' => 'Rechazado por error en schema',
        'RFR' => 'Rechazado por error en firma',
        'RCT' => 'Rechazado por error en carátula',
        '-11' => 'Error en la consulta (track ID inexistente o no corresponde al emisor)',
    ];

    private const FINALES_RECHAZO = ['RSC', 'RFR', 'RCT', 'RCH'];
    private const FINALES_OK = ['EPR', 'RPR', 'RLV'];

    /**
     * @param list<EnvioStatistic> $estadisticas
     * @param list<DocumentResult> $documentos
     */
    public function __construct(
        public readonly string $trackId,
        public readonly ?string $estado,
        public readonly ?string $glosa,
        public readonly array $estadisticas = [],
        public readonly array $documentos = [],
        public readonly ?string $numeroAtencion = null,
        public readonly string $rawResponse = '',
    ) {
    }

    public function getDescripcion(): string
    {
        return $this->glosa ?? (self::ESTADOS[$this->estado ?? ''] ?? (string) $this->estado);
    }

    /** El SII terminó de procesar el envío (aceptado, con reparos o rechazado). */
    public function isFinal(): bool
    {
        return \in_array($this->estado, self::FINALES_OK, true) || \in_array($this->estado, self::FINALES_RECHAZO, true);
    }

    /** Aún en proceso (REC, SOK, CRT, FOK, PDR) o el SII todavía no informa el envío: volver a consultar. */
    public function isPending(): bool
    {
        return !$this->isFinal();
    }

    /** La consulta misma falló (código negativo o numérico, ej. -11): reintentar la consulta más tarde. */
    public function isQueryError(): bool
    {
        return $this->estado !== null && preg_match('/^-?\d+$/', $this->estado) === 1;
    }

    /** Procesado y todos los documentos aceptados sin reparos. */
    public function isAccepted(): bool
    {
        return $this->isProcesado() && $this->getTotal('rechazados') === 0 && !$this->hasReparos();
    }

    /** Procesado, sin rechazos, pero con reparos (el documento es válido; revisar las observaciones). */
    public function isAcceptedWithObjections(): bool
    {
        return $this->isProcesado() && $this->getTotal('rechazados') === 0 && $this->hasReparos();
    }

    /**
     * Rechazado completo: error de schema, firma o carátula (RSC, RFR, RCT, RCH), o procesado con
     * todos sus documentos rechazados. Los folios rechazados NO quedan usados en el SII.
     */
    public function isRejected(): bool
    {
        if (\in_array($this->estado, self::FINALES_RECHAZO, true)) {
            return true;
        }

        return $this->isProcesado() && $this->getTotal('rechazados') > 0 && $this->getTotal('aceptados') === 0 && $this->getTotal('reparos') === 0;
    }

    /** Procesado con algunos documentos aceptados y otros rechazados: revisar cada documento. */
    public function isPartiallyRejected(): bool
    {
        return $this->isProcesado() && $this->getTotal('rechazados') > 0 && ($this->getTotal('aceptados') > 0 || $this->getTotal('reparos') > 0);
    }

    public function hasReparos(): bool
    {
        return $this->estado === 'RPR' || $this->estado === 'RLV' || $this->getTotal('reparos') > 0;
    }

    /** Detalle de un documento rechazado o con reparos (sólo la API de boletas lo informa). */
    public function getDocumento(int $tipoDte, int $folio): ?DocumentResult
    {
        foreach ($this->documentos as $documento) {
            if ($documento->tipoDte === $tipoDte && $documento->folio === $folio) {
                return $documento;
            }
        }

        return null;
    }

    private function isProcesado(): bool
    {
        return \in_array($this->estado, self::FINALES_OK, true);
    }

    public function getTotal(string $campo): int
    {
        $total = 0;
        foreach ($this->estadisticas as $e) {
            $total += match ($campo) {
                'informados' => $e->informados,
                'aceptados' => $e->aceptados,
                'rechazados' => $e->rechazados,
                'reparos' => $e->reparos,
                default => 0,
            };
        }

        return $total;
    }
}
