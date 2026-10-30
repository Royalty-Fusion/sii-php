<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Calculator;

use RoyaltyFusion\Sii\Model\AbstractDocument;
use RoyaltyFusion\Sii\Model\AdditionalTax;
use RoyaltyFusion\Sii\Model\Boleta;
use RoyaltyFusion\Sii\Model\Detail;
use RoyaltyFusion\Sii\Model\Dte;
use RoyaltyFusion\Sii\Model\GlobalDiscount;
use RoyaltyFusion\Sii\Model\TaxTotal;
use RoyaltyFusion\Sii\Model\Totals;

/**
 * Calcula montos de línea y totales según las reglas del formato de DTE del SII:
 *
 * - MontoItem = (QtyItem × PrcItem) − DescuentoMonto + RecargoMonto
 * - DescuentoMonto = (QtyItem × PrcItem) × DescuentoPct / 100 (análogo para recargos)
 * - MntNeto = Σ MontoItem afectos ± descuentos/recargos globales afectos
 * - IVA = MntNeto × TasaIVA / 100
 * - MntTotal = MntNeto + MntExe + IVA + impuestos adicionales − retenciones + garantía de envases
 *
 * En documentos nacionales los montos se redondean a pesos enteros; en exportación se usan
 * 2 decimales salvo que la moneda sea "PESO CL".
 */
final class TotalsCalculator
{
    public function calculate(AbstractDocument $document): Calculation
    {
        $tipo = $document->getTipo();
        $decimales = $this->decimales($document);
        $diferencias = [];

        $lineas = [];
        $sumas = array_fill_keys(array_map(static fn (LineGroup $g) => $g->value, LineGroup::cases()), 0.0);
        foreach ($document->getDetalles() as $i => $detalle) {
            $linea = $this->calcularLinea($i + 1, $detalle, $document, $decimales, $diferencias);
            $lineas[] = $linea;
            $sumas[$linea->grupo->value] += $linea->montoItem;
        }

        $globales = [];
        $ajustes = array_fill_keys(array_keys($sumas), 0.0);
        foreach ($document->getDescuentosGlobales() as $i => $descuento) {
            $grupo = match ($descuento->getIndicadorExencion()) {
                GlobalDiscount::EXENTOS => LineGroup::EXENTO,
                GlobalDiscount::NO_FACTURABLES => LineGroup::NO_FACTURABLE,
                default => $tipo->isExento() ? LineGroup::EXENTO : LineGroup::AFECTO,
            };
            $base = $sumas[$grupo->value];
            $monto = $descuento->isPorcentaje()
                ? self::round($base * (float) $descuento->getValor() / 100, $decimales)
                : self::round((float) $descuento->getValor(), $decimales);
            $monto = $descuento->isDescuento() ? -$monto : $monto;
            $ajustes[$grupo->value] += $monto;
            $globales[] = new CalculatedGlobalDiscount($i + 1, $descuento, $grupo, self::num($monto, $decimales));
        }

        $afecto = self::round($sumas[LineGroup::AFECTO->value] + $ajustes[LineGroup::AFECTO->value], $decimales);
        $exento = self::round($sumas[LineGroup::EXENTO->value] + $ajustes[LineGroup::EXENTO->value], $decimales);
        $noFacturable = (int) round($sumas[LineGroup::NO_FACTURABLE->value] + $ajustes[LineGroup::NO_FACTURABLE->value] - $sumas[LineGroup::NO_FACTURABLE_NEGATIVO->value]);
        $garantia = (int) round($sumas[LineGroup::GARANTIA->value]);
        $hayAfectos = $this->hayGrupo($lineas, LineGroup::AFECTO);
        $hayExentos = $this->hayGrupo($lineas, LineGroup::EXENTO) || $exento != 0.0;

        if ($document instanceof Boleta) {
            $totales = $this->totalesBoleta($document, $afecto, $exento, $hayAfectos, $hayExentos, $noFacturable);
        } elseif ($tipo->isExportacion()) {
            $totales = new Totals(
                montoExento: self::num($exento, $decimales),
                montoTotal: self::num($exento, $decimales),
                tipoMoneda: $document instanceof Dte ? $document->getTipoMoneda() : null,
            );
        } else {
            \assert($document instanceof Dte);
            $totales = $this->totalesDte($document, $lineas, $sumas[LineGroup::AFECTO->value], $afecto, $exento, $hayAfectos, $hayExentos, $noFacturable, $garantia);
        }

        $esperado = $document->getMontoTotalEsperado();
        if ($esperado !== null && abs((float) $esperado - (float) $totales->montoTotal) > 0.0001) {
            $diferencias[] = sprintf('El monto total informado por el ERP (%s) no coincide con el calculado (%s); diferencia %s.%s',
                $esperado, $totales->montoTotal, self::num((float) $esperado - (float) $totales->montoTotal, $decimales),
                $this->diagnosticoRedondeo($lineas, $totales, $decimales));
        }

        return new Calculation($lineas, $globales, $totales, $this->otraMoneda($document, $totales), $decimales, $diferencias);
    }

