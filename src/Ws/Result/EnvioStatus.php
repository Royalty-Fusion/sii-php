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

    /** El SII terminó de procesar el envío (aceptado o rechazado). */
    public function isFinal(): bool
    {
        return \in_array($this->estado, self::FINALES_OK, true) || $this->isRejected();
    }

    /** Envío rechazado completo. */
    public function isRejected(): bool
    {
        return \in_array($this->estado, self::FINALES_RECHAZO, true);
    }

    /** Envío procesado y todos sus documentos aceptados (con o sin reparos). */
    public function isAccepted(): bool
    {
        return \in_array($this->estado, self::FINALES_OK, true) && $this->getTotal('rechazados') === 0;
    }

    public function hasReparos(): bool
    {
        return $this->estado === 'RPR' || $this->estado === 'RLV' || $this->getTotal('reparos') > 0;
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
