<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Tests\Xml;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Sii\Emitter;
use RoyaltyFusion\Sii\Environment;
use RoyaltyFusion\Sii\Model\Resolution;
use RoyaltyFusion\Sii\Tests\Documents;
use RoyaltyFusion\Sii\Tests\Fixtures;
use RoyaltyFusion\Sii\Util\Clock;
use RoyaltyFusion\Sii\Util\Text;
use RoyaltyFusion\Sii\Validator\DocumentValidator;

/**
 * Todo lo que genera el SDK debe quedar en hora de Chile, sin importar la zona horaria global del
 * servidor (el ERP corre con America/Guatemala y no puede cambiarla).
 */
final class TimezoneTest extends TestCase
{
    private string $previous;

    protected function setUp(): void
    {
        $this->previous = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previous);
    }

    /** @return iterable<string, array{string}> */
    public static function zonas(): iterable
    {
        yield 'UTC' => ['UTC'];
        yield 'Guatemala' => ['America/Guatemala'];
        yield 'Tokio' => ['Asia/Tokyo'];
    }

    #[DataProvider('zonas')]
    public function testFechasYHorasEnHoraDeChile(string $zona): void
    {
        date_default_timezone_set($zona);
        // 2026-11-02 02:30 UTC = 2026-11-01 23:30 en Chile (horario de verano, UTC-3).
        $emitter = new Emitter(Fixtures::certificate(), Fixtures::cafs(), Environment::CERTIFICACION,
            clock: static fn () => new \DateTimeImmutable('2026-11-02T02:30:00+00:00'));

        $factura = Documents::factura()->setFechaEmision(null);
        $factura->setReferencias([]);
        $factura->setPagos([])->setFechaVencimiento(null);
        $prepared = $emitter->prepare($factura);
        $xml = $prepared->getXmlUtf8();

        self::assertStringContainsString('<FchEmis>2026-11-01</FchEmis>', $xml);
        self::assertStringContainsString('<FE>2026-11-01</FE>', $xml);
        self::assertStringContainsString('<TSTED>2026-11-01T23:30:00</TSTED>', $xml);
        self::assertStringContainsString('<TmstFirma>2026-11-01T23:30:00</TmstFirma>', $xml);
        self::assertSame('America/Santiago', $prepared->timestamp->getTimezone()->getName());

        $envio = Text::toUtf8($emitter->envelope([$prepared], new Resolution('2026-10-01', 0)));
        self::assertStringContainsString('<FchResol>2026-10-01</FchResol>', $envio);
        self::assertStringContainsString('<TmstFirmaEnv>2026-11-01T23:30:00</TmstFirmaEnv>', $envio);
    }

    #[DataProvider('zonas')]
    public function testVencimientoDelCafEnHoraDeChile(string $zona): void
    {
        date_default_timezone_set($zona);
        $caf = Fixtures::caf(33); // FA 2026-10-01: vence el 2027-04-01 (inclusive)
        self::assertSame('America/Santiago', $caf->getFechaAutorizacion()->getTimezone()->getName());
        // 2027-04-02 02:30 UTC todavía es 2027-04-01 en Chile.
        self::assertFalse($caf->isVencido(new \DateTimeImmutable('2027-04-02T02:30:00+00:00')));
        self::assertTrue($caf->isVencido(new \DateTimeImmutable('2027-04-02T04:30:00+00:00')));
    }

    #[DataProvider('zonas')]
    public function testValidacionDeFechaFuturaEnHoraDeChile(string $zona): void
    {
        date_default_timezone_set($zona);
        $ahora = new \DateTimeImmutable('2026-11-02T02:30:00+00:00'); // 2026-11-01 en Chile
        $validator = new DocumentValidator();

        $manana = Documents::factura()->setFechaEmision(Clock::date('2026-11-02'));
        self::assertSame('FECHA_FUTURA', $validator->validate($manana, now: $ahora)->getWarnings()[0]->code ?? null);

        $hoy = Documents::factura()->setFechaEmision(Clock::date('2026-11-01'));
        $hoy->setReferencias([]);
        self::assertSame([], $validator->validate($hoy, now: $ahora)->getWarnings());
    }

    public function testResolucionYCertificadoEnHoraDeChile(): void
    {
        date_default_timezone_set('America/Guatemala');
        self::assertSame('2026-10-01', (new Resolution('2026-10-01', 0))->fecha->format('Y-m-d'));
        self::assertSame('America/Santiago', Fixtures::certificate()->getValidTo()->getTimezone()->getName());
    }
}