    /**
     * Explica dónde puede estar la diferencia con el total del ERP: líneas cuyo monto se redondeó y
     * diferencia entre el IVA por línea (práctica común en ERP) y el IVA sobre el neto total (regla del SII).
     *
     * @param list<CalculatedLine> $lineas
     */
    private function diagnosticoRedondeo(array $lineas, Totals $totales, int $decimales): string
    {
        $partes = [];
        foreach ($lineas as $linea) {
            $d = $linea->detalle;
            if ($d->getPrecio() === null) {
                continue;
            }
            $bruto = (float) ($d->getCantidad() ?? 1) * (float) $d->getPrecio();
            $exacto = round($bruto - $linea->descuentoMonto + $linea->recargoMonto, 6);
            if (abs($exacto - (float) $linea->montoItem) > 1e-6) {
                $partes[] = sprintf('Línea %d "%s": %s × %s = %s, redondeado a %s', $linea->numero, mb_substr((string) $d->getNombre(), 0, 30),
                    rtrim(rtrim(number_format((float) ($d->getCantidad() ?? 1), 6, '.', ''), '0'), '.'),
                    rtrim(rtrim(number_format((float) $d->getPrecio(), 6, '.', ''), '0'), '.'),
                    rtrim(rtrim(number_format($exacto, 6, '.', ''), '0'), '.'), $linea->montoItem);
            }
        }
        if ($totales->iva !== null && $totales->tasaIva !== null && $decimales === 0) {
            $ivaPorLinea = 0;
            foreach ($lineas as $linea) {
                if ($linea->grupo === LineGroup::AFECTO) {
                    $ivaPorLinea += (int) round((float) $linea->montoItem * $totales->tasaIva / 100);
                }
            }
            if ($ivaPorLinea !== $totales->iva) {
                $partes[] = sprintf('IVA por línea = %d, pero el SII calcula el IVA sobre el neto total = %d', $ivaPorLinea, $totales->iva);
            }
        }

        return $partes === [] ? '' : ' Posibles causas: '.implode('; ', $partes).'.';
    }

    public function decimales(AbstractDocument $document): int
    {
        if ($document instanceof Dte && $document->getTipo()->isExportacion()) {
            return strtoupper((string) $document->getTipoMoneda()) === 'PESO CL' ? 0 : 2;
        }

        return 0;
    }

    /**
     * @param list<string> $diferencias
     */
    private function calcularLinea(int $numero, Detail $detalle, AbstractDocument $document, int $decimales, array &$diferencias): CalculatedLine
    {
        $cantidad = $detalle->getCantidad();
        $precio = $detalle->getPrecio();
        $grupo = $this->grupo($detalle, $document);

        if ($precio === null) {
            // Sin precio (ej. nota de crédito que corrige texto): se usa el monto informado o 0.
            $monto = (float) ($detalle->getMontoItem() ?? 0);

            return new CalculatedLine($numero, $detalle, $grupo, 0, 0, self::num(self::round($monto, $decimales), $decimales));
        }

        $bruto = round((float) ($cantidad ?? 1) * (float) $precio, 6);
        $descuento = $this->ajusteLinea($bruto, $detalle->getDescuentoMonto(), $detalle->getDescuentoPorcentaje(), 'descuento', $numero, $diferencias);
        $recargo = $this->ajusteLinea($bruto, $detalle->getRecargoMonto(), $detalle->getRecargoPorcentaje(), 'recargo', $numero, $diferencias);
        $monto = self::round($bruto - $descuento + $recargo, $decimales);

        $informado = $detalle->getMontoItem();
        if ($informado !== null && abs((float) $informado - $monto) > 0.0001) {
            $diferencias[] = sprintf('Línea %d: MontoItem informado (%s) no coincide con cantidad × precio − descuento + recargo (%s).', $numero, $informado, self::num($monto, $decimales));
        }

        return new CalculatedLine($numero, $detalle, $grupo, (int) $descuento, (int) $recargo, self::num($monto, $decimales));
    }

