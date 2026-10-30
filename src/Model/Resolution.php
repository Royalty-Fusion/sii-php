<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Model;

/**
 * Resolución SII que autoriza al emisor como facturador electrónico (FchResol / NroResol de la carátula).
 *
 * En el ambiente de certificación el número de resolución es 0 y la fecha es la entregada por el SII
 * al postular; en producción se usa la resolución definitiva.
 */
final class Resolution
{
    public readonly \DateTimeImmutable $fecha;

    public function __construct(\DateTimeInterface|string $fecha, public readonly int $numero)
    {
        $this->fecha = \is_string($fecha)
            ? \RoyaltyFusion\Sii\Util\Clock::date(substr(trim($fecha), 0, 10))
            : \DateTimeImmutable::createFromInterface($fecha);
    }
}
