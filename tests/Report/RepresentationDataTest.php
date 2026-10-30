<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Tests\Report;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Sii\Environment;
use RoyaltyFusion\Sii\Model\Resolution;
use RoyaltyFusion\Sii\See;
use RoyaltyFusion\Sii\Tests\Documents;
use RoyaltyFusion\Sii\Tests\Fixtures;
use RoyaltyFusion\Sii\Util\Text;
use RoyaltyFusion\Sii\Xml\Builder\TedBuilder;

final class RepresentationDataTest extends TestCase
{
    public function testDatosParaTcpdf(): void
    {
        $see = (new See(Fixtures::certificate(), Environment::CERTIFICACION, new Resolution('2026-10-01', 0), Fixtures::cafs(),
            clock: static fn () => new \DateTimeImmutable('2026-11-02 10:30:00', new \DateTimeZone('America/Santiago'))))
            ->setCompany(Fixtures::emisor())
            ->setOficinaSii('Santiago Oriente');
        $prepared = $see->prepare(Documents::factura());
        $data = $see->getRepresentationData($prepared);

        self::assertSame(33, $data->documento['tipo']);
        self::assertSame('Factura Electrónica', $data->documento['nombre']);
        self::assertSame(1, $data->documento['folio']);
        self::assertSame('76.192.083-9', $data->emisor['rut_formateado']);
        self::assertSame("Comercial O'Higgins & Cía. Ltda.", $data->receptor['razon_social'] ?? null);
        self::assertSame(1072231, $data->totales['monto_total']);
        self::assertSame(165608, $data->totales['iva']);
        self::assertCount(3, $data->detalle);
        self::assertSame(855000, $data->detalle[0]['monto']);
        self::assertSame('801', $data->referencias[0]['tipo']);
        self::assertSame('Res. 0 de 2026', $data->resolucion['texto'] ?? null);
        self::assertSame('SANTIAGO ORIENTE', $data->oficinaSii);
        self::assertSame(5, $data->pdf417['nivel_correccion']);
        self::assertSame('PDF417,3,5', $data->pdf417['tcpdf_type']);
        self::assertTrue($data->admiteCedible);
        self::assertStringStartsWith('<TED version="1.0">', $data->ted);
        self::assertSame(Text::toLatin1($data->ted), $data->tedLatin1);
        $caf = Fixtures::caf(33);
        self::assertTrue(TedBuilder::verify($data->tedLatin1, $caf), 'El TED entregado debe verificar con el CAF');
        self::assertNotFalse(json_encode($data->toArray()));
    }
}
