<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use RoyaltyFusion\Sii\Caf\CafRepositoryInterface;
use RoyaltyFusion\Sii\Certificate\Certificate;
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

    public function __construct(
        private readonly Environment $defaultEnvironment = Environment::CERTIFICACION,
        ?HttpClientInterface $httpClient = null,
        CacheItemPoolInterface|TokenStorageInterface|null $tokenStorage = null,
        ?LoggerInterface $logger = null,
        private readonly int $tokenTtl = 1800,
        int $retries = 3,
        float $timeout = 60.0,
    ) {
        $this->tokenStorage = match (true) {
            $tokenStorage instanceof TokenStorageInterface => $tokenStorage,
            $tokenStorage instanceof CacheItemPoolInterface => new Psr6TokenStorage($tokenStorage),
            default => new InMemoryTokenStorage(),
        };
        $this->transport = new HttpTransport($httpClient, $logger, $retries, $timeout);
    }

    public function create(
        Certificate $certificate,
        ?Resolution $resolution = null,
        ?CafRepositoryInterface $cafs = null,
        ?Environment $environment = null,
    ): See {
        return new See(
            $certificate,
            $environment ?? $this->defaultEnvironment,
            $resolution,
            $cafs,
            tokenStorage: $this->tokenStorage,
            transport: $this->transport,
            tokenTtl: $this->tokenTtl,
        );
    }

    /** Atajo: carga el certificado desde un archivo .pfx/.p12. */
    public function createFromFile(string $certificatePath, string $password, ?Resolution $resolution = null, ?CafRepositoryInterface $cafs = null, ?Environment $environment = null): See
    {
        return $this->create(Certificate::fromFile($certificatePath, $password), $resolution, $cafs, $environment);
    }
}
