<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Folio;

use RoyaltyFusion\Sii\Caf\Caf;
use RoyaltyFusion\Sii\Caf\CafRepositoryInterface;
use RoyaltyFusion\Sii\Environment;
use RoyaltyFusion\Sii\Exception\CafException;
use RoyaltyFusion\Sii\Model\DocumentType;
use RoyaltyFusion\Sii\Util\Rut;

/**
 * Asignación y consulta de folios a partir de los CAF disponibles.
 *
 * - next(): reserva el siguiente folio de forma atómica, recorriendo los CAF en orden y
 *   omitiendo los vencidos o de otro ambiente.
 * - status(): folios disponibles por CAF y vencimientos (para alertar antes de quedarse sin folios).
 */
final class FolioManager
{
    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    public function __construct(
        private readonly CafRepositoryInterface $cafs,
        private readonly FolioStoreInterface $store = new InMemoryFolioStore(),
        private readonly ?Environment $environment = null,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn () => new \DateTimeImmutable('now', new \DateTimeZone('America/Santiago'));
    }

    /**
     * Reserva y retorna el siguiente folio disponible.
     *
     * @throws CafException si no quedan folios utilizables
     */
    public function next(string $rutEmisor, DocumentType|int $tipo): int
    {
        $rut = Rut::normalize($rutEmisor);
        $tipoDte = $tipo instanceof DocumentType ? $tipo->value : $tipo;
        foreach ($this->usableCafs($rut, $tipoDte) as $caf) {
            $folio = $this->store->reserve($rut, $tipoDte, $caf->getFolioDesde(), $caf->getFolioHasta());
            if ($folio !== null) {
                return $folio;
            }
        }

        throw new CafException(sprintf('No quedan folios disponibles para el tipo %d del emisor %s. Solicite un nuevo CAF al SII.', $tipoDte, $rut));
    }

    /** Libera un folio reservado que no llegó a emitirse (sólo si sigue siendo el último). */
    public function release(string $rutEmisor, DocumentType|int $tipo, int $folio): bool
    {
        return $this->store->release(Rut::normalize($rutEmisor), $tipo instanceof DocumentType ? $tipo->value : $tipo, $folio);
    }

    /** Registra el último folio usado (al migrar desde otro sistema o sincronizar con el ERP). */
    public function setUltimoUsado(string $rutEmisor, DocumentType|int $tipo, int $folio): void
    {
        $this->store->setLast(Rut::normalize($rutEmisor), $tipo instanceof DocumentType ? $tipo->value : $tipo, $folio);
    }

    /**
     * Estado de los CAF de un tipo de documento.
     *
     * @return list<FolioStatus>
     */
    public function status(string $rutEmisor, DocumentType|int $tipo): array
    {
        $rut = Rut::normalize($rutEmisor);
        $tipoDte = $tipo instanceof DocumentType ? $tipo->value : $tipo;
        $last = $this->store->getLast($rut, $tipoDte);
        $now = ($this->clock)();
        $out = [];
        foreach ($this->cafs->findAll($rut, $tipoDte) as $caf) {
            $usado = $last === null || $last < $caf->getFolioDesde() ? null : min($last, $caf->getFolioHasta());
            $disponibles = $usado === null ? $caf->getCantidadFolios() : $caf->getFolioHasta() - $usado;
            $out[] = new FolioStatus(
                $tipoDte,
                $caf->getFolioDesde(),
                $caf->getFolioHasta(),
                $usado,
                $disponibles,
                $caf->getFechaAutorizacion(),
                $caf->getFechaVencimiento(),
                $caf->isVencido($now),
                $caf->isCertificacion(),
            );
        }

        return $out;
    }

    /** Total de folios utilizables (no vencidos y del ambiente correcto). */
    public function disponibles(string $rutEmisor, DocumentType|int $tipo): int
    {
        $total = 0;
        foreach ($this->status($rutEmisor, $tipo) as $s) {
            if ($s->isUtilizable() && $this->ambienteCorrecto($s->certificacion)) {
                $total += $s->disponibles;
            }
        }

        return $total;
    }

    /** True si quedan menos de $minimo folios utilizables (momento de pedir un CAF nuevo). */
    public function requiereNuevoCaf(string $rutEmisor, DocumentType|int $tipo, int $minimo = 10): bool
    {
        return $this->disponibles($rutEmisor, $tipo) < $minimo;
    }

    /** @return list<Caf> */
    private function usableCafs(string $rut, int $tipoDte): array
    {
        $now = ($this->clock)();

        return array_values(array_filter(
            $this->cafs->findAll($rut, $tipoDte),
            fn (Caf $caf) => !$caf->isVencido($now) && $this->ambienteCorrecto($caf->isCertificacion()),
        ));
    }

    private function ambienteCorrecto(bool $cafCertificacion): bool
    {
        return $this->environment === null || $this->environment->isProduccion() !== $cafCertificacion;
    }
}
