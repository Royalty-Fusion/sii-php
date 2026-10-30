<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Model;

/** Pago programado (nodo MntPagos). */
class Payment
{
    public function __construct(
        private \DateTimeInterface $fecha,
        private int $monto,
        private ?string $glosa = null,
    ) {
    }

    public function getFecha(): \DateTimeInterface
    {
        return $this->fecha;
    }

    public function getMonto(): int
    {
        return $this->monto;
    }

    public function getGlosa(): ?string
    {
        return $this->glosa;
    }
}
