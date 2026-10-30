<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Tests\Xml;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Sii\Emitter;
use RoyaltyFusion\Sii\Environment;
use RoyaltyFusion\Sii\Exception\ValidationException;
use RoyaltyFusion\Sii\Model\AbstractDocument;
use RoyaltyFusion\Sii\Model\Resolution;
use RoyaltyFusion\Sii\Tests\Documents;
use RoyaltyFusion\Sii\Tests\Fixtures;
use RoyaltyFusion\Sii\Util\Text;
use RoyaltyFusion\Sii\Validator\XsdValidator;
use RoyaltyFusion\Sii\Xml\Builder\TedBuilder;
use RoyaltyFusion\Sii\Xmldsig\XmlSignatureVerifier;

/**
 * Emite cada tipo de documento y verifica: XSD oficial del SII, firma XMLDSig y timbre (TED).
 */
final class EmissionTest extends TestCase
{
    public static function emitter(): Emitter
    {
        return new Emitter(
            Fixtures::certificate(),
            Fixtures::cafs(),
            Environment::CERTIFICACION,
            clock: static fn () => new \DateTimeImmutable('2026-11-02 10:30:00', new \DateTimeZone('America/Santiago')),
        );
    }

    /** @return iterable<string, array{AbstractDocument}> */
    public static function documentos(): iterable
    {
        yield 'factura 33' => [Documents::factura()];
        yield 'factura otra moneda e impuestos 33' => [Documents::facturaOtraMonedaEImpuestos()];
        yield 'factura exenta 34' => [Documents::facturaExenta()];
        yield 'factura compra 46' => [Documents::facturaCompra()];
        yield 'guia 52' => [Documents::guia()];
        yield 'nota debito 56' => [Documents::notaDebito()];
        yield 'nota credito 61' => [Documents::notaCredito()];
        yield 'nota credito texto 61' => [Documents::notaCreditoTexto()];
        yield 'exportacion 110' => [Documents::exportacion()];
        yield 'nd exportacion 111' => [Documents::notaDebitoExportacion()];
        yield 'nc exportacion 112' => [Documents::notaCreditoExportacion()];
        yield 'boleta 39' => [Documents::boleta()];
        yield 'boleta exenta 41' => [Documents::boletaExenta()];
    }

    #[DataProvider('documentos')]
    public function testEmiteDocumentoValido(AbstractDocument $document): void
    {
        $emitter = self::emitter();
        $prepared = $emitter->prepare($document);

        self::assertStringStartsWith('<?xml version="1.0" encoding="ISO-8859-1"?>', $prepared->xml);
        self::assertTrue((new XmlSignatureVerifier())->isValid($prepared->xml), 'Firma del DTE inválida');

        $ted = $prepared->getTed();
        self::assertNotNull($ted);
        $caf = $emitter->findCafFor($document);
        self::assertNotNull($caf);
        self::assertTrue(TedBuilder::verify($ted, $caf), 'Timbre inválido');
        self::assertStringContainsString('<TmstFirma>2026-11-02T10:30:00</TmstFirma>', $prepared->getXmlUtf8());

        $envelope = $emitter->envelope([$prepared], new Resolution('2026-10-01', 0));
        self::assertTrue((new XsdValidator())->validate($envelope)->isValid());
        $checks = (new XmlSignatureVerifier())->verifyAll($envelope);
        self::assertCount(2, $checks);
        foreach ($checks as $check) {
            self::assertTrue($check->isValid(), (string) $check->error);
        }
    }

    public function testSobreConVariosDocumentos(): void
    {
        $emitter = self::emitter();
        $docs = [
            $emitter->prepare(Documents::factura(10)),
            $emitter->prepare(Documents::factura(11)),
            $emitter->prepare(Documents::notaCredito(3)),
            $emitter->prepare(Documents::guia(7)),
        ];
        $xml = Text::toUtf8($emitter->envelope($docs, new Resolution('2026-10-01', 0)));
        self::assertStringContainsString('<SubTotDTE><TpoDTE>33</TpoDTE><NroDTE>2</NroDTE></SubTotDTE><SubTotDTE><TpoDTE>52</TpoDTE><NroDTE>1</NroDTE></SubTotDTE><SubTotDTE><TpoDTE>61</TpoDTE><NroDTE>1</NroDTE></SubTotDTE>', $xml);
        self::assertStringContainsString('<RutEnvia>11111111-1</RutEnvia><RutReceptor>60803000-K</RutReceptor>', $xml);
        self::assertSame(5, substr_count($xml, '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">'));
        self::assertTrue((new XmlSignatureVerifier())->isValid(Text::toLatin1($xml)));
    }

    public function testNoMezclaBoletasConDte(): void
    {
        $emitter = self::emitter();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No se pueden mezclar boletas');
        $emitter->envelope([$emitter->prepare(Documents::factura()), $emitter->prepare(Documents::boleta())], new Resolution('2026-10-01', 0));
    }

    public function testRechazaFolioDuplicadoEnSobre(): void
    {
        $emitter = self::emitter();
        $p = $emitter->prepare(Documents::factura(20));
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('duplicado');
        $emitter->envelope([$p, $p], new Resolution('2026-10-01', 0));
    }

    public function testFacturaContenidoEsperado(): void
    {
        $xml = self::emitter()->prepare(Documents::factura())->getXmlUtf8();
        foreach ([
            '<DTE xmlns="http://www.sii.cl/SiiDte" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" version="1.0"><Documento ID="T33F1">',
            '<RznSocRecep>Comercial O&apos;Higgins &amp; Cía. Ltda.</RznSocRecep>',
            '<NmbItem>Notebook 14&quot; Ñandú</NmbItem>',
            '<CdgItem><TpoCodigo>INT1</TpoCodigo><VlrCodigo>NB-14</VlrCodigo></CdgItem>',
            '<DescuentoPct>5</DescuentoPct><DescuentoMonto>45000</DescuentoMonto><MontoItem>855000</MontoItem>',
            '<QtyItem>3</QtyItem><UnmdItem>UN</UnmdItem><PrcItem>12990.5</PrcItem><MontoItem>38972</MontoItem>',
            '<Totales><MntNeto>871623</MntNeto><MntExe>35000</MntExe><TasaIVA>19</TasaIVA><IVA>165608</IVA><MntTotal>1072231</MntTotal></Totales>',
            '<Acteco>620200</Acteco><Acteco>465100</Acteco>',
            '<MntPagos><FchPago>2026-12-02</FchPago><MntPago>20000</MntPago><GlosaPagos>Cuota 1</GlosaPagos></MntPagos>',
            '<Referencia><NroLinRef>1</NroLinRef><TpoDocRef>801</TpoDocRef><FolioRef>OC-4512</FolioRef><FchRef>2026-10-30</FchRef><RazonRef>Orden de compra</RazonRef></Referencia>',
            '<RR>96790240-3</RR><RSR>Comercial O&apos;Higgins &amp; Cía. Ltda.</RSR><MNT>1072231</MNT><IT1>Notebook 14&quot; Ñandú</IT1>',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $xml);
        }
    }

    public function testCaracteresFueraDeLatin1(): void
    {
        $doc = Documents::factura();
        $doc->getDetalles()[0]->setNombre('Producto “premium” – edición €uro 😀');
        $xml = self::emitter()->prepare($doc)->getXmlUtf8();
        self::assertStringContainsString('<NmbItem>Producto &quot;premium&quot; - edición EURuro</NmbItem>', $xml);
    }
}
