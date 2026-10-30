<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Rcv;

final class RcvConciliacionResultado
{
    /**
     * @param list<RcvDocument>                                                                                                                  $coincidentes
     * @param list<RcvDocument>                                                                                                                  $soloEnSii    en el SII y no en el ERP (ej. facturas de proveedores no recibidas por correo)
     * @param list<array{rut: string, tipo: int, folio: int, monto_total: int|float}>                                                          $soloEnErp    en el ERP y no en el RCV
     * @param list<array{sii: RcvDocument, erp: array{rut: string, tipo: int, folio: int, monto_total: int|float}, diferencia: int}> $diferencias  montos distintos
     */
    public function __construct(
        public readonly array $coincidentes,
        public readonly array $soloEnSii,
        public readonly array $soloEnErp,
        public readonly array $diferencias,
    ) {
    }

    public function isConciliado(): bool
    {
        return $this->soloEnSii === [] && $this->soloEnErp === [] && $this->diferencias === [];
    }
}
