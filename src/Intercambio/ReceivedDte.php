<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Intercambio;

/** DTE recibido dentro de un EnvioDTE de un proveedor. */
final class ReceivedDte
{
    public function __construct(
        public readonly int $tipoDte,
        public readonly int $folio,
        public readonly \DateTimeImmutable $fechaEmision,
        public readonly string $rutEmisor,
        public readonly string $razonSocialEmisor,
        public readonly string $rutReceptor,
        public readonly int $montoTotal,
        public readonly bool $firmaValida,
        public readonly bool $timbreValido,
        /** XML del DTE tal como venía en el sobre (UTF-8). */
        public readonly string $xml,
        public readonly ?int $montoNeto = null,
        public readonly ?int $iva = null,
        public readonly ?int $montoExento = null,
    ) {
    }

    public function getId(): string
    {
        return sprintf('T%dF%d', $this->tipoDte, $this->folio);
    }
}
