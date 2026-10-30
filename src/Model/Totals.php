<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Model;

/**
 * Totales del documento (nodo Totales).
 *
 * El SDK calcula siempre los totales a partir del detalle. Si el ERP informa sus propios totales
 * (Dte::setTotalesEsperados()), el validador los compara contra el cálculo antes de emitir.
 */
class Totals
{
    /** @param list<TaxTotal> $impuestos */
    public function __construct(
        public float|int|null $montoNeto = null,
        public float|int|null $montoExento = null,
        public ?float $tasaIva = null,
        public ?int $iva = null,
        public array $impuestos = [],
        public ?int $garantiaDeposito = null,
        public float|int $montoTotal = 0,
        public ?int $montoNoFacturable = null,
        public ?int $montoPeriodo = null,
        public ?int $saldoAnterior = null,
        public ?int $valorPagar = null,
        public ?string $tipoMoneda = null,
    ) {
    }

    public function getMontoNeto(): float|int|null
    {
        return $this->montoNeto;
    }

    public function getMontoExento(): float|int|null
    {
        return $this->montoExento;
    }

    public function getTasaIva(): ?float
    {
        return $this->tasaIva;
    }

    public function getIva(): ?int
    {
        return $this->iva;
    }

    /** @return list<TaxTotal> */
    public function getImpuestos(): array
    {
        return $this->impuestos;
    }

    public function getMontoTotal(): float|int
    {
        return $this->montoTotal;
    }

    public function getMontoNoFacturable(): ?int
    {
        return $this->montoNoFacturable;
    }

    public function getTipoMoneda(): ?string
    {
        return $this->tipoMoneda;
    }
}
