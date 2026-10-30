<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Model;

/** Tipo de bultos de exportación (nodo Aduana/TipoBultos). */
class Package
{
    public function __construct(
        private ?int $codigoTipo = null,
        private ?int $cantidad = null,
        private ?string $marcas = null,
        private ?string $idContainer = null,
        private ?string $sello = null,
        private ?string $emisorSello = null,
    ) {
    }

    /** Código del tipo de bulto según tabla de Aduanas. */
    public function getCodigoTipo(): ?int
    {
        return $this->codigoTipo;
    }

    public function getCantidad(): ?int
    {
        return $this->cantidad;
    }

    public function getMarcas(): ?string
    {
        return $this->marcas;
    }

    public function getIdContainer(): ?string
    {
        return $this->idContainer;
    }

    public function getSello(): ?string
    {
        return $this->sello;
    }

    public function getEmisorSello(): ?string
    {
        return $this->emisorSello;
    }
}
