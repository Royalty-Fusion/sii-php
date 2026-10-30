<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Tests\Pdo;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Sii\Environment;
use RoyaltyFusion\Sii\Folio\FolioManager;
use RoyaltyFusion\Sii\Pdo\PdoCafRepository;
use RoyaltyFusion\Sii\Pdo\PdoFolioStore;
use RoyaltyFusion\Sii\Tests\Fixtures;

#[RequiresPhpExtension('pdo_sqlite')]
final class PdoStoresTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/sii-pdo-'.bin2hex(random_bytes(4)).'.sqlite';
        $this->pdo()->exec((string) file_get_contents(\dirname(__DIR__, 2).'/resources/sql/sqlite.sql'));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function pdo(): \PDO
    {
        return new \PDO('sqlite:'.$this->file, null, null, [\PDO::ATTR_TIMEOUT => 30]);
    }

    public function testFolioStore(): void
    {
        $a = new PdoFolioStore($this->pdo(), 'empresa1');
        $b = new PdoFolioStore($this->pdo(), 'empresa1');
        $otra = new PdoFolioStore($this->pdo(), 'empresa2');

        self::assertNull($a->getLast('76192083-9', 33));
        self::assertSame(1, $a->reserve('76192083-9', 33, 1, 100));
        self::assertSame(2, $b->reserve('76192083-9', 33, 1, 100));
        self::assertSame(1, $otra->reserve('76192083-9', 33, 1, 100), 'El scope separa empresas');
        self::assertTrue($a->release('76192083-9', 33, 2));
        self::assertFalse($a->release('76192083-9', 33, 7));
        self::assertSame(1, $b->getLast('76192083-9', 33));
        $a->setLast('76192083-9', 33, 100);
        self::assertNull($b->reserve('76192083-9', 33, 1, 100));
        self::assertSame(201, $b->reserve('76192083-9', 33, 201, 300));
    }

    public function testFolioStoreDentroDeTransaccionDelErp(): void
    {
        $pdo = $this->pdo();
        $store = new PdoFolioStore($pdo, 'e');
        $pdo->beginTransaction();
        self::assertSame(1, $store->reserve('76192083-9', 33, 1, 10));
        $pdo->rollBack(); // el ERP revierte su transacción: el folio vuelve a quedar libre
        self::assertSame(1, $store->reserve('76192083-9', 33, 1, 10));
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testSinFoliosDuplicadosEntreProcesos(): void
    {
        $pids = [];
        $out = sys_get_temp_dir().'/sii-pdo-out-'.bin2hex(random_bytes(4));
        for ($p = 0; $p < 4; ++$p) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $store = new PdoFolioStore($this->pdo(), 'concurrencia');
                $folios = [];
                for ($i = 0; $i < 25; ++$i) {
                    $folios[] = $store->reserve('76192083-9', 33, 1, 1000);
                }
                file_put_contents($out.'-'.$p, implode(',', $folios));
                // Termina el hijo sin ejecutar los manejadores de cierre de PHPUnit.
                posix_kill(posix_getpid(), \SIGKILL);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        $all = [];
        for ($p = 0; $p < 4; ++$p) {
            $all = array_merge($all, array_map('intval', explode(',', (string) file_get_contents($out.'-'.$p))));
            @unlink($out.'-'.$p);
        }
        sort($all);
        self::assertSame(range(1, 100), $all);
    }

    public function testCafRepositoryPorScope(): void
    {
        $repo = new PdoCafRepository($this->pdo(), 'tenant1:sucursal1');
        $repo->save(Fixtures::caf(33));
        $repo->save(Fixtures::caf(33)); // idempotente
        $repo->save(Fixtures::caf(39));

        $leido = (new PdoCafRepository($this->pdo(), 'tenant1:sucursal1'))->find('76.192.083-9', 33, 50);
        self::assertNotNull($leido);
        self::assertSame(100, $leido->getFolioHasta());
        self::assertNull($repo->find(Fixtures::RUT_EMISOR, 33, 101));
        self::assertCount(1, $repo->findAll(Fixtures::RUT_EMISOR, 33));
        self::assertNull((new PdoCafRepository($this->pdo(), 'tenant2'))->find(Fixtures::RUT_EMISOR, 33, 50), 'Otro scope no ve los CAF');

        // Integración con FolioManager
        $manager = new FolioManager($repo, new PdoFolioStore($this->pdo(), 'tenant1:sucursal1'), Environment::CERTIFICACION,
            static fn () => new \DateTimeImmutable('2026-11-02 10:00', new \DateTimeZone('America/Santiago')));
        self::assertSame(1, $manager->next(Fixtures::RUT_EMISOR, 33));
        self::assertSame(99, $manager->disponibles(Fixtures::RUT_EMISOR, 33));
    }

    public function testRechazaIdentificadoresInvalidos(): void
    {
        $this->expectException(\RoyaltyFusion\Sii\Exception\InvalidArgumentException::class);
        new PdoCafRepository($this->pdo(), 'x', 'sii_caf; DROP TABLE x');
    }
}
