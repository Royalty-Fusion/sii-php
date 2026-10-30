<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Tests\Intercambio;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Sii\Calculator\TotalsCalculator;
use RoyaltyFusion\Sii\Intercambio\DteXmlParser;
use RoyaltyFusion\Sii\Intercambio\EnvioDteReader;
use RoyaltyFusion\Sii\Model\Dte;
use RoyaltyFusion\Sii\Model\GlobalDiscount;
use RoyaltyFusion\Sii\Model\Reference;
use RoyaltyFusion\Sii\Model\Resolution;
use RoyaltyFusion\Sii\Tests\Documents;
use RoyaltyFusion\Sii\Tests\Xml\EmissionTest;
use RoyaltyFusion\Sii\Validator\DocumentValidator;

/** Un DTE emitido por el SDK se lee de vuelta con todo su contenido (ida y vuelta). */
final class DteXmlParserTest extends TestCase
{
    /** @return iterable<string, array{Dte}> */
    public static function documentos(): iterable
    {
        yield 'factura 33' => [Documents::factura()];
        yield 'factura otra moneda e impuestos' => [Documents::facturaOtraMonedaEImpuestos()];
        yield 'factura exenta 34' => [Documents::facturaExenta()];
        yield 'factura compra 46' => [Documents::facturaCompra()];
        yield 'guia 52' => [Documents::guia()];
        yield 'nota credito 61' => [Documents::notaCredito()];
        yield 'nota credito texto' => [Documents::notaCreditoTexto()];
        yield 'nota debito 56' => [Documents::notaDebito()];
        yield 'exportacion 110' => [Documents::exportacion()];
        yield 'nc exportacion 112' => [Documents::notaCreditoExportacion()];
    }

    #[DataProvider('documentos')]
    public function testIdaYVuelta(Dte $original): void
    {
        $prepared = EmissionTest::emitter()->prepare($original);
        $parsed = (new DteXmlParser())->parse($prepared->xml);
        $leido = $parsed->documento;
        self::assertNotNull($leido);

        self::assertSame($original->getTipo(), $leido->getTipo());
        self::assertSame($original->getFolio(), $leido->getFolio());
        self::assertSame($original->getFechaEmision()?->format('Y-m-d'), $leido->getFechaEmision()?->format('Y-m-d'));
        self::assertSame($original->getEmisor()?->getRut(), $leido->getEmisor()?->getRut());
        self::assertSame($original->getEmisor()?->getActecos(), $leido->getEmisor()?->getActecos());
        self::assertSame(\RoyaltyFusion\Sii\Util\Rut::normalize((string) $original->getReceptor()?->getRut()), $leido->getReceptor()?->getRut());
        self::assertSame($original->getReceptor()?->getRazonSocial(), $leido->getReceptor()?->getRazonSocial());

        // Detalle
        self::assertCount(\count($original->getDetalles()), $leido->getDetalles());
        foreach ($prepared->calculation->lineas as $k => $linea) {
            $d = $leido->getDetalles()[$k];
            self::assertSame($linea->detalle->getNombre(), $d->getNombre());
            self::assertEquals($linea->montoItem, $d->getMontoItem());
            self::assertSame($linea->detalle->getIndicadorExencion(), $d->getIndicadorExencion());
            self::assertSame(array_values(array_unique($linea->detalle->getImpuestosAdicionales())), $d->getImpuestosAdicionales());
        }

        // Referencias
        self::assertCount(\count($original->getReferencias()), $leido->getReferencias());
        foreach ($original->getReferencias() as $k => $ref) {
            $r = $leido->getReferencias()[$k];
            self::assertSame($ref->getTipoDocumento(), $r->getTipoDocumento());
            self::assertSame($ref->getFolio(), $r->getFolio());
            self::assertSame($ref->getFecha()?->format('Y-m-d'), $r->getFecha()?->format('Y-m-d'));
            self::assertSame($ref->getCodigo(), $r->getCodigo());
            self::assertSame($ref->getRazon(), $r->getRazon());
        }

        // Totales informados = calculados, y el documento leído se recalcula igual y es válido.
        self::assertEquals($prepared->calculation->totales->montoTotal, $parsed->totales->montoTotal);
        self::assertEquals($prepared->calculation->totales->iva, $parsed->totales->iva);
        self::assertEquals($prepared->calculation->totales->montoNeto, $parsed->totales->montoNeto);
        self::assertCount(\count($prepared->calculation->totales->impuestos), $parsed->totales->impuestos);
        $recalculo = (new TotalsCalculator())->calculate($leido);
        self::assertSame([], $recalculo->diferencias);
        $validacion = (new DocumentValidator())->validate($leido, now: new \DateTimeImmutable('2026-11-02 12:00', new \DateTimeZone('America/Santiago')));
        self::assertTrue($validacion->isValid(), (string) $validacion);
    }

    public function testCompraRecibidaConReferenciasAOrdenDeCompraYGuias(): void
    {
        $factura = Documents::factura(40)
            ->setReferencias([
                Reference::create('801', 'OC-4512', new \DateTimeImmutable('2026-10-28'), null, 'Orden de compra'),
                (new Reference())->setTipoDocumento('52')->setFolio('0')->setFecha(new \DateTimeImmutable('2026-10-31'))->setGlobal(true)->setRazon('Guías del mes'),
                Reference::create('HES', '9988', new \DateTimeImmutable('2026-10-30')),
            ])
            ->addDescuentoGlobal(GlobalDiscount::recargoMonto(1000, 'Flete'));
        $emitter = EmissionTest::emitter();
        $envio = $emitter->envelope([$emitter->prepare($factura)], new Resolution('2026-10-01', 0), null, '96790240-3');

        $dte = (new EnvioDteReader())->read($envio)->documentos[0];
        self::assertSame(3, \count($dte->getReferencias()));
        $oc = $dte->getReferenciasPorTipo('801');
        self::assertCount(1, $oc);
        self::assertSame('OC-4512', $oc[0]->getFolio());
        self::assertTrue($dte->getReferenciasPorTipo(52)[0]->isGlobal());
        self::assertCount(3, $dte->getDetalles());
        self::assertSame('NB-14', $dte->getDetalles()[0]->getCodigos()[0]->getValor());
        self::assertSame('Comercio al por mayor', $dte->getDocumento()?->getReceptor()?->getGiro());
        self::assertCount(2, $dte->getDocumento()?->getDescuentosGlobales() ?? []);
        self::assertSame($dte->montoTotal, $dte->contenido?->totales->montoTotal);
    }
}