    /**
     * Descuento/recargo de línea: siempre en pesos enteros (MntImpType).
     *
     * @param list<string> $diferencias
     */
    private function ajusteLinea(float $bruto, float|int|null $monto, float|int|null $porcentaje, string $tipo, int $numero, array &$diferencias): int
    {
        $calculado = $porcentaje !== null ? (int) self::round($bruto * (float) $porcentaje / 100, 0) : null;
        if ($monto === null) {
            return $calculado ?? 0;
        }
        if ($calculado !== null && abs($calculado - (float) $monto) > 0.0001) {
            $diferencias[] = sprintf('Línea %d: el %s informado (%s) no corresponde al %s%% (%d).', $numero, $tipo, $monto, $porcentaje, $calculado);
        }

        return (int) round((float) $monto);
    }

    private function grupo(Detail $detalle, AbstractDocument $document): LineGroup
    {
        $indicador = $detalle->getIndicadorExencion();
        $grupo = match ($indicador) {
            null => LineGroup::AFECTO,
            Detail::EXENTO => LineGroup::EXENTO,
            Detail::NO_FACTURABLE => LineGroup::NO_FACTURABLE,
            Detail::NO_FACTURABLE_NEGATIVO => LineGroup::NO_FACTURABLE_NEGATIVO,
            Detail::GARANTIA_ENVASES => LineGroup::GARANTIA,
            default => LineGroup::EXCLUIDO,
        };
        if ($grupo === LineGroup::AFECTO && $document->getTipo()->isExento()) {
            return LineGroup::EXENTO;
        }

        return $grupo;
    }

    /**
     * @param list<CalculatedLine> $lineas
     */
    private function totalesDte(Dte $dte, array $lineas, float $sumaAfectos, float $afecto, float $exento, bool $hayAfectos, bool $hayExentos, int $noFacturable, int $garantia): Totals
    {
        $tasa = $dte->getTasaIva();
        $tipo = $dte->getTipo();
        $neto = null;
        $iva = null;
        $impuestos = [];

        if ($hayAfectos && !$tipo->isExento()) {
            if ($dte->isMontoBruto()) {
                $neto = (int) round($afecto / (1 + $tasa / 100));
                $iva = (int) $afecto - $neto;
            } else {
                $neto = (int) $afecto;
                $iva = (int) round($neto * $tasa / 100);
            }
            $impuestos = $this->impuestosAdicionales($dte, $lineas, $sumaAfectos, (float) $neto);
        }

        $total = ($neto ?? 0) + (int) $exento + ($iva ?? 0) + $garantia;
        foreach ($impuestos as $impuesto) {
            $total += $impuesto->retencion ? -$impuesto->monto : $impuesto->monto;
        }

        return new Totals(
            montoNeto: $neto,
            montoExento: $hayExentos ? (int) $exento : null,
            tasaIva: $neto !== null ? $tasa : null,
            iva: $iva,
            impuestos: $impuestos,
            garantiaDeposito: $garantia !== 0 ? $garantia : null,
            montoTotal: max(0, $total),
            montoNoFacturable: $noFacturable !== 0 ? $noFacturable : null,
        );
    }

