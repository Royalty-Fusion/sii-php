<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Pdo;

use RoyaltyFusion\Sii\Caf\Caf;
use RoyaltyFusion\Sii\Caf\CafRepositoryInterface;
use RoyaltyFusion\Sii\Exception\InvalidArgumentException;
use RoyaltyFusion\Sii\Util\Rut;

/**
 * CAF guardados en la base de datos del ERP (requiere ext-pdo, sin dependencias extra).
 *
 * El RUT no basta para identificar los CAF en un ERP multiempresa: cada instancia filtra además por
 * $scope (por ejemplo, "tenant42:sucursal3"), guardado en la columna $scopeColumn.
 *
 * SEGURIDAD: el XML del CAF contiene la clave privada de timbraje (RSASK). Quien la obtenga puede
 * timbrar documentos a nombre del emisor. No la registre en logs, no la exponga por APIs y restrinja
 * el acceso a la tabla (idealmente cifrando la columna xml en reposo).
 */
final class PdoCafRepository implements CafRepositoryInterface
{
    private readonly string $table;
    private readonly string $scopeColumn;
    /** @var array<string, Caf> */
    private array $cache = [];

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $scope = '',
        string $table = 'sii_caf',
        string $scopeColumn = 'scope',
    ) {
        $this->table = PdoHelper::identifier($table);
        $this->scopeColumn = PdoHelper::identifier($scopeColumn);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /** Guarda un CAF (si ya existe el mismo rango, no hace nada). */
    public function save(Caf $caf): void
    {
        $columns = "{$this->scopeColumn}, rut_emisor, tipo_dte, folio_desde, folio_hasta, fecha_autorizacion, certificacion, xml";
        $stmt = $this->pdo->prepare(PdoHelper::insertIgnore($this->pdo, $this->table, $columns, '?, ?, ?, ?, ?, ?, ?, ?'));
        $stmt->bindValue(1, $this->scope);
        $stmt->bindValue(2, $caf->getRutEmisor());
        $stmt->bindValue(3, $caf->getTipoDte(), \PDO::PARAM_INT);
        $stmt->bindValue(4, $caf->getFolioDesde(), \PDO::PARAM_INT);
        $stmt->bindValue(5, $caf->getFolioHasta(), \PDO::PARAM_INT);
        $stmt->bindValue(6, $caf->getFechaAutorizacion()->format('Y-m-d'));
        $stmt->bindValue(7, $caf->isCertificacion() ? 1 : 0, \PDO::PARAM_INT);
        $stmt->bindValue(8, $caf->getSource(), \PDO::PARAM_LOB);
        $stmt->execute();
    }

    public function find(string $rutEmisor, int $tipoDte, int $folio): ?Caf
    {
        $stmt = $this->pdo->prepare("SELECT folio_desde, xml FROM {$this->table} WHERE {$this->scopeColumn} = ? AND rut_emisor = ? AND tipo_dte = ? AND folio_desde <= ? AND folio_hasta >= ? ORDER BY folio_desde LIMIT 1");
        $stmt->execute([$this->scope, self::rut($rutEmisor), $tipoDte, $folio, $folio]);
        $row = $stmt->fetch(\PDO::FETCH_NUM);

        return $row === false ? null : $this->hydrate($rutEmisor, $tipoDte, (int) $row[0], $row[1]);
    }

    public function findAll(string $rutEmisor, int $tipoDte): array
    {
        $stmt = $this->pdo->prepare("SELECT folio_desde, xml FROM {$this->table} WHERE {$this->scopeColumn} = ? AND rut_emisor = ? AND tipo_dte = ? ORDER BY folio_desde");
        $stmt->execute([$this->scope, self::rut($rutEmisor), $tipoDte]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as $row) {
            $out[] = $this->hydrate($rutEmisor, $tipoDte, (int) $row[0], $row[1]);
        }

        return $out;
    }

    private function hydrate(string $rut, int $tipo, int $desde, mixed $xml): Caf
    {
        $key = self::rut($rut).'|'.$tipo.'|'.$desde;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        if (\is_resource($xml)) {
            $xml = stream_get_contents($xml);
        }
        if (!\is_string($xml) || $xml === '') {
            throw new InvalidArgumentException(sprintf('El CAF %s no tiene XML en la base de datos.', $key));
        }

        return $this->cache[$key] = Caf::fromXml($xml);
    }

    private static function rut(string $rut): string
    {
        return Rut::isValid($rut) ? Rut::normalize($rut) : $rut;
    }
}
