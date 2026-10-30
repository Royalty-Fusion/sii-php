<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Tests;

use RoyaltyFusion\Sii\Model\AdditionalTax;
use RoyaltyFusion\Sii\Model\Boleta;
use RoyaltyFusion\Sii\Model\Client;
use RoyaltyFusion\Sii\Model\Customs;
use RoyaltyFusion\Sii\Model\Detail;
use RoyaltyFusion\Sii\Model\DocumentType;
use RoyaltyFusion\Sii\Model\Driver;
use RoyaltyFusion\Sii\Model\Dte;
use RoyaltyFusion\Sii\Model\Foreigner;
use RoyaltyFusion\Sii\Model\GlobalDiscount;
use RoyaltyFusion\Sii\Model\OtherCurrency;
use RoyaltyFusion\Sii\Model\Package;
use RoyaltyFusion\Sii\Model\Payment;
use RoyaltyFusion\Sii\Model\Reference;
use RoyaltyFusion\Sii\Model\Transport;

/** Documentos de ejemplo para cada tipo soportado. */
final class Documents
{
    public static function factura(int $folio = 1): Dte
    {
        return Dte::factura()
            ->setFolio($folio)
            ->setFechaEmision(Fixtures::fecha())
            ->setEmisor(Fixtures::emisor())
            ->setReceptor(Fixtures::receptor())
            ->setFormaPago(2)
            ->setFechaVencimiento(Fixtures::fecha()->modify('+30 days'))
            ->addPago(new Payment(Fixtures::fecha()->modify('+30 days'), 20000, 'Cuota 1'))
            ->setMedioPago('PE')
            ->addDetalle(Detail::create('Notebook 14" Ñandú', 2, 450000, 'UN')->addCodigo('INT1', 'NB-14')->setDescuentoPorcentaje(5)->setDescripcion('Con garantía de 12 meses'))
            ->addDetalle(Detail::create('Mouse inalámbrico', 3, 12990.5, 'UN'))
            ->addDetalle(Detail::create('Servicio de instalación (exento)', 1, 35000)->setExento())
            ->addDescuentoGlobal(GlobalDiscount::descuentoPorcentaje(2.5, 'Descuento por volumen'))
            ->addReferencia(Reference::create('801', 'OC-4512', Fixtures::fecha()->modify('-3 days'), null, 'Orden de compra'));
    }

    public static function facturaOtraMonedaEImpuestos(int $folio = 3): Dte
    {
        return Dte::factura()
            ->setFolio($folio)
            ->setFechaEmision(Fixtures::fecha())
            ->setEmisor(Fixtures::emisor())
            ->setReceptor(Fixtures::receptor())
            ->setOtraMoneda(new OtherCurrency('DOLAR USA', 950.5))
            ->addDetalle(Detail::create('Vino reserva', 12, 4500, 'BOT')->addImpuestoAdicional(25))
            ->addDetalle(Detail::create('Bebida', 24, 800, 'UN')->addImpuestoAdicional(27)->setRecargoMonto(500))
            ->addDetalle(Detail::create('Garantía envases', 12, 100)->setIndicadorExencion(Detail::GARANTIA_ENVASES))
            ->addDescuentoGlobal(GlobalDiscount::recargoMonto(1500, 'Flete'));
    }

    public static function facturaExenta(int $folio = 1): Dte
    {
        return Dte::facturaExenta()
            ->setFolio($folio)
            ->setFechaEmision(Fixtures::fecha())
            ->setEmisor(Fixtures::emisor())
            ->setReceptor(Fixtures::receptor())
            ->addDetalle(Detail::create('Asesoría educacional', 10, 25000, 'HR'))
            ->addDescuentoGlobal(GlobalDiscount::descuentoMonto(5000));
    }

    public static function facturaCompra(int $folio = 1): Dte
    {
        return Dte::facturaCompra()
            ->setFolio($folio)
            ->setFechaEmision(Fixtures::fecha())
            ->setEmisor(Fixtures::emisor())
            ->setReceptor((new Client())->setRut('11111111-1')->setRazonSocial('Juan Pérez')->setGiro('Agricultor')->setDireccion('Fundo El Roble s/n')->setComuna('Molina'))
            ->addImpuestoAdicional(new AdditionalTax(15, 19))
            ->addDetalle(Detail::create('Frambuesas', 100, 1500, 'KG')->addImpuestoAdicional(15));
    }

    public static function guia(int $folio = 1): Dte
    {
        return Dte::guiaDespacho(5)
            ->setFolio($folio)
            ->setFechaEmision(Fixtures::fecha())
            ->setTipoDespacho(2)
            ->setEmisor(Fixtures::emisor())
            ->setReceptor(Fixtures::receptor())
            ->setTransporte((new Transport())
                ->setPatente('ABCD12')
                ->setRutTransportista('96790240-3')
                ->setChofer(new Driver('11111111-1', 'Pedro Soto'))
                ->setDireccionDestino('Bodega 5, Av. Industrial 900')
                ->setComunaDestino('Quilicura')
                ->setCiudadDestino('Santiago'))
            ->addDetalle(Detail::create('Pallet de cajas', 4, 0, 'UN'));
    }

    public static function notaCredito(int $folio = 1): Dte
    {
        return Dte::notaCredito()
            ->setFolio($folio)
            ->setFechaEmision(Fixtures::fecha())
            ->setEmisor(Fixtures::emisor())
            ->setReceptor(Fixtures::receptor())
            ->addDetalle(Detail::create('Devolución mouse inalámbrico', 1, 12990, 'UN'))
            ->addReferencia(Reference::create(DocumentType::FACTURA, 1, Fixtures::fecha()->modify('-1 day'), Reference::CORRIGE_MONTOS, 'Devolución de mercaderías'));
    }

