<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Tests\Certificate;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Sii\Certificate\Certificate;
use RoyaltyFusion\Sii\Exception\CertificateException;
use RoyaltyFusion\Sii\Tests\Fixtures;

final class CertificateTest extends TestCase
{
    public function testCargaPfx(): void
    {
        $cert = Certificate::fromFile(Fixtures::DIR.'/certificado.pfx', Fixtures::PASSWORD);
        self::assertSame(Fixtures::RUT_FIRMANTE, $cert->getRut());
        self::assertSame('USUARIO DE PRUEBA SDK', $cert->getName());
        self::assertSame('prueba@example.cl', $cert->getEmail());
        self::assertTrue($cert->isValidAt(new \DateTimeImmutable('2030-01-01')));
        self::assertSame('AQAB', $cert->getExponent());
        self::assertNotEmpty($cert->getModulus());
        self::assertStringNotContainsString('BEGIN', $cert->getCertificateBase64());
    }

    public function testCargaPfxLegacy(): void
    {
        // PFX con cifrado RC2-40 (como los de varias entidades certificadoras): requiere fallback a "openssl -legacy".
        $cert = Certificate::fromFile(Fixtures::DIR.'/certificado-legacy.pfx', Fixtures::PASSWORD);
        self::assertSame(Fixtures::RUT_FIRMANTE, $cert->getRut());
    }

    public function testCargaPem(): void
    {
        $pem = (string) file_get_contents(Fixtures::DIR.'/certificado.pem');
        $cert = Certificate::fromPem($pem, $pem);
        self::assertSame(Fixtures::RUT_FIRMANTE, $cert->getRut());
    }

    public function testFirmaVerificable(): void
    {
        $cert = Fixtures::certificate();
        $signature = $cert->sign('hola');
        $public = openssl_pkey_get_public($cert->getCertificatePem());
        self::assertNotFalse($public);
        self::assertSame(1, openssl_verify('hola', $signature, $public, \OPENSSL_ALGO_SHA1));
    }

    public function testClaveIncorrecta(): void
    {
        $this->expectException(CertificateException::class);
        Certificate::fromFile(Fixtures::DIR.'/certificado.pfx', 'otra-clave');
    }
}
