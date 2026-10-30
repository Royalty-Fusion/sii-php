<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Ws\Result;

/** Detalle de un documento rechazado o con reparos dentro de un envío. */
final class DocumentResult
{
    /** @param list<array<string, mixed>> $errores */
    public function __construct(
        public readonly int $tipoDte,
        public readonly int $folio,
        public readonly string $estado,
        public readonly ?string $descripcion,
        public readonly array $errores = [],
    ) {
    }
}
