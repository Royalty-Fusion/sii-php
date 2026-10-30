<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Calculator;

use RoyaltyFusion\Sii\Model\Totals;

/** Resultado del cálculo de un documento: montos por línea, descuentos globales y totales. */
final class Calculation
{
    /**
     * @param list<CalculatedLine>           $lineas
     * @param list<CalculatedGlobalDiscount> $descuentosGlobales
     * @param list<string>                   $diferencias        diferencias entre los montos informados por el ERP y el cálculo
     */
    public function __construct(
        public readonly array $lineas,
        public readonly array $descuentosGlobales,
        public readonly Totals $totales,
        public readonly ?CalculatedOtherCurrency $otraMoneda,
        public readonly int $decimales,
        public readonly array $diferencias = [],
    ) {
    }
}
