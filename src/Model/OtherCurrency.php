<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Model;

/**
 * Totales en otra moneda (nodo OtraMoneda).
 *
 * TpoCambio es la cantidad de pesos chilenos por unidad de moneda extranjera. Si sólo se indica
 * tipo de moneda y tipo de cambio, el SDK calcula los montos: en documentos nacionales divide los
 * totales en pesos por el tipo de cambio; en exportación los multiplica (equivalente en pesos).
 */
class OtherCurrency
{
    public function __construct(
        private string $tipoMoneda,
        private float|int|null $tipoCambio = null,
        private float|int|null $montoNeto = null,
        private float|int|null $montoExento = null,
        private float|int|null $iva = null,
        private float|int|null $montoTotal = null,
    ) {
    }

    /** Glosa de moneda según tabla del SII (ej. "PESO CL", "DOLAR USA", "EURO"). */
    public function getTipoMoneda(): string
    {
        return $this->tipoMoneda;
    }

    public function getTipoCambio(): float|int|null
    {
        return $this->tipoCambio;
    }

    public function getMontoNeto(): float|int|null
    {
        return $this->montoNeto;
    }

    public function getMontoExento(): float|int|null
    {
        return $this->montoExento;
    }

    public function getIva(): float|int|null
    {
        return $this->iva;
    }

    public function getMontoTotal(): float|int|null
    {
        return $this->montoTotal;
    }
}
