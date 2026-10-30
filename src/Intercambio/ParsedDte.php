<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Intercambio;

use RoyaltyFusion\Sii\Model\Detail;
use RoyaltyFusion\Sii\Model\Dte;
use RoyaltyFusion\Sii\Model\Reference;
use RoyaltyFusion\Sii\Model\Totals;

/**
 * Resultado de leer un DTE: el documento completo como modelo del SDK y los totales tal como los
 * informó el emisor (pueden diferir del cálculo del SDK si el proveedor redondeó distinto).
 */
final class ParsedDte
{
    public function __construct(
        public readonly int $tipoDte,
        public readonly int $folio,
        /** Documento completo; null si el tipo no está modelado (ej. 43). */
        public readonly ?Dte $documento,
        /** Totales informados por el emisor. */
        public readonly Totals $totales,
    ) {
    }

    /** @return list<Detail> */
    public function getDetalles(): array
    {
        return $this->documento?->getDetalles() ?? [];
    }

    /** @return list<Reference> */
    public function getReferencias(): array
    {
        return $this->documento?->getReferencias() ?? [];
    }

    /**
     * Referencias de un tipo (ej. "801" orden de compra, "52" guía de despacho, "HES").
     *
     * @return list<Reference>
     */
    public function getReferenciasPorTipo(string|int $tipo): array
    {
        return array_values(array_filter($this->getReferencias(), static fn (Reference $r) => $r->getTipoDocumento() === (string) $tipo));
    }
}
