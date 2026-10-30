<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Folio;

/** Estado de un CAF: folios usados, disponibles y vencimiento. */
final class FolioStatus
{
    public function __construct(
        public readonly int $tipoDte,
        public readonly int $desde,
        public readonly int $hasta,
        /** Último folio usado dentro de este CAF (null si no se ha usado ninguno). */
        public readonly ?int $ultimoUsado,
        public readonly int $disponibles,
        public readonly \DateTimeImmutable $fechaAutorizacion,
        public readonly ?\DateTimeImmutable $fechaVencimiento,
        public readonly bool $vencido,
        public readonly bool $certificacion,
    ) {
    }

    public function getSiguiente(): ?int
    {
        if ($this->disponibles === 0 || $this->vencido) {
            return null;
        }

        return $this->ultimoUsado === null ? $this->desde : $this->ultimoUsado + 1;
    }

    public function isAgotado(): bool
    {
        return $this->disponibles === 0;
    }

    public function isUtilizable(): bool
    {
        return !$this->vencido && $this->disponibles > 0;
    }
}
