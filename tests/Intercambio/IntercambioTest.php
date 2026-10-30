<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Tests\Intercambio;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Sii\Intercambio\EnvioDteReader;
use RoyaltyFusion\Sii\Intercambio\ResponseBuilder;
use RoyaltyFusion\Sii\Model\Resolution;
use RoyaltyFusion\Sii\Tests\Documents;
use RoyaltyFusion\Sii\Tests\Fixtures;
use RoyaltyFusion\Sii\Tests\Xml\EmissionTest;
use RoyaltyFusion\Sii\Util\Text;
use RoyaltyFusion\Sii\Validator\XsdValidator;
use RoyaltyFusion\Sii\Xmldsig\XmlSignatureVerifier;

final class IntercambioTest extends TestCase
{
    private const RUT_RECEPTOR = '96790240-3';

    private function envio(): string
    {
        $emitter = EmissionTest::emitter();

        return $emitter->envelope(
            [$emitter->prepare(Documents::factura(30)), $emitter->prepare(Documents::factura(31))],
            new Resolution('2026-10-01', 0),
            null,
            self::RUT_RECEPTOR,
        );
    }

    private function builder(): ResponseBuilder
    {
        return new ResponseBuilder(Fixtures::certificate(), clock: static fn () => new \DateTimeImmutable('2026-11-03 09:00:00'), nombreContacto: 'Encargado DTE', mailContacto: 'dte@cliente.cl');
    }

    public function testLeeEnvioRecibido(): void
    {
        $envelope = (new EnvioDteReader())->read($this->envio());
        self::assertTrue($envelope->schemaValido, implode("\n", $envelope->erroresSchema));
        self::assertTrue($envelope->firmaValida);
        self::assertSame('SetDoc', $envelope->setId);
        self::assertSame(self::RUT_RECEPTOR, $envelope->rutReceptor);
        self::assertCount(2, $envelope->documentos);
        $dte = $envelope->documentos[0];
        self::assertSame(33, $dte->tipoDte);
        self::assertSame(30, $dte->folio);
        self::assertSame(1072231, $dte->montoTotal);
        self::assertSame(165608, $dte->iva);
        self::assertTrue($dte->firmaValida);
        self::assertTrue($dte->timbreValido);
        self::assertStringStartsWith('<DTE ', $dte->xml);
    }

    public function testDetectaDocumentoAdulterado(): void
    {
        $xml = Text::toLatin1(str_replace('<MntTotal>1072231</MntTotal>', '<MntTotal>1</MntTotal>', Text::toUtf8($this->envio())));
        $envelope = (new EnvioDteReader())->read($xml);
        self::assertFalse($envelope->firmaValida);
        self::assertFalse($envelope->documentos[0]->firmaValida);
    }

    public function testRespuestas(): void
    {
        $envelope = (new EnvioDteReader())->read($this->envio());
        $builder = $this->builder();
        $xsd = new XsdValidator();
        $verifier = new XmlSignatureVerifier();

        $recepcion = $builder->recepcionEnvio($envelope, 'EnvioDTE_76192083.xml', self::RUT_RECEPTOR, 1);
        self::assertTrue($xsd->validate($recepcion)->isValid());
        self::assertTrue($verifier->isValid($recepcion));
        self::assertStringContainsString('<EstadoRecepEnv>0</EstadoRecepEnv><RecepEnvGlosa>Envio Recibido Conforme</RecepEnvGlosa><NroDTE>2</NroDTE>', $recepcion);
        self::assertSame(2, substr_count($recepcion, '<EstadoRecepDTE>0</EstadoRecepDTE>'));

        $resultado = $builder->resultadoDte([
            ['dte' => $envelope->documentos[0], 'estado' => ResponseBuilder::ACEPTADO],
            ['dte' => $envelope->documentos[1], 'estado' => ResponseBuilder::RECHAZADO, 'glosa' => 'Precio no corresponde', 'codigoRechazo' => -1],
        ], self::RUT_RECEPTOR, 2);
        self::assertTrue($xsd->validate($resultado)->isValid());
        self::assertTrue($verifier->isValid($resultado));
        self::assertStringContainsString('<EstadoDTE>2</EstadoDTE><EstadoDTEGlosa>Precio no corresponde</EstadoDTEGlosa><CodRchDsc>-1</CodRchDsc>', $resultado);

        $recibos = $builder->envioRecibos($envelope->documentos, self::RUT_RECEPTOR, 'Bodega central');
        self::assertTrue($xsd->validate($recibos)->isValid(), (string) $xsd->validate($recibos));
        $checks = $verifier->verifyAll($recibos);
        self::assertCount(3, $checks);
        foreach ($checks as $check) {
            self::assertTrue($check->isValid(), $check->referenceUri.' '.$check->error);
        }
    }

    public function testRechazaSobreDeOtroReceptor(): void
    {
        $envelope = (new EnvioDteReader())->read($this->envio());
        $recepcion = $this->builder()->recepcionEnvio($envelope, 'envio.xml', '76192083-9', 1);
        self::assertStringContainsString('<EstadoRecepEnv>3</EstadoRecepEnv>', $recepcion);
    }
}
