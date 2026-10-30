<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Folio;

/**
 * Registro del último folio usado por emisor y tipo de documento.
 *
 * La reserva debe ser atómica: dos procesos simultáneos nunca deben obtener el mismo folio.
 * En un ERP con base de datos implemente esta interfaz con un bloqueo de fila
 * (SELECT ... FOR UPDATE) o una secuencia.
 */
interface FolioStoreInterface
{
    /**
     * Reserva el siguiente folio libre dentro de [$desde, $hasta]. Retorna null si el rango está agotado.
     */
    public function reserve(string $rutEmisor, int $tipoDte, int $desde, int $hasta): ?int;

    /** Último folio reservado (o null si nunca se ha usado uno para ese tipo). */
    public function getLast(string $rutEmisor, int $tipoDte): ?int;

    /**
     * Libera el folio si sigue siendo el último reservado (por ejemplo, si la emisión falló
     * antes de firmarse). Retorna true si se liberó.
     */
    public function release(string $rutEmisor, int $tipoDte, int $folio): bool;

    /** Fija el último folio usado (por ejemplo, al migrar desde otro sistema). */
    public function setLast(string $rutEmisor, int $tipoDte, int $folio): void;
}
