<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Tests\Folio;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Sii\Caf\Caf;
use RoyaltyFusion\Sii\Caf\InMemoryCafRepository;
use RoyaltyFusion\Sii\Environment;
use RoyaltyFusion\Sii\Exception\CafException;
use RoyaltyFusion\Sii\Exception\ValidationException;
use RoyaltyFusion\Sii\Folio\FileFolioStore;
use RoyaltyFusion\Sii\Folio\FolioManager;
use RoyaltyFusion\Sii\Folio\InMemoryFolioStore;
use RoyaltyFusion\Sii\Model\DocumentType;
use RoyaltyFusion\Sii\Model\Resolution;
use RoyaltyFusion\Sii\See;
use RoyaltyFusion\Sii\Tests\Documents;
use RoyaltyFusion\Sii\Tests\Fixtures;

final class FolioManagerTest extends TestCase
{
    private static function clock(string $date = '2026-11-02 10:00:00'): \Closure
    {
        return static fn () => new \DateTimeImmutable($date, new \DateTimeZone('America/Santiago'));
    }

    public function testAsignaFoliosCorrelativos(): void
    {
        $manager = new FolioManager(Fixtures::cafs(), new InMemoryFolioStore(), Environment::CERTIFICACION, self::clock());
        self::assertSame(1, $manager->next(Fixtures::RUT_EMISOR, DocumentType::FACTURA));
        self::assertSame(2, $manager->next('76.192.083-9', 33));
        self::assertSame(1, $manager->next(Fixtures::RUT_EMISOR, DocumentType::NOTA_CREDITO));
        self::assertTrue($manager->release(Fixtures::RUT_EMISOR, 33, 2));
        self::assertFalse($manager->release(Fixtures::RUT_EMISOR, 33, 7));
        self::assertSame(2, $manager->next(Fixtures::RUT_EMISOR, 33));
    }

    public function testConsultaEstadoYAgotamiento(): void
    {
        $manager = new FolioManager(Fixtures::cafs(), new InMemoryFolioStore(), Environment::CERTIFICACION, self::clock());
        $manager->setUltimoUsado(Fixtures::RUT_EMISOR, 33, 95);
        $status = $manager->status(Fixtures::RUT_EMISOR, 33)[0];
        self::assertSame(95, $status->ultimoUsado);
        self::assertSame(5, $status->disponibles);
        self::assertSame(96, $status->getSiguiente());
        self::assertSame('2027-04-01', $status->fechaVencimiento?->format('Y-m-d'));
        self::assertTrue($manager->requiereNuevoCaf(Fixtures::RUT_EMISOR, 33));
        self::assertFalse($manager->requiereNuevoCaf(Fixtures::RUT_EMISOR, 39));

        for ($f = 96; $f <= 100; ++$f) {
            self::assertSame($f, $manager->next(Fixtures::RUT_EMISOR, 33));
        }
        $this->expectException(CafException::class);
        $this->expectExceptionMessage('No quedan folios disponibles');
        $manager->next(Fixtures::RUT_EMISOR, 33);
    }

    public function testContinuaEnElSiguienteCaf(): void
    {
        $repo = new InMemoryCafRepository([Fixtures::caf(33), $this->cafConRango(33, 201, 300)]);
        $manager = new FolioManager($repo, new InMemoryFolioStore(), Environment::CERTIFICACION, self::clock());
        $manager->setUltimoUsado(Fixtures::RUT_EMISOR, 33, 100);
        self::assertSame(201, $manager->next(Fixtures::RUT_EMISOR, 33));
        self::assertSame(100, $manager->disponibles(Fixtures::RUT_EMISOR, 33) + 1);
    }

