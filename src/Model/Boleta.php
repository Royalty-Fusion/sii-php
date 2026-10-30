<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Model;

use RoyaltyFusion\Sii\Exception\InvalidArgumentException;

/**
 * Boleta Electrónica (39) y Boleta Exenta Electrónica (41), según EnvioBOLETA_v11.xsd.
 *
 * Por defecto los precios del detalle incluyen IVA (montos brutos); el SDK desglosa neto e IVA.
 */
class Boleta extends AbstractDocument
{
    /** IndServicio: boleta de servicios periódicos. */
    public const SERVICIOS_PERIODICOS = 1;
    /** IndServicio: boleta de servicios periódicos domiciliarios. */
    public const SERVICIOS_PERIODICOS_DOMICILIARIOS = 2;
    /** IndServicio: boleta de ventas y servicios. */
    public const VENTAS_Y_SERVICIOS = 3;
    /** IndServicio: boleta de espectáculos emitida por cuenta de terceros. */
    public const ESPECTACULOS = 4;

    private int $indicadorServicio = self::VENTAS_Y_SERVICIOS;
    private bool $montosNetos = false;
    private ?int $medioPago = null;
    private ?string $rutProveedorSoftware = null;
    private float $tasaIva = 19.0;
    private ?int $saldoAnterior = null;

    public function __construct(DocumentType $tipo = DocumentType::BOLETA)
    {
        if (!$tipo->isBoleta()) {
            throw new InvalidArgumentException(sprintf('El tipo %d no es una boleta; use Dte.', $tipo->value));
        }
        parent::__construct($tipo);
    }

    public static function afecta(): self
    {
        return new self(DocumentType::BOLETA);
    }

    public static function exenta(): self
    {
        return new self(DocumentType::BOLETA_EXENTA);
    }

    public function getIndicadorServicio(): int
    {
        return $this->indicadorServicio;
    }

    public function setIndicadorServicio(int $indicadorServicio): static
    {
        $this->indicadorServicio = $indicadorServicio;

        return $this;
    }

    /** IndMntNeto = 2: los precios del detalle son netos (sin IVA). */
    public function isMontosNetos(): bool
    {
        return $this->montosNetos;
    }

    public function setMontosNetos(bool $montosNetos): static
    {
        $this->montosNetos = $montosNetos;

        return $this;
    }

    public function getMedioPago(): ?int
    {
        return $this->medioPago;
    }

    public function setMedioPago(?int $medioPago): static
    {
        $this->medioPago = $medioPago;

        return $this;
    }

    public function getRutProveedorSoftware(): ?string
    {
        return $this->rutProveedorSoftware;
    }

    public function setRutProveedorSoftware(?string $rut): static
    {
        $this->rutProveedorSoftware = $rut;

        return $this;
    }

    public function getTasaIva(): float
    {
        return $this->tasaIva;
    }

    public function setTasaIva(float $tasaIva): static
    {
        $this->tasaIva = $tasaIva;

        return $this;
    }

    /** Sólo servicios periódicos: saldo anterior a pagar (se suma en VlrPagar). */
    public function getSaldoAnterior(): ?int
    {
        return $this->saldoAnterior;
    }

    public function setSaldoAnterior(?int $saldoAnterior): static
    {
        $this->saldoAnterior = $saldoAnterior;

        return $this;
    }
}
