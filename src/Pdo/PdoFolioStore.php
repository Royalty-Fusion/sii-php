<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Pdo;

use RoyaltyFusion\Sii\Exception\InvalidArgumentException;
use RoyaltyFusion\Sii\Folio\FolioStoreInterface;

/**
 * Registro del último folio usado en una base de datos (requiere ext-pdo, sin dependencias extra).
 *
 * La reserva es atómica entre procesos y servidores:
 *  - 'row' (por defecto): SELECT … FOR UPDATE dentro de una transacción (MariaDB/MySQL/PostgreSQL);
 *  - 'get_lock': además toma GET_LOCK() de MariaDB/MySQL por emisor y tipo.
 * En SQLite se usa BEGIN IMMEDIATE.
 *
 * $scope separa empresas/establecimientos del ERP multiempresa (por ejemplo "tenant42:sucursal3").
 * Esquema en resources/sql/mariadb.sql.
 */
final class PdoFolioStore implements FolioStoreInterface
{
    private readonly string $table;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $scope = '',
        string $table = 'sii_folios',
        private readonly string $lockMode = 'row',
    ) {
        $this->table = PdoHelper::identifier($table);
        if (!\in_array($lockMode, ['row', 'get_lock'], true)) {
            throw new InvalidArgumentException('lockMode debe ser "row" o "get_lock".');
        }
        if (\strlen($scope) > 64) {
            throw new InvalidArgumentException('El scope admite como máximo 64 caracteres.');
        }
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function reserve(string $rutEmisor, int $tipoDte, int $desde, int $hasta): ?int
    {
        return $this->locked($rutEmisor, $tipoDte, function () use ($rutEmisor, $tipoDte, $desde, $hasta): ?int {
            $last = $this->selectForUpdate($rutEmisor, $tipoDte);
            $next = max($desde, $last + 1);
            if ($next > $hasta) {
                return null;
            }
            $this->update($rutEmisor, $tipoDte, $next);

            return $next;
        });
    }

    public function getLast(string $rutEmisor, int $tipoDte): ?int
    {
        $stmt = $this->pdo->prepare("SELECT ultimo_folio FROM {$this->table} WHERE scope = ? AND rut_emisor = ? AND tipo_dte = ?");
        $stmt->execute([$this->scope, $rutEmisor, $tipoDte]);
        $value = $stmt->fetchColumn();

        return $value === false || (int) $value === 0 ? null : (int) $value;
    }

    public function release(string $rutEmisor, int $tipoDte, int $folio): bool
    {
        return $this->locked($rutEmisor, $tipoDte, function () use ($rutEmisor, $tipoDte, $folio): bool {
            if ($this->selectForUpdate($rutEmisor, $tipoDte) !== $folio) {
                return false;
            }
            $this->update($rutEmisor, $tipoDte, $folio - 1);

            return true;
        });
    }

    public function setLast(string $rutEmisor, int $tipoDte, int $folio): void
    {
        $this->locked($rutEmisor, $tipoDte, function () use ($rutEmisor, $tipoDte, $folio): null {
            $this->selectForUpdate($rutEmisor, $tipoDte);
            $this->update($rutEmisor, $tipoDte, $folio);

            return null;
        });
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function locked(string $rutEmisor, int $tipoDte, callable $callback): mixed
    {
        $useGetLock = $this->lockMode === 'get_lock' && PdoHelper::driver($this->pdo) === 'mysql';
        $lockName = substr('sii_folio:'.sha1($this->table.'|'.$this->scope.'|'.$rutEmisor.'|'.$tipoDte), 0, 64);
        if ($useGetLock) {
            $stmt = $this->pdo->prepare('SELECT GET_LOCK(?, 10)');
            $stmt->execute([$lockName]);
            if ((int) $stmt->fetchColumn() !== 1) {
                throw new InvalidArgumentException('No se pudo obtener el bloqueo de folios (GET_LOCK) en 10 segundos.');
            }
        }
        try {
            return PdoHelper::transactional($this->pdo, $callback);
        } finally {
            if ($useGetLock) {
                $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
            }
        }
    }

    private function selectForUpdate(string $rutEmisor, int $tipoDte): int
    {
        $this->pdo->prepare(PdoHelper::insertIgnore($this->pdo, $this->table, 'scope, rut_emisor, tipo_dte, ultimo_folio', '?, ?, ?, 0'))
            ->execute([$this->scope, $rutEmisor, $tipoDte]);
        $forUpdate = PdoHelper::driver($this->pdo) === 'sqlite' ? '' : ' FOR UPDATE';
        $stmt = $this->pdo->prepare("SELECT ultimo_folio FROM {$this->table} WHERE scope = ? AND rut_emisor = ? AND tipo_dte = ?{$forUpdate}");
        $stmt->execute([$this->scope, $rutEmisor, $tipoDte]);

        return (int) $stmt->fetchColumn();
    }

    private function update(string $rutEmisor, int $tipoDte, int $folio): void
    {
        $this->pdo->prepare("UPDATE {$this->table} SET ultimo_folio = ? WHERE scope = ? AND rut_emisor = ? AND tipo_dte = ?")
            ->execute([$folio, $this->scope, $rutEmisor, $tipoDte]);
    }
}
