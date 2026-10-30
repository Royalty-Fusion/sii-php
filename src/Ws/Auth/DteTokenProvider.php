<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Ws\Auth;

use RoyaltyFusion\Sii\Certificate\Certificate;
use RoyaltyFusion\Sii\Environment;
use RoyaltyFusion\Sii\Exception\AuthenticationException;
use RoyaltyFusion\Sii\Ws\Endpoints;
use RoyaltyFusion\Sii\Ws\SiiResponse;
use RoyaltyFusion\Sii\Ws\SoapClient;

/** Token para los servicios SOAP y el upload de DTE (CrSeed.jws + GetTokenFromSeed.jws). */
final class DteTokenProvider extends AbstractTokenProvider
{
    public function __construct(
        Certificate $certificate,
        Environment $environment,
        private readonly SoapClient $soap,
        private readonly Endpoints $endpoints,
        TokenStorageInterface $storage = new InMemoryTokenStorage(),
        int $ttlSeconds = 1800,
    ) {
        parent::__construct($certificate, $environment, $storage, $ttlSeconds);
    }

    protected function requestSeed(): string
    {
        $response = SiiResponse::parse($this->soap->callRpc($this->endpoints->seed, 'getSeed'));
        if ($response->getEstado() !== '00' || ($seed = $response->body('SEMILLA')) === null) {
            throw new AuthenticationException(sprintf('El SII no entregó la semilla (estado %s).', $response->getEstado() ?? '?'), $response->getEstado(), $response->raw);
        }

        return $seed;
    }

    protected function requestToken(string $signedXml): string
    {
        $response = SiiResponse::parse($this->soap->callRpc($this->endpoints->token, 'getToken', ['pszXml' => $signedXml]));
        $token = $response->body('TOKEN');
        if ($response->getEstado() !== '00' || $token === null || $token === '') {
            throw new AuthenticationException(sprintf('El SII rechazó la solicitud de token: %s (estado %s). Verifique que el certificado esté vigente y autorizado en el SII.', $response->getGlosa() ?? 'sin glosa', $response->getEstado() ?? '?'), $response->getEstado(), $response->raw);
        }

        return $token;
    }

    protected function service(): string
    {
        return 'dte';
    }
}
