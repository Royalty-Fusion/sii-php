<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Model;

/** Código del ítem (CdgItem): tipo (INT1, EAN13, PLU, DUN14, etc.) y valor. */
class ItemCode
{
    public function __construct(
        private string $tipo,
        private string $valor,
    ) {
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

    public function getValor(): string
    {
        return $this->valor;
    }

    public function setValor(string $valor): static
    {
        $this->valor = $valor;

        return $this;
    }
}
