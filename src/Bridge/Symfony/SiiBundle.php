<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Bridge\Symfony;

use RoyaltyFusion\Sii\Caf\CafRepositoryInterface;
use RoyaltyFusion\Sii\Caf\InMemoryCafRepository;
use RoyaltyFusion\Sii\Certificate\Certificate;
use RoyaltyFusion\Sii\Environment;
use RoyaltyFusion\Sii\Model\Company;
use RoyaltyFusion\Sii\Model\Resolution;
use RoyaltyFusion\Sii\See;
use RoyaltyFusion\Sii\SeeFactory;
use RoyaltyFusion\Sii\Validator\DocumentValidator;
use RoyaltyFusion\Sii\Validator\XsdValidator;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Bundle para Symfony 7.4.
 *
 * config/packages/sii.yaml:
 *   sii:
 *     environment: certificacion          # o produccion
 *     certificate:
 *       path: '%env(SII_CERT_PATH)%'
 *       password: '%env(SII_CERT_PASSWORD)%'
 *     resolution: { date: '2024-01-15', number: 0 }
 *     caf_directory: '%kernel.project_dir%/var/sii/caf'
 *     token_cache: cache.app              # pool PSR-6 para compartir el token entre procesos
 *     company:
 *       rut: 76123456-7
 *       razon_social: Mi Empresa SpA
 *       giro: Venta de software
 *       actecos: [620200]
 *       direccion: Av. Siempre Viva 123
 *       comuna: Santiago
 *       ciudad: Santiago
 *
 * Servicios: RoyaltyFusion\Sii\SeeFactory (siempre) y RoyaltyFusion\Sii\See (si se configura el certificado).
 */
final class SiiBundle extends AbstractBundle
{
    protected string $extensionAlias = 'sii';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->enumNode('environment')->values(['certificacion', 'produccion'])->defaultValue('certificacion')->end()
                ->arrayNode('certificate')
                    ->children()
                        ->scalarNode('path')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('password')->defaultValue('')->end()
                    ->end()
                ->end()
                ->arrayNode('resolution')
                    ->children()
                        ->scalarNode('date')->isRequired()->end()
                        ->integerNode('number')->defaultValue(0)->min(0)->end()
                    ->end()
                ->end()
                ->scalarNode('caf_directory')->defaultNull()->end()
                ->scalarNode('caf_repository')->defaultNull()->info('Servicio que implementa CafRepositoryInterface (reemplaza caf_directory).')->end()
                ->scalarNode('token_cache')->defaultNull()->info('Servicio PSR-6 (ej. cache.app).')->end()
                ->integerNode('token_ttl')->defaultValue(1800)->min(60)->end()
                ->scalarNode('http_client')->defaultValue('http_client')->end()
                ->scalarNode('logger')->defaultValue('logger')->end()
                ->integerNode('retries')->defaultValue(3)->min(0)->end()
                ->floatNode('timeout')->defaultValue(60.0)->end()
                ->arrayNode('company')
                    ->children()
                        ->scalarNode('rut')->isRequired()->end()
                        ->scalarNode('razon_social')->isRequired()->end()
                        ->scalarNode('giro')->isRequired()->end()
                        ->arrayNode('actecos')->integerPrototype()->end()->end()
                        ->scalarNode('direccion')->defaultNull()->end()
                        ->scalarNode('comuna')->defaultNull()->end()
                        ->scalarNode('ciudad')->defaultNull()->end()
                        ->scalarNode('email')->defaultNull()->end()
                        ->arrayNode('telefonos')->scalarPrototype()->end()->end()
                        ->integerNode('codigo_sucursal')->defaultNull()->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();
        $optional = static fn (?string $id): ?Reference => $id === null ? null : new Reference($id, ContainerInterface::NULL_ON_INVALID_REFERENCE);

        $services->set(SeeFactory::class)
            ->args([
                Environment::fromString($config['environment']),
                $optional($config['http_client']),
                $optional($config['token_cache']),
                $optional($config['logger']),
                $config['token_ttl'],
                $config['retries'],
                $config['timeout'],
            ])
            ->public();

        $services->set(DocumentValidator::class)->public();
        $services->set(XsdValidator::class)->public();

        if (isset($config['caf_repository'])) {
            $services->alias(CafRepositoryInterface::class, $config['caf_repository'])->public();
        } else {
            $repo = $services->set(InMemoryCafRepository::class);
            if (isset($config['caf_directory'])) {
                $repo->call('addDirectory', [$config['caf_directory']]);
            }
            $services->alias(CafRepositoryInterface::class, InMemoryCafRepository::class)->public();
        }

        if (!isset($config['certificate'])) {
            return;
        }

        $services->set('sii.certificate', Certificate::class)
            ->factory([Certificate::class, 'fromFile'])
            ->args([$config['certificate']['path'], $config['certificate']['password']]);

        $resolution = null;
        if (isset($config['resolution'])) {
            $services->set('sii.resolution', Resolution::class)->args([(string) $config['resolution']['date'], $config['resolution']['number']]);
            $resolution = new Reference('sii.resolution');
        }

        $see = $services->set(See::class)
            ->factory([new Reference(SeeFactory::class), 'create'])
            ->args([new Reference('sii.certificate'), $resolution, new Reference(CafRepositoryInterface::class)])
            ->public();

        if (isset($config['company'])) {
            $c = $config['company'];
            $services->set('sii.company', Company::class)
                ->call('setRut', [$c['rut']])
                ->call('setRazonSocial', [$c['razon_social']])
                ->call('setGiro', [$c['giro']])
                ->call('setActecos', [$c['actecos']])
                ->call('setDireccion', [$c['direccion']])
                ->call('setComuna', [$c['comuna']])
                ->call('setCiudad', [$c['ciudad']])
                ->call('setEmail', [$c['email']])
                ->call('setTelefonos', [$c['telefonos']])
                ->call('setCodigoSucursal', [$c['codigo_sucursal']]);
            $see->call('setCompany', [new Reference('sii.company')]);
        }
    }
}
