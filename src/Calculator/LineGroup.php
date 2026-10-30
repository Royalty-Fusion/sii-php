<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Calculator;

/** Clasificación tributaria de una línea de detalle para el cálculo de totales. */
enum LineGroup: string
{
    case AFECTO = 'afecto';
    case EXENTO = 'exento';
    case NO_FACTURABLE = 'no_facturable';
    case NO_FACTURABLE_NEGATIVO = 'no_facturable_negativo';
    case GARANTIA = 'garantia';
    case EXCLUIDO = 'excluido';
}
