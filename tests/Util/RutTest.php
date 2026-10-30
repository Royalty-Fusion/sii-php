<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Tests\Util;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Sii\Exception\InvalidArgumentException;
use RoyaltyFusion\Sii\Util\Rut;

final class RutTest extends TestCase
{
    /** @return iterable<array{string, string}> */
    public static function validos(): iterable
    {
        yield ['76.192.083-9', '76192083-9'];
        yield ['60803000-k', '60803000-K'];
        yield ['666666666', '66666666-6'];
        yield ['11.111.111-1', '11111111-1'];
        yield [' 96790240-3 ', '96790240-3'];
    }

    #[DataProvider('validos')]
    public function testNormaliza(string $input, string $expected): void
    {
        self::assertTrue(Rut::isValid($input));
        self::assertSame($expected, Rut::normalize($input));
    }

    public function testDigitoVerificadorIncorrecto(): void
    {
        self::assertFalse(Rut::isValid('76192083-1'));
        $this->expectException(InvalidArgumentException::class);
        Rut::parse('76192083-1');
    }

    public function testFormatoConPuntos(): void
    {
        self::assertSame('76.192.083-9', Rut::parse('761920839')->format(true));
        self::assertSame('K', Rut::computeDv(60803000));
        self::assertSame('0', Rut::computeDv(14));
    }
}
