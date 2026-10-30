<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Tests\Validator;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Sii\Environment;
use RoyaltyFusion\Sii\Model\Boleta;
use RoyaltyFusion\Sii\Model\Detail;
use RoyaltyFusion\Sii\Model\Dte;
use RoyaltyFusion\Sii\Model\GlobalDiscount;
use RoyaltyFusion\Sii\Model\Reference;
use RoyaltyFusion\Sii\Tests\Documents;
use RoyaltyFusion\Sii\Tests\Fixtures;
use RoyaltyFusion\Sii\Validator\DocumentValidator;
use RoyaltyFusion\Sii\Validator\ValidationResult;

final class DocumentValidatorTest extends TestCase
{
    private DocumentValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new DocumentValidator();
    }

    public function testDocumentosDeEjemploSonValidos(): void
    {
        foreach ([Documents::factura(), Documents::facturaExenta(), Documents::guia(), Documents::notaCredito(), Documents::exportacion(), Documents::boleta(), Documents::boletaExenta()] as $doc) {
            $result = $this->validator->validate($doc, now: Fixtures::fecha());
            self::assertTrue($result->isValid(), $doc->getId().': '.$result);
        }
    }

    public function testCamposObligatorios(): void
    {
        $result = $this->validator->validate(Dte::factura());
        self::assertHas($result, 'Encabezado.IdDoc.Folio', 'REQUERIDO');
        self::assertHas($result, 'Encabezado.IdDoc.FchEmis', 'REQUERIDO');
        self::assertHas($result, 'Encabezado.Emisor', 'REQUERIDO');
        self::assertHas($result, 'Encabezado.Receptor', 'REQUERIDO');
        self::assertHas($result, 'Detalle', 'REQUERIDO');
    }

    public function testRutInvalidoYLargos(): void
    {
        $doc = Documents::factura();
        $doc->getReceptor()?->setRut('12345678-0')->setGiro(str_repeat('x', 41));
        $doc->getEmisor()?->setActecos([]);
        $result = $this->validator->validate($doc, now: Fixtures::fecha());
        self::assertHas($result, 'Encabezado.Receptor.RUTRecep', 'RUT_INVALIDO');
        self::assertHas($result, 'Encabezado.Receptor.GiroRecep', 'LARGO_MAXIMO');
        self::assertHas($result, 'Encabezado.Emisor.Acteco', 'REQUERIDO');
    }

    public function testReceptorFacturaRequiereGiroYDireccion(): void
    {
        $doc = Documents::factura();
        $doc->getReceptor()?->setGiro(null)->setDireccion(null)->setComuna(null);
        $result = $this->validator->validate($doc, now: Fixtures::fecha());
        self::assertHas($result, 'Encabezado.Receptor.GiroRecep', 'REQUERIDO');
        self::assertHas($result, 'Encabezado.Receptor.DirRecep', 'REQUERIDO');
        self::assertHas($result, 'Encabezado.Receptor.CmnaRecep', 'REQUERIDO');
    }

    public function testNotaCreditoSinReferencia(): void
    {
        $doc = Documents::notaCredito()->setReferencias([]);
        self::assertHas($this->validator->validate($doc, now: Fixtures::fecha()), 'Referencia', 'NOTA_SIN_REFERENCIA');
    }

    public function testGuiaRequiereIndicadorTraslado(): void
    {
        $doc = Documents::guia()->setIndicadorTraslado(null);
        self::assertHas($this->validator->validate($doc, now: Fixtures::fecha()), 'Encabezado.IdDoc.IndTraslado', 'REQUERIDO');
        $factura = Documents::factura()->setIndicadorTraslado(1);
        self::assertHas($this->validator->validate($factura, now: Fixtures::fecha()), 'Encabezado.IdDoc.IndTraslado', 'NO_PERMITIDO');
    }

    public function testDetalleInvalido(): void
    {
        $doc = Documents::factura()->setDetalles([
            Detail::create('', 1, 100),
            Detail::create('Item con precio negativo', 1, -5),
            Detail::create('Descuento excesivo', 1, 100)->setDescuentoMonto(150),
            Detail::create('Impuesto sin tasa', 1, 100)->addImpuestoAdicional(28),
            Detail::create('Impuesto inexistente', 1, 100)->addImpuestoAdicional(999),
            Detail::create('Indicador de guía', 1, 100)->setIndicadorExencion(Detail::NO_VENTA),
        ]);
        $result = $this->validator->validate($doc, now: Fixtures::fecha());
        self::assertHas($result, 'Detalle[1].NmbItem', 'REQUERIDO');
        self::assertHas($result, 'Detalle[2].PrcItem', 'RANGO');
        self::assertHas($result, 'Detalle[3].MontoItem', 'MONTO_NEGATIVO');
        self::assertHas($result, 'Detalle[4].CodImpAdic', 'IMPUESTO_SIN_TASA');
        self::assertHas($result, 'Detalle[5].CodImpAdic', 'VALOR_NO_PERMITIDO');
        self::assertHas($result, 'Detalle[6].IndExe', 'NO_PERMITIDO');
    }

    public function testMaximoLineas(): void
    {
        $doc = Documents::factura()->setDetalles(array_fill(0, 61, Detail::create('Item', 1, 100)));
        self::assertHas($this->validator->validate($doc, now: Fixtures::fecha()), 'Detalle', 'MAXIMO_ELEMENTOS');
        $boleta = Documents::boleta()->setDetalles(array_fill(0, 61, Detail::create('Item', 1, 100)));
        self::assertTrue($this->validator->validate($boleta, now: Fixtures::fecha())->isValid());
    }

    public function testDescuentoGlobalSinBase(): void
    {
        $doc = Documents::facturaExenta()->addDescuentoGlobal(GlobalDiscount::descuentoMonto(100, null, GlobalDiscount::NO_FACTURABLES));
        self::assertHas($this->validator->validate($doc, now: Fixtures::fecha()), 'DscRcgGlobal[2].IndExeDR', 'DESCUENTO_SIN_BASE');
    }

    public function testTotalEsperadoDistinto(): void
    {
        $doc = Documents::factura()->setMontoTotalEsperado(1000000);
        self::assertHas($this->validator->validate($doc, now: Fixtures::fecha()), 'Totales', 'TOTALES_DIFERENCIA');
        $ok = Documents::factura()->setMontoTotalEsperado(1072231);
        self::assertTrue($this->validator->validate($ok, now: Fixtures::fecha())->isValid());
    }

    public function testCaf(): void
    {
        $doc = Documents::factura(150);
        $result = $this->validator->validateCaf($doc, Fixtures::caf(33), Environment::PRODUCCION, Fixtures::fecha());
        self::assertHas($result, 'CAF.RNG', 'CAF_RANGO');
        self::assertHas($result, 'CAF.IDK', 'CAF_AMBIENTE');

        $result = $this->validator->validateCaf(Documents::factura(), Fixtures::caf(34), Environment::CERTIFICACION, Fixtures::fecha());
        self::assertHas($result, 'CAF.TD', 'CAF_TIPO');

        $antes = Documents::factura()->setFechaEmision(new \DateTimeImmutable('2026-09-30'));
        self::assertHas($this->validator->validateCaf($antes, Fixtures::caf(33), null, Fixtures::fecha()), 'CAF.FA', 'CAF_FECHA');

        self::assertHas($this->validator->validateCaf(Documents::factura(), Fixtures::caf(33), null, new \DateTimeImmutable('2027-05-01')), 'CAF.FA', 'CAF_VENCIDO');
        self::assertHas($this->validator->validateCaf(Documents::factura(), null), 'CAF', 'CAF_NO_ENCONTRADO');
    }

    public function testExportacion(): void
    {
        $doc = Documents::exportacion()->setTipoMoneda('PESO ARGENTINO')->setTransporte(null);
        $result = $this->validator->validate($doc, now: Fixtures::fecha());
        self::assertHas($result, 'Encabezado.Totales.TpoMoneda', 'VALOR_NO_PERMITIDO');
        self::assertHas($result, 'Encabezado.Transporte.Aduana', 'REQUERIDO');
    }

    public function testBoletaReglas(): void
    {
        $boleta = Boleta::afecta()->setFolio(1)->setFechaEmision(Fixtures::fecha())->setEmisor(Fixtures::emisor())
            ->setIndicadorServicio(9)
            ->addDetalle(Detail::create('Item', 1, 1000)->setDescuentoPorcentaje(150)->addImpuestoAdicional(27))
            ->addReferencia((new Reference())->setCodigoBoleta(str_repeat('X', 19)));
        $result = $this->validator->validate($boleta, now: Fixtures::fecha());
        self::assertHas($result, 'Encabezado.IdDoc.IndServicio', 'VALOR_NO_PERMITIDO');
        self::assertHas($result, 'Detalle[1].DescuentoPct', 'RANGO');
        self::assertHas($result, 'Detalle[1].CodImpAdic', 'NO_PERMITIDO');
        self::assertHas($result, 'Referencia[1].CodRef', 'LARGO_MAXIMO');
    }

    public function testFechaFuturaEsAdvertencia(): void
    {
        $doc = Documents::factura()->setFechaEmision(Fixtures::fecha()->modify('+1 day'));
        $result = $this->validator->validate($doc, now: Fixtures::fecha());
        self::assertTrue($result->isValid());
        self::assertSame('FECHA_FUTURA', $result->getWarnings()[0]->code);
    }

    private static function assertHas(ValidationResult $result, string $path, string $code): void
    {
        foreach ($result as $violation) {
            if ($violation->path === $path && $violation->code === $code) {
                self::assertTrue(true);

                return;
            }
        }
        self::fail(sprintf("No se encontró [%s] %s en:\n%s", $code, $path, $result));
    }
}
