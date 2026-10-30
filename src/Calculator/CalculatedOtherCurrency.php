<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Calculator;

final class CalculatedOtherCurrency
{
    public function __construct(
        public readonly string $tipoMoneda,
        public readonly float|int|null $tipoCambio,
        public readonly float|int|null $montoNeto,
        public readonly float|int|null $montoExento,
        public readonly float|int|null $iva,
        public readonly float|int $montoTotal,
    ) {
    }
}
