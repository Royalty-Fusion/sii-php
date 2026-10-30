<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use RoyaltyFusion\Sii\Caf\CafRepositoryInterface;
use RoyaltyFusion\Sii\Model\Resolution;
use RoyaltyFusion\Sii\Util\Redactor;
use RoyaltyFusion\Sii\Ws\Auth\InMemoryTokenStorage;
use RoyaltyFusion\Sii\Ws\Auth\Psr6TokenStorage;
use RoyaltyFusion\Sii\Ws\Auth\TokenStorageInterface;
use RoyaltyFusion\Sii\Ws\HttpTransport;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Crea instancias de See para un ERP multiempresa. La fábrica sólo comparte infraestructura
 * (cliente HTTP, caché de tokens, logger por defecto); todo lo propio de cada empresa (certificado,
 * resolución, folios, CAF, reloj) llega en un SeeConfig, sin estado global.
 *
 * La caché de tokens es segura entre empresas: la clave incluye ambiente y huella del certificado.
 */
final class SeeFactory
{
    private readonly TokenStorageInterface $tokenStorage;
    private readonly HttpClientInterface $httpClient;

    public function __construct(
        private readonly Environment $defaultEnvironment = Environment::CERTIFICACION,
        ?HttpClientInterface $httpClient = null,
        CacheItemPoolInterface|TokenStorageInterface|null $tokenStorage = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly int $tokenTtl = 1800,
        private readonly int $retries = 3,
        private readonly float $timeout = 60.0,
    ) {
        $this->tokenStorage = match (true) {
            $tokenStorage instanceof TokenStorageInterface => $tokenStorage,
            $tokenStorage instanceof CacheItemPoolInterface => new Psr6TokenStorage($tokenStorage),
            default => new InMemoryTokenStorage(),
        };
        $this->httpClient = $httpClient ?? HttpClient::create();
    }

    public function create(SeeConfig $config): See
    {
        $logger = $config->getLogger() ?? $this->logger;
        $transport = new HttpTransport($this->httpClient, $logger, $this->retries, $this->timeout, redactor: new Redactor($config->getSecrets()));

        $see = new See(
            $config->getCertificate(),
            $config->getEnvironment() ?? $this->defaultEnvironment,
            $config->getResolution(),
            $config->getCafRepository(),
            httpClient: $this->httpClient,
            tokenStorage: $this->tokenStorage,
            logger: $logger,
            transport: $transport,
            tokenTtl: $this->tokenTtl,
            clock: $config->getClock(),
        );
        if ($config->getFolioStore() !== null) {
            $see->setFolioStore($config->getFolioStore());
        }
        if ($config->getCompany() !== null) {
            $see->setCompany($config->getCompany());
        }
        if ($config->getRutEnvia() !== null) {
            $see->setRutEnvia($config->getRutEnvia());
        }
        if ($config->getOficinaSii() !== null) {
            $see->setOficinaSii($config->getOficinaSii());
        }
        if ($config->isFolioServiceEnabled()) {
            $see->enableFolioService();
        }
        if ($config->isRcvServiceEnabled()) {
            $see->enableRcvService();
        }

        return $see;
    }

    /** Atajo: certificado desde un archivo .pfx/.p12. */
    public function createFromFile(string $certificatePath, string $password, ?Resolution $resolution = null, ?CafRepositoryInterface $cafs = null, ?Environment $environment = null): See
    {
        $config = SeeConfig::fromFile($certificatePath, $password);
        if ($resolution !== null) {
            $config = $config->withResolution($resolution);
        }
        if ($cafs !== null) {
            $config = $config->withCafRepository($cafs);
        }
        if ($environment !== null) {
            $config = $config->withEnvironment($environment);
        }

        return $this->create($config);
    }
}
