<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Model;

/** Chofer del transporte (nodo Transporte/Chofer). */
class Driver
{
    public function __construct(
        private string $rut,
        private string $nombre,
    ) {
    }

    public function getRut(): string
    {
        return $this->rut;
    }

    public function getNombre(): string
    {
        return $this->nombre;
    }
}
