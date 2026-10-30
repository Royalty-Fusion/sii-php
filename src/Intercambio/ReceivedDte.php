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
        /** Contenido completo (líneas, referencias, impuestos, totales del emisor). */
        public readonly ?ParsedDte $contenido = null,
    ) {
    }

    /** Documento completo como modelo del SDK (null si el tipo no está modelado). */
    public function getDocumento(): ?\RoyaltyFusion\Sii\Model\Dte
    {
        return $this->contenido?->documento;
    }

    /** @return list<\RoyaltyFusion\Sii\Model\Detail> */
    public function getDetalles(): array
    {
        return $this->contenido?->getDetalles() ?? [];
    }

    /** @return list<\RoyaltyFusion\Sii\Model\Reference> */
    public function getReferencias(): array
    {
        return $this->contenido?->getReferencias() ?? [];
    }

    /**
     * Referencias de un tipo, por ejemplo "801" (orden de compra) para conciliar con el ERP.
     *
     * @return list<\RoyaltyFusion\Sii\Model\Reference>
     */
    public function getReferenciasPorTipo(string|int $tipo): array
    {
        return $this->contenido?->getReferenciasPorTipo($tipo) ?? [];
    }

    public function getId(): string
    {
        return sprintf('T%dF%d', $this->tipoDte, $this->folio);
    }
}
