<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Tests\Caf;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Sii\Caf\Caf;
use RoyaltyFusion\Sii\Exception\CafException;
use RoyaltyFusion\Sii\Tests\Fixtures;
use RoyaltyFusion\Sii\Xml\Builder\TedBuilder;

final class CafTest extends TestCase
{
    public function testParseo(): void
    {
        $caf = Fixtures::caf(33);
        self::assertSame(Fixtures::RUT_EMISOR, $caf->getRutEmisor());
        self::assertSame(33, $caf->getTipoDte());
        self::assertSame(1, $caf->getFolioDesde());
        self::assertSame(100, $caf->getFolioHasta());
        self::assertSame(100, $caf->getCantidadFolios());
        self::assertTrue($caf->isCertificacion());
        self::assertTrue($caf->contains(50));
        self::assertFalse($caf->contains(101));
        self::assertStringStartsWith('<CAF version="1.0"><DA><RE>', $caf->getCafXml());
        self::assertStringNotContainsString("\n", $caf->getCafXml());
        self::assertTrue($caf->verifySiiSignature((string) file_get_contents(Fixtures::DIR.'/caf/sii_fake_public.pem')));
    }

    public function testVencimiento(): void
    {
        $factura = Fixtures::caf(33);
        self::assertSame('2027-04-01', $factura->getFechaVencimiento()?->format('Y-m-d'));
        self::assertFalse($factura->isVencido(new \DateTimeImmutable('2027-04-01 12:00', new \DateTimeZone('America/Santiago'))));
        self::assertTrue($factura->isVencido(new \DateTimeImmutable('2027-04-02 12:00', new \DateTimeZone('America/Santiago'))));
        // Los folios de boletas no vencen.
        self::assertNull(Fixtures::caf(39)->getFechaVencimiento());
        self::assertFalse(Fixtures::caf(39)->isVencido(new \DateTimeImmutable('2040-01-01')));
    }

    public function testClavesNoCorresponden(): void
    {
        $a = (string) file_get_contents(Fixtures::DIR.'/caf/caf_33.xml');
        $b = (string) file_get_contents(Fixtures::DIR.'/caf/caf_34.xml');
        preg_match('#<RSASK>.*</RSASK>#s', $b, $m);
        $mezcla = (string) preg_replace('#<RSASK>.*</RSASK>#s', $m[0], $a);
        $this->expectException(CafException::class);
        Caf::fromXml($mezcla);
    }

    public function testTimbre(): void
    {
        $caf = Fixtures::caf(33);
        $ted = (new TedBuilder())->build($caf, Fixtures::RUT_EMISOR, 33, 10, Fixtures::fecha(), '96790240-3', str_repeat('Razón Social & Cía ', 5), '119000', 'Producto "especial" <A>', new \DateTimeImmutable('2026-10-15T10:00:00'));
        self::assertStringContainsString('<RSR>Razón Social &amp; Cía Razón Social &amp; Cía Ra</RSR>', $ted);
        self::assertStringContainsString('<IT1>Producto &quot;especial&quot; &lt;A&gt;</IT1>', $ted);
        self::assertStringContainsString('<TSTED>2026-10-15T10:00:00</TSTED>', $ted);
        self::assertTrue(TedBuilder::verify($ted, $caf));
        self::assertFalse(TedBuilder::verify(str_replace('<F>10</F>', '<F>11</F>', $ted), $caf));
    }
}
