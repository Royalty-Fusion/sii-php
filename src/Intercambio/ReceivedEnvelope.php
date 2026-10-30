<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Intercambio;

/** Sobre EnvioDTE recibido de un proveedor (intercambio entre contribuyentes). */
final class ReceivedEnvelope
{
    /** @param list<ReceivedDte> $documentos */
    public function __construct(
        public readonly string $setId,
        public readonly string $rutEmisor,
        public readonly string $rutEnvia,
        public readonly string $rutReceptor,
        public readonly ?string $fechaResolucion,
        public readonly ?int $numeroResolucion,
        public readonly ?string $timestampFirma,
        public readonly bool $firmaValida,
        public readonly ?string $digest,
        public readonly array $documentos,
        public readonly bool $schemaValido,
        /** @var list<string> */
        public readonly array $erroresSchema = [],
    ) {
    }
}
