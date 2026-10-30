<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use RoyaltyFusion\Sii\Caf\CafRepositoryInterface;
use RoyaltyFusion\Sii\Certificate\Certificate;
use RoyaltyFusion\Sii\Folio\FolioStoreInterface;
use RoyaltyFusion\Sii\Model\Resolution;
use RoyaltyFusion\Sii\Ws\Auth\InMemoryTokenStorage;
use RoyaltyFusion\Sii\Ws\Auth\Psr6TokenStorage;
use RoyaltyFusion\Sii\Ws\Auth\TokenStorageInterface;
use RoyaltyFusion\Sii\Ws\HttpTransport;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Crea instancias de See compartiendo cliente HTTP, caché de tokens y logger.
 * Pensado para ERP multiempresa: una instancia por empresa/certificado.
 */
final class SeeFactory
{
    private readonly TokenStorageInterface $tokenStorage;
    private readonly HttpTransport $transport;
    private readonly ?LoggerInterface $logger;
    private readonly ?HttpClientInterface $httpClient;

    public function __construct(
        private readonly Environment $defaultEnvironment = Environment::CERTIFICACION,
        ?HttpClientInterface $httpClient = null,
        CacheItemPoolInterface|TokenStorageInterface|null $tokenStorage = null,
        ?LoggerInterface $logger = null,
        private readonly int $tokenTtl = 1800,
        int $retries = 3,
        float $timeout = 60.0,
        private readonly ?FolioStoreInterface $folioStore = null,
    ) {
        $this->httpClient = $httpClient;
        $this->tokenStorage = match (true) {
            $tokenStorage instanceof TokenStorageInterface => $tokenStorage,
            $tokenStorage instanceof CacheItemPoolInterface => new Psr6TokenStorage($tokenStorage),
            default => new InMemoryTokenStorage(),
        };
        $this->transport = new HttpTransport($httpClient, $logger, $retries, $timeout);
        $this->logger = $logger;
    }

    public function create(
        Certificate $certificate,
        ?Resolution $resolution = null,
        ?CafRepositoryInterface $cafs = null,
        ?Environment $environment = null,
    ): See {
        $see = new See(
            $certificate,
            $environment ?? $this->defaultEnvironment,
            $resolution,
            $cafs,
            httpClient: $this->httpClient,
            tokenStorage: $this->tokenStorage,
            logger: $this->logger,
            transport: $this->transport,
            tokenTtl: $this->tokenTtl,
        );
        if ($this->folioStore !== null) {
            $see->setFolioStore($this->folioStore);
        }

        return $see;
    }

    /** Atajo: carga el certificado desde un archivo .pfx/.p12. */
    public function createFromFile(string $certificatePath, string $password, ?Resolution $resolution = null, ?CafRepositoryInterface $cafs = null, ?Environment $environment = null): See
    {
        return $this->create(Certificate::fromFile($certificatePath, $password), $resolution, $cafs, $environment);
    }
}
