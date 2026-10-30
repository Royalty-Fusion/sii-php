<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Model;

/** Descuento o recargo global (nodo DscRcgGlobal). */
class GlobalDiscount
{
    public const DESCUENTO = 'D';
    public const RECARGO = 'R';
    public const PORCENTAJE = '%';
    public const MONTO = '$';

    /** IndExeDR: afecta a ítems exentos. */
    public const EXENTOS = 1;
    /** IndExeDR: afecta a ítems no facturables. */
    public const NO_FACTURABLES = 2;

    public function __construct(
        private string $tipo = self::DESCUENTO,
        private string $tipoValor = self::PORCENTAJE,
        private float|int $valor = 0,
        private ?string $glosa = null,
        private ?int $indicadorExencion = null,
    ) {
    }

    public static function descuentoPorcentaje(float|int $porcentaje, ?string $glosa = null, ?int $indicadorExencion = null): self
    {
        return new self(self::DESCUENTO, self::PORCENTAJE, $porcentaje, $glosa, $indicadorExencion);
    }

    public static function descuentoMonto(float|int $monto, ?string $glosa = null, ?int $indicadorExencion = null): self
    {
        return new self(self::DESCUENTO, self::MONTO, $monto, $glosa, $indicadorExencion);
    }

    public static function recargoPorcentaje(float|int $porcentaje, ?string $glosa = null, ?int $indicadorExencion = null): self
    {
        return new self(self::RECARGO, self::PORCENTAJE, $porcentaje, $glosa, $indicadorExencion);
    }

    public static function recargoMonto(float|int $monto, ?string $glosa = null, ?int $indicadorExencion = null): self
    {
        return new self(self::RECARGO, self::MONTO, $monto, $glosa, $indicadorExencion);
    }

    public function getTipo(): string
    {
        return $this->tipo;
    }

    public function setTipo(string $tipo): static
    {
        $this->tipo = $tipo;

        return $this;
    }

    public function getTipoValor(): string
    {
        return $this->tipoValor;
    }

    public function setTipoValor(string $tipoValor): static
    {
        $this->tipoValor = $tipoValor;

        return $this;
    }

    public function getValor(): float|int
    {
        return $this->valor;
    }

    public function setValor(float|int $valor): static
    {
        $this->valor = $valor;

        return $this;
    }

    public function getGlosa(): ?string
    {
        return $this->glosa;
    }

    public function setGlosa(?string $glosa): static
    {
        $this->glosa = $glosa;

        return $this;
    }

    public function getIndicadorExencion(): ?int
    {
        return $this->indicadorExencion;
    }

    public function setIndicadorExencion(?int $indicadorExencion): static
    {
        $this->indicadorExencion = $indicadorExencion;

        return $this;
    }

    public function isDescuento(): bool
    {
        return $this->tipo === self::DESCUENTO;
    }

    public function isPorcentaje(): bool
    {
        return $this->tipoValor === self::PORCENTAJE;
    }
}
