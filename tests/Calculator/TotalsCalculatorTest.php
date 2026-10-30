<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Tests\Calculator;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Sii\Calculator\TotalsCalculator;
use RoyaltyFusion\Sii\Model\AdditionalTax;
use RoyaltyFusion\Sii\Model\Boleta;
use RoyaltyFusion\Sii\Model\Detail;
use RoyaltyFusion\Sii\Model\Dte;
use RoyaltyFusion\Sii\Model\GlobalDiscount;
use RoyaltyFusion\Sii\Tests\Documents;

final class TotalsCalculatorTest extends TestCase
{
    private TotalsCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new TotalsCalculator();
    }

    public function testFacturaConDescuentosYExentos(): void
    {
        $c = $this->calculator->calculate(Documents::factura());
        // Línea 1: 2 × 450.000 = 900.000 − 5% (45.000) = 855.000
        self::assertSame(45000, $c->lineas[0]->descuentoMonto);
        self::assertSame(855000, $c->lineas[0]->montoItem);
        // Línea 2: 3 × 12.990,5 = 38.971,5 → 38.972
        self::assertSame(38972, $c->lineas[1]->montoItem);
        // Afectos 893.972 − 2,5% global (22.349) = 871.623
        self::assertSame(-22349, $c->descuentosGlobales[0]->monto);
        self::assertSame(871623, $c->totales->montoNeto);
        self::assertSame(35000, $c->totales->montoExento);
        self::assertSame(165608, $c->totales->iva); // 871.623 × 19% = 165.608,37
        self::assertSame(1072231, $c->totales->montoTotal);
    }

    public function testMontoBruto(): void
    {
        $dte = Dte::factura()->setMontoBruto(true)->addDetalle(Detail::create('Item', 1, 11900));
        $t = $this->calculator->calculate($dte)->totales;
        self::assertSame(10000, $t->montoNeto);
        self::assertSame(1900, $t->iva);
        self::assertSame(11900, $t->montoTotal);
    }

    public function testImpuestosAdicionales(): void
    {
        $dte = Dte::factura()
            ->addDetalle(Detail::create('Vino', 10, 3000)->addImpuestoAdicional(25))
            ->addDetalle(Detail::create('Bebida', 10, 1000)->addImpuestoAdicional('27'));
        $t = $this->calculator->calculate($dte)->totales;
        self::assertSame(40000, $t->montoNeto);
        self::assertSame(7600, $t->iva);
        self::assertCount(2, $t->impuestos);
        self::assertSame(6150, $t->impuestos[0]->monto); // 30.000 × 20,5%
        self::assertSame(1000, $t->impuestos[1]->monto); // 10.000 × 10%
        self::assertSame(54750, $t->montoTotal);
    }

    public function testFacturaCompraConRetencionTotal(): void
    {
        $t = $this->calculator->calculate(Documents::facturaCompra())->totales;
        self::assertSame(150000, $t->montoNeto);
        self::assertSame(28500, $t->iva);
        self::assertTrue($t->impuestos[0]->retencion);
        self::assertSame(28500, $t->impuestos[0]->monto);
        self::assertSame(150000, $t->montoTotal);
    }

    public function testFacturaExenta(): void
    {
        $t = $this->calculator->calculate(Documents::facturaExenta())->totales;
        self::assertNull($t->montoNeto);
        self::assertNull($t->iva);
        self::assertSame(245000, $t->montoExento);
        self::assertSame(245000, $t->montoTotal);
    }

    public function testBoletaDesglosaIva(): void
    {
        $c = $this->calculator->calculate(Documents::boleta());
        // Afectos: 3.980 + (2.500 − 250) = 6.230 → neto 5.235, IVA 995
        self::assertSame(5235, $c->totales->montoNeto);
        self::assertSame(995, $c->totales->iva);
        self::assertSame(300, $c->totales->montoExento);
        self::assertSame(6530, $c->totales->montoTotal);
    }

    public function testBoletaMontosNetos(): void
    {
        $boleta = Boleta::afecta()->setMontosNetos(true)->addDetalle(Detail::create('Servicio', 1, 10000));
        $t = $this->calculator->calculate($boleta)->totales;
        self::assertSame(10000, $t->montoNeto);
        self::assertSame(1900, $t->iva);
        self::assertSame(11900, $t->montoTotal);
    }

    public function testExportacionDecimales(): void
    {
        $c = $this->calculator->calculate(Documents::exportacion());
        self::assertSame(2, $c->decimales);
        self::assertSame(11500.5, $c->lineas[0]->montoItem);
        self::assertSame(12500.5, $c->totales->montoTotal);
        self::assertNotNull($c->otraMoneda);
        self::assertSame(11878600, $c->otraMoneda->montoTotal); // 12.500,50 × 950,25 = 11.878.600,125 → pesos enteros
    }

    public function testDetectaDiferenciaConErp(): void
    {
        $dte = Dte::factura()->addDetalle(Detail::create('Item', 1, 1000)->setMontoItem(999))->setMontoTotalEsperado(1190);
        $c = $this->calculator->calculate($dte);
        self::assertCount(1, $c->diferencias);
        self::assertStringContainsString('MontoItem informado (999)', $c->diferencias[0]);
    }

    public function testDescuentoGlobalSobreExentos(): void
    {
        $dte = Dte::factura()
            ->addDetalle(Detail::create('Afecto', 1, 10000))
            ->addDetalle(Detail::create('Exento', 1, 10000)->setExento())
            ->addDescuentoGlobal(GlobalDiscount::descuentoPorcentaje(10, null, GlobalDiscount::EXENTOS));
        $t = $this->calculator->calculate($dte)->totales;
        self::assertSame(10000, $t->montoNeto);
        self::assertSame(9000, $t->montoExento);
        self::assertSame(20900, $t->montoTotal);
    }

    public function testImpuestoAdicionalConTasaPersonalizada(): void
    {
        $dte = Dte::factura()
            ->addImpuestoAdicional(new AdditionalTax(28, 5.5))
            ->addDetalle(Detail::create('Diesel', 100, 1000)->addImpuestoAdicional(28));
        $t = $this->calculator->calculate($dte)->totales;
        self::assertSame(5500, $t->impuestos[0]->monto);
        self::assertFalse($t->impuestos[0]->retencion);
    }

    public function testOtraMonedaEnDocumentoNacional(): void
    {
        $dte = Dte::factura()
            ->setOtraMoneda(new \RoyaltyFusion\Sii\Model\OtherCurrency('DOLAR USA', 950))
            ->addDetalle(Detail::create('Servicio', 1, 95000));
        $o = $this->calculator->calculate($dte)->otraMoneda;
        self::assertNotNull($o);
        self::assertSame(100.0, $o->montoNeto);   // 95.000 / 950
        self::assertSame(19.0, $o->iva);          // 18.050 / 950
        self::assertSame(119.0, $o->montoTotal);  // 113.050 / 950
    }
}
