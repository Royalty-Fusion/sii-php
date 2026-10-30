<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Tests\Ws;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Sii\Environment;
use RoyaltyFusion\Sii\Exception\InvalidArgumentException;
use RoyaltyFusion\Sii\Folio\InMemoryFolioStore;
use RoyaltyFusion\Sii\Model\Resolution;
use RoyaltyFusion\Sii\SeeConfig;
use RoyaltyFusion\Sii\SeeFactory;
use RoyaltyFusion\Sii\Tests\Documents;
use RoyaltyFusion\Sii\Tests\Fixtures;
use RoyaltyFusion\Sii\Util\Text;
use Symfony\Component\HttpClient\MockHttpClient;

final class SeeFactoryTest extends TestCase
{
    private function config(string $origen): SeeConfig
    {
        $base = match ($origen) {
            'pkcs12' => SeeConfig::fromPkcs12((string) file_get_contents(Fixtures::DIR.'/certificado.pfx'), Fixtures::PASSWORD),
            'pem' => SeeConfig::fromPem((string) file_get_contents(Fixtures::DIR.'/certificado.pem'), (string) file_get_contents(Fixtures::DIR.'/certificado.pem')),
            default => SeeConfig::fromFile(Fixtures::DIR.'/certificado.pfx', Fixtures::PASSWORD),
        };

        return $base
            ->withEnvironment(Environment::CERTIFICACION)
            ->withResolution(new Resolution('2026-10-01', 0))
            ->withCafRepository(Fixtures::cafs())
            ->withCompany(Fixtures::emisor())
            ->withClock(static fn () => new \DateTimeImmutable('2026-11-02T13:30:00+00:00'));
    }

    public function testCreaInstanciasPorEmpresaSinCompartirFolios(): void
    {
        $factory = new SeeFactory(httpClient: new MockHttpClient());
        $a = $factory->create($this->config('pkcs12')->withFolioStore(new InMemoryFolioStore()));
        $b = $factory->create($this->config('pem')->withFolioStore(new InMemoryFolioStore()));

        self::assertSame(1, $a->prepare(Documents::factura()->setFolio(null))->document->getFolio());
        self::assertSame(2, $a->prepare(Documents::factura()->setFolio(null))->document->getFolio());
        self::assertSame(1, $b->prepare(Documents::factura()->setFolio(null))->document->getFolio(), 'Cada empresa tiene su propio registro de folios');

        // El reloj de la configuración llega al emisor (y se convierte a hora de Chile).
        self::assertStringContainsString('<TmstFirma>2026-11-02T10:30:00</TmstFirma>', Text::toUtf8($a->prepare(Documents::factura(50))->xml));
    }

    public function testConfiguracionInmutable(): void
    {
        $base = $this->config('archivo');
        $prod = $base->withEnvironment(Environment::PRODUCCION);
        self::assertSame(Environment::CERTIFICACION, $base->getEnvironment());
        self::assertSame(Environment::PRODUCCION, $prod->getEnvironment());
        self::assertSame([Fixtures::PASSWORD], $base->getSecrets());
    }

    public function testTimbrajeWebDesactivadoPorDefecto(): void
    {
        $factory = new SeeFactory(httpClient: new MockHttpClient());
        $see = $factory->create($this->config('archivo'));
        try {
            $see->getFolioService();
            self::fail('Debe estar desactivado');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('desactivado', $e->getMessage());
        }
        self::assertNotNull($factory->create($this->config('archivo')->withFolioService())->getFolioService());
    }
}
