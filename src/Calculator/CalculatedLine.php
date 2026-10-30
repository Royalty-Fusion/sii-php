<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Calculator;

use RoyaltyFusion\Sii\Model\Detail;

final class CalculatedLine
{
    public function __construct(
        public readonly int $numero,
        public readonly Detail $detalle,
        public readonly LineGroup $grupo,
        public readonly float|int $descuentoMonto,
        public readonly float|int $recargoMonto,
        public readonly float|int $montoItem,
    ) {
    }
}
