<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Pdo;

use RoyaltyFusion\Sii\Exception\InvalidArgumentException;

/** Utilidades comunes de las implementaciones PDO (MariaDB/MySQL, PostgreSQL y SQLite). */
final class PdoHelper
{
    public static function identifier(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Nombre de tabla/columna inválido: "%s".', $name));
        }

        return $name;
    }

    public static function driver(\PDO $pdo): string
    {
        return (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    /** INSERT que no falla si la fila ya existe. */
    public static function insertIgnore(\PDO $pdo, string $table, string $columns, string $values): string
    {
        return match (self::driver($pdo)) {
            'mysql' => "INSERT IGNORE INTO {$table} ({$columns}) VALUES ({$values})",
            'sqlite' => "INSERT OR IGNORE INTO {$table} ({$columns}) VALUES ({$values})",
            default => "INSERT INTO {$table} ({$columns}) VALUES ({$values}) ON CONFLICT DO NOTHING",
        };
    }

    /**
     * Ejecuta $callback dentro de una transacción. Si el PDO ya está en una transacción (del ERP),
     * se usa esa y no se hace commit.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public static function transactional(\PDO $pdo, callable $callback): mixed
    {
        if ($pdo->inTransaction()) {
            return $callback();
        }
        $sqlite = self::driver($pdo) === 'sqlite';
        // En SQLite se toma el bloqueo de escritura al inicio para evitar carreras entre procesos.
        $sqlite ? $pdo->exec('BEGIN IMMEDIATE') : $pdo->beginTransaction();
        try {
            $result = $callback();
            $sqlite ? $pdo->exec('COMMIT') : $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            try {
                $sqlite ? $pdo->exec('ROLLBACK') : $pdo->rollBack();
            } catch (\Throwable) {
            }

            throw $e;
        }
    }
}