    public static function notaCreditoTexto(int $folio = 2): Dte
    {
        return Dte::notaCredito()
            ->setFolio($folio)
            ->setFechaEmision(Fixtures::fecha())
            ->setEmisor(Fixtures::emisor())
            ->setReceptor(Fixtures::receptor())
            ->addDetalle((new Detail())->setNombre('Donde dice "Comuna: Santiago" debe decir "Comuna: Ñuñoa"'))
            ->addReferencia(Reference::create(DocumentType::FACTURA, 1, Fixtures::fecha()->modify('-1 day'), Reference::CORRIGE_TEXTO, 'Corrige dirección del receptor'));
    }

    public static function notaDebito(int $folio = 1): Dte
    {
        return Dte::notaDebito()
            ->setFolio($folio)
            ->setFechaEmision(Fixtures::fecha())
            ->setEmisor(Fixtures::emisor())
            ->setReceptor(Fixtures::receptor())
            ->addDetalle(Detail::create('Intereses por mora', 1, 8500))
            ->addReferencia(Reference::create(DocumentType::FACTURA, 1, Fixtures::fecha()->modify('-1 day'), Reference::CORRIGE_MONTOS, 'Cobro de intereses'));
    }

    public static function exportacion(int $folio = 1): Dte
    {
        $aduana = (new Customs())
            ->setCodigoModalidadVenta(1)
            ->setCodigoClausulaVenta(5)
            ->setTotalClausulaVenta(12500.50)
            ->setCodigoViaTransporte(1)
            ->setCodigoPuertoEmbarque(906)
            ->setCodigoPuertoDesembarque(262)
            ->setCodigoUnidadTara(10)
            ->setTara(1)
            ->setCodigoUnidadPesoBruto(6)
            ->setPesoBruto(1500.5)
            ->setCodigoUnidadPesoNeto(6)
            ->setPesoNeto(1400)
            ->setTotalBultos(20)
            ->addBulto(new Package(13, 20, 'RF-EXPORT', 'MSKU1234567', 'S123'))
            ->setMontoFlete(800)
            ->setMontoSeguro(120.25)
            ->setCodigoPaisReceptor(225)
            ->setCodigoPaisDestino(225);

        return Dte::facturaExportacion('DOLAR USA')
            ->setFolio($folio)
            ->setFechaEmision(Fixtures::fecha())
            ->setFormaPagoExportacion(21)
            ->setEmisor(Fixtures::emisor())
            ->setReceptor((new Client())
                ->setRut('55555555-5')
                ->setRazonSocial('Fruit Importers LLC')
                ->setGiro('Importer')
                ->setDireccion('100 Main Street, Miami FL')
                ->setComuna('Miami')
                ->setExtranjero(new Foreigner('US-998877', '225')))
            ->setTransporte((new Transport())->setAduana($aduana))
            ->setOtraMoneda(new OtherCurrency('PESO CL', 950.25))
            ->addDetalle(Detail::create('Cerezas frescas', 1000, 11.5005, 'KG'))
            ->addDetalle(Detail::create('Embalaje', 20, 50, 'UN'));
    }

    public static function notaCreditoExportacion(int $folio = 1): Dte
    {
        return Dte::notaCreditoExportacion('DOLAR USA')
            ->setFolio($folio)
            ->setFechaEmision(Fixtures::fecha())
            ->setEmisor(Fixtures::emisor())
            ->setReceptor((new Client())->setRut('55555555-5')->setRazonSocial('Fruit Importers LLC')->setExtranjero(new Foreigner(null, '225')))
            ->addDetalle(Detail::create('Descuento por fruta dañada', 1, 500))
            ->addReferencia(Reference::create(DocumentType::FACTURA_EXPORTACION, 1, Fixtures::fecha(), Reference::CORRIGE_MONTOS, 'Fruta dañada'));
    }

    public static function notaDebitoExportacion(int $folio = 1): Dte
    {
        return Dte::notaDebitoExportacion('EURO')
            ->setFolio($folio)
            ->setFechaEmision(Fixtures::fecha())
            ->setEmisor(Fixtures::emisor())
            ->setReceptor((new Client())->setRut('55555555-5')->setRazonSocial('Euro Foods GmbH')->setExtranjero(new Foreigner(null, '204')))
            ->addDetalle(Detail::create('Flete adicional', 1, 350.75))
            ->addReferencia(Reference::create(DocumentType::FACTURA_EXPORTACION, 1, Fixtures::fecha(), Reference::CORRIGE_MONTOS, 'Flete adicional'));
    }

    public static function boleta(int $folio = 1): Boleta
    {
        return Boleta::afecta()
            ->setFolio($folio)
            ->setFechaEmision(Fixtures::fecha())
            ->setEmisor(Fixtures::emisor())
            ->addDetalle(Detail::create('Café americano', 2, 1990))
            ->addDetalle(Detail::create('Croissant', 1, 2500)->setDescuentoPorcentaje(10))
            ->addDetalle(Detail::create('Bolsa reutilizable (exenta)', 1, 300)->setExento());
    }

    public static function boletaExenta(int $folio = 1): Boleta
    {
        return Boleta::exenta()
            ->setFolio($folio)
            ->setFechaEmision(Fixtures::fecha())
            ->setEmisor(Fixtures::emisor())
            ->setReceptor((new Client())->setRut('11111111-1')->setRazonSocial('Ana López')->setEmail('ana@correo.cl'))
            ->addDetalle(Detail::create('Matrícula curso', 1, 150000));
    }
}
