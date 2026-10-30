<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Tests\Validator;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Sii\Model\Client;
use RoyaltyFusion\Sii\Model\DocumentType;
use RoyaltyFusion\Sii\Model\Reference;
use RoyaltyFusion\Sii\Tests\Documents;
use RoyaltyFusion\Sii\Tests\Fixtures;
use RoyaltyFusion\Sii\Tests\Xml\EmissionTest;
use RoyaltyFusion\Sii\Util\Rut;
use RoyaltyFusion\Sii\Validator\DocumentValidator;

final class ConsumidorFinalTest extends TestCase
{
    public function testHelpers(): void
    {
        self::assertSame('66666666-6', Rut::CONSUMIDOR_FINAL);
        self::assertTrue(Rut::isValid(Rut::CONSUMIDOR_FINAL));
        self::assertTrue(Rut::isConsumidorFinal('66.666.666-6'));
        self::assertFalse(Rut::isConsumidorFinal('11111111-1'));
        self::assertTrue(Client::consumidorFinal()->isConsumidorFinal());
    }

    public function testBoletaConYSinReceptor(): void
    {
        $emitter = EmissionTest::emitter();
        $sin = $emitter->prepare(Documents::boleta(10))->getXmlUtf8();
        self::assertStringContainsString('<Receptor><RUTRecep>66666666-6</RUTRecep></Receptor>', $sin);
        self::assertStringContainsString('<RR>66666666-6</RR>', $sin);
        $con = $emitter->prepare(Documents::boleta(11)->setReceptor(Client::consumidorFinal()))->getXmlUtf8();
        self::assertStringContainsString('<RUTRecep>66666666-6</RUTRecep><RznSocRecep>Consumidor final</RznSocRecep>', $con);
    }

    public function testNoSeAdmiteEnFacturas(): void
    {
        $validator = new DocumentValidator();
        $factura = Documents::factura()->setReceptor(Client::consumidorFinal());
        $codes = array_map(static fn ($v) => $v->code, $validator->validate($factura, now: Fixtures::fecha())->getErrors());
        self::assertContains('CONSUMIDOR_FINAL', $codes);

        // Nota de crédito que anula una boleta: sí se admite.
        $nc = Documents::notaCredito()->setReceptor(Client::consumidorFinal())
            ->setReferencias([Reference::create(DocumentType::BOLETA, 25, Fixtures::fecha(), Reference::ANULA, 'Anula boleta')]);
        self::assertTrue($validator->validate($nc, now: Fixtures::fecha())->isValid(), (string) $validator->validate($nc, now: Fixtures::fecha()));
    }
}
