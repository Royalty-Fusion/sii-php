<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Folio;

/** Información de timbraje entregada por el sitio del SII antes de solicitar folios. */
final class FolioInfo
{
    /**
     * @param array<string, string> $campos campos del formulario del SII (MAX_AUTOR, FOLIO_INI, etc.)
     */
    public function __construct(
        public readonly int $tipoDte,
        /** Cantidad máxima de folios que el SII autoriza en esta solicitud (MAX_AUTOR), si se informa. */
        public readonly ?int $maximoAutorizado,
        public readonly array $campos,
        /** Texto visible de la página (contiene los mensajes del SII). */
        public readonly string $texto,
    ) {
    }
}