    /**
     * @param list<CalculatedLine> $lineas
     *
     * @return list<TaxTotal>
     */
    private function impuestosAdicionales(Dte $dte, array $lineas, float $sumaAfectos, float $neto): array
    {
        $bases = [];
        foreach ($lineas as $linea) {
            if ($linea->grupo !== LineGroup::AFECTO) {
                continue;
            }
            foreach (array_unique($linea->detalle->getImpuestosAdicionales()) as $codigo) {
                $bases[$codigo] = ($bases[$codigo] ?? 0.0) + (float) $linea->montoItem;
            }
        }
        // Descuentos/recargos globales afectos se prorratean sobre la base de cada impuesto.
        $factor = $sumaAfectos != 0.0 ? $neto / $sumaAfectos : 1.0;
        $definidos = $dte->getImpuestosAdicionales();
        $impuestos = [];
        foreach ($bases as $codigo => $base) {
            $codigo = (string) $codigo;
            $definicion = $definidos[$codigo] ?? (isset(AdditionalTax::DEFAULT_RATES[$codigo]) ? new AdditionalTax($codigo) : null);
            if ($definicion === null) {
                continue; // el validador informa el impuesto sin tasa definida
            }
            $monto = (int) round(round($base * $factor, 6) * $definicion->getTasa() / 100);
            $impuestos[] = new TaxTotal($codigo, $definicion->getTasa(), $monto, $definicion->isRetencion());
        }

        return $impuestos;
    }

    private function totalesBoleta(Boleta $boleta, float $afecto, float $exento, bool $hayAfectos, bool $hayExentos, int $noFacturable): Totals
    {
        $tasa = $boleta->getTasaIva();
        $neto = null;
        $iva = null;
        $afecto = (int) $afecto;
        if ($boleta->getTipo()->isExento()) {
            $total = (int) $exento;
        } elseif ($boleta->isMontosNetos()) {
            $neto = $afecto;
            $iva = (int) round($neto * $tasa / 100);
            $total = $neto + $iva + (int) $exento;
        } else {
            if ($hayAfectos) {
                $neto = (int) round($afecto / (1 + $tasa / 100));
                $iva = $afecto - $neto;
            }
            $total = $afecto + (int) $exento;
        }
        $saldo = $boleta->getSaldoAnterior();

        return new Totals(
            montoNeto: $hayAfectos ? $neto : null,
            montoExento: $hayExentos ? (int) $exento : null,
            iva: $hayAfectos ? $iva : null,
            montoTotal: max(0, $total),
            montoNoFacturable: $noFacturable !== 0 ? $noFacturable : null,
            saldoAnterior: $saldo,
            valorPagar: $saldo !== null ? $total + $noFacturable + $saldo : null,
        );
    }

    /**
     * OtraMoneda: TpoCambio es la cantidad de pesos chilenos por unidad de moneda extranjera.
     * - Documento nacional (montos en CLP): monto en otra moneda = monto / TpoCambio.
     * - Exportación (montos en moneda extranjera): monto en otra moneda = monto × TpoCambio.
     */
    private function otraMoneda(AbstractDocument $document, Totals $totales): ?CalculatedOtherCurrency
    {
        if (!$document instanceof Dte || ($otra = $document->getOtraMoneda()) === null) {
            return null;
        }
        $tc = $otra->getTipoCambio();
        $export = $document->getTipo()->isExportacion();
        $dec = strtoupper($otra->getTipoMoneda()) === 'PESO CL' ? 0 : 4;
        $conv = static function (float|int|null $informado, float|int|null $monto) use ($tc, $export, $dec): float|int|null {
            if ($informado !== null) {
                return $informado;
            }
            if ($tc === null || $monto === null || (float) $tc <= 0) {
                return null;
            }
            $valor = $export ? (float) $monto * (float) $tc : (float) $monto / (float) $tc;

            return self::num(self::round($valor, $dec), $dec);
        };

        return new CalculatedOtherCurrency(
            $otra->getTipoMoneda(),
            $tc,
            $export ? null : $conv($otra->getMontoNeto(), $totales->montoNeto),
            $conv($otra->getMontoExento(), $totales->montoExento),
            $export ? null : $conv($otra->getIva(), $totales->iva),
            $conv($otra->getMontoTotal(), $totales->montoTotal) ?? 0,
        );
    }

    /**
     * @param list<CalculatedLine> $lineas
     */
    private function hayGrupo(array $lineas, LineGroup $grupo): bool
    {
        foreach ($lineas as $linea) {
            if ($linea->grupo === $grupo) {
                return true;
            }
        }

        return false;
    }

    public static function round(float $value, int $decimals): float
    {
        return round(round($value, 6), $decimals, \PHP_ROUND_HALF_UP);
    }

    private static function num(float|int $value, int $decimals): float|int
    {
        return $decimals === 0 ? (int) round((float) $value) : round((float) $value, $decimals);
    }
}
