<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Calculator;

use RoyaltyFusion\Sii\Model\GlobalDiscount;

final class CalculatedGlobalDiscount
{
    public function __construct(
        public readonly int $numero,
        public readonly GlobalDiscount $descuento,
        public readonly LineGroup $grupo,
        /** Monto con signo: negativo para descuentos, positivo para recargos. */
        public readonly float|int $monto,
    ) {
    }
}
