<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Tests\Bridge;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Sii\Bridge\Symfony\SiiBundle;
use RoyaltyFusion\Sii\Caf\CafRepositoryInterface;
use RoyaltyFusion\Sii\Environment;
use RoyaltyFusion\Sii\See;
use RoyaltyFusion\Sii\SeeFactory;
use RoyaltyFusion\Sii\Tests\Fixtures;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class SiiBundleTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function container(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $container->register('cache.app', ArrayAdapter::class)->setPublic(true);
        $bundle = new SiiBundle();
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);
        $container->registerExtension($extension);
        $container->loadFromExtension('sii', $config);
        $bundle->build($container);
        $container->compile();

        return $container;
    }

    public function testConfiguracionCompleta(): void
    {
        $container = $this->container([
            'environment' => 'certificacion',
            'certificate' => ['path' => Fixtures::DIR.'/certificado.pfx', 'password' => Fixtures::PASSWORD],
            'resolution' => ['date' => '2026-10-01', 'number' => 0],
            'caf_directory' => Fixtures::DIR.'/caf',
            'token_cache' => 'cache.app',
            'company' => [
                'rut' => Fixtures::RUT_EMISOR,
                'razon_social' => 'EMPRESA DE PRUEBA SPA',
                'giro' => 'Software',
                'actecos' => [620200],
                'direccion' => 'Av. Providencia 1234',
                'comuna' => 'Providencia',
            ],
        ]);

        $see = $container->get(See::class);
        self::assertInstanceOf(See::class, $see);
        self::assertSame(Environment::CERTIFICACION, $see->getEnvironment());
        self::assertSame(Fixtures::RUT_EMISOR, $see->getCompany()?->getRut());
        self::assertSame(Fixtures::RUT_FIRMANTE, $see->getCertificate()->getRut());

        $cafs = $container->get(CafRepositoryInterface::class);
        self::assertInstanceOf(CafRepositoryInterface::class, $cafs);
        self::assertNotNull($cafs->find(Fixtures::RUT_EMISOR, 33, 10));
    }

    public function testSoloFactory(): void
    {
        $container = $this->container(['environment' => 'produccion']);
        self::assertTrue($container->has(SeeFactory::class));
        self::assertFalse($container->has(See::class));
        $factory = $container->get(SeeFactory::class);
        self::assertInstanceOf(SeeFactory::class, $factory);
        $see = $factory->createFromFile(Fixtures::DIR.'/certificado.pfx', Fixtures::PASSWORD);
        self::assertSame(Environment::PRODUCCION, $see->getEnvironment());
    }
}
