<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Model;

/** Total de un impuesto adicional o retención (Totales/ImptoReten). */
final class TaxTotal
{
    public function __construct(
        public readonly string $codigo,
        public readonly float $tasa,
        public readonly int $monto,
        public readonly bool $retencion,
    ) {
    }
}
