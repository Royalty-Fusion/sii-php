<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Ws\Result;

/** Respuesta del registro de aceptación/reclamo de DTE. */
final class ReclamoResult
{
    /**
     * @param list<array{codigo: string, descripcion: string, rutResponsable: string, fecha: string}> $eventos
     */
    public function __construct(
        public readonly int $codigo,
        public readonly string $descripcion,
        public readonly array $eventos = [],
        public readonly string $rawResponse = '',
    ) {
    }

    public function isOk(): bool
    {
        return $this->codigo === 0;
    }
}
