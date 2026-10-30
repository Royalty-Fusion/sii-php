<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Caf;

/** Origen de los CAF del emisor (archivos, base de datos del ERP, etc.). */
interface CafRepositoryInterface
{
    /** Retorna el CAF que autoriza el folio indicado, o null si no existe. */
    public function find(string $rutEmisor, int $tipoDte, int $folio): ?Caf;
}