    public function testOmiteCafVencidosYDeOtroAmbiente(): void
    {
        $vencido = new FolioManager(Fixtures::cafs(), new InMemoryFolioStore(), Environment::CERTIFICACION, self::clock('2027-05-01'));
        self::assertSame(0, $vencido->disponibles(Fixtures::RUT_EMISOR, 33));
        self::assertSame(1, $vencido->next(Fixtures::RUT_EMISOR, 39)); // las boletas no vencen

        $produccion = new FolioManager(Fixtures::cafs(), new InMemoryFolioStore(), Environment::PRODUCCION, self::clock());
        $this->expectException(CafException::class);
        $produccion->next(Fixtures::RUT_EMISOR, 33);
    }

    public function testRegistroEnArchivoCompartido(): void
    {
        $file = sys_get_temp_dir().'/sii-folios-'.bin2hex(random_bytes(4)).'.json';
        try {
            $a = new FolioManager(Fixtures::cafs(), new FileFolioStore($file), null, self::clock());
            $b = new FolioManager(Fixtures::cafs(), new FileFolioStore($file), null, self::clock());
            self::assertSame(1, $a->next(Fixtures::RUT_EMISOR, 33));
            self::assertSame(2, $b->next(Fixtures::RUT_EMISOR, 33));
            self::assertSame(3, $a->next(Fixtures::RUT_EMISOR, 33));
            self::assertSame(3, $b->status(Fixtures::RUT_EMISOR, 33)[0]->ultimoUsado);
        } finally {
            @unlink($file);
        }
    }

    public function testSeeAsignaFolioAutomaticamente(): void
    {
        $see = (new See(Fixtures::certificate(), Environment::CERTIFICACION, new Resolution('2026-10-01', 0), Fixtures::cafs(), clock: self::clock()))
            ->setCompany(Fixtures::emisor())
            ->setFolioStore(new InMemoryFolioStore());
        $a = $see->prepare(Documents::factura()->setFolio(null));
        $b = $see->prepare(Documents::factura()->setFolio(null));
        self::assertSame(1, $a->document->getFolio());
        self::assertSame(2, $b->document->getFolio());

        // Si la emisión falla, el folio reservado se libera.
        $invalida = Documents::factura()->setFolio(null);
        $invalida->getReceptor()?->setRut('1-1');
        try {
            $see->prepare($invalida);
            self::fail('Debió fallar la validación');
        } catch (ValidationException) {
            self::assertNull($invalida->getFolio());
        }
        self::assertSame(3, $see->prepare(Documents::factura()->setFolio(null))->document->getFolio());
    }

    public function testCafEnLatin1SinDeclaracionDeEncoding(): void
    {
        $xml = (string) file_get_contents(Fixtures::DIR.'/caf/caf_33.xml');
        $xml = str_replace('EMPRESA DE PRUEBA SPA', mb_convert_encoding('PEÑA Y CÍA', 'ISO-8859-1', 'UTF-8'), $xml);
        $caf = Caf::fromXml($xml);
        self::assertSame('PEÑA Y CÍA', $caf->getRazonSocial());
        self::assertStringContainsString('<RS>PEÑA Y CÍA</RS>', $caf->getCafXml());
    }

    /** CAF de prueba con otro rango (misma clave que el CAF 33). */
    private function cafConRango(int $tipo, int $desde, int $hasta): Caf
    {
        $xml = (string) file_get_contents(Fixtures::DIR.'/caf/caf_'.$tipo.'.xml');

        return Caf::fromXml(str_replace(["<D>1</D>", "<H>100</H>"], ["<D>{$desde}</D>", "<H>{$hasta}</H>"], $xml));
    }

    public function testSinRegistroDeFoliosNoAsignaAutomaticamente(): void
    {
        $see = (new See(Fixtures::certificate(), Environment::CERTIFICACION, new Resolution('2026-10-01', 0), Fixtures::cafs(), clock: self::clock()))
            ->setCompany(Fixtures::emisor());
        $this->expectException(\RoyaltyFusion\Sii\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('setFolioStore');
        $see->prepare(Documents::factura()->setFolio(null));
    }
}
