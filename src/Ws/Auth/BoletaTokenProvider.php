<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Ws\Auth;

use RoyaltyFusion\Sii\Certificate\Certificate;
use RoyaltyFusion\Sii\Environment;
use RoyaltyFusion\Sii\Exception\AuthenticationException;
use RoyaltyFusion\Sii\Ws\Endpoints;
use RoyaltyFusion\Sii\Ws\HttpTransport;
use RoyaltyFusion\Sii\Ws\SiiResponse;

/** Token para la API REST de boletas electrónicas (boleta.electronica.semilla / .token). */
final class BoletaTokenProvider extends AbstractTokenProvider
{
    public function __construct(
        Certificate $certificate,
        Environment $environment,
        private readonly HttpTransport $http,
        private readonly Endpoints $endpoints,
        TokenStorageInterface $storage = new InMemoryTokenStorage(),
        int $ttlSeconds = 1800,
    ) {
        parent::__construct($certificate, $environment, $storage, $ttlSeconds);
    }

    protected function requestSeed(): string
    {
        $response = $this->http->request('GET', $this->endpoints->boletaSeed, ['headers' => ['Accept' => 'application/xml']]);
        if (!$response->isSuccessful()) {
            throw new AuthenticationException(sprintf('El SII respondió HTTP %d al solicitar la semilla de boletas.', $response->status), null, $response->body, $response->status);
        }
        $parsed = SiiResponse::parse($response->body);
        $seed = $parsed->body('SEMILLA') ?? $parsed->find('SEMILLA');
        if ($seed === null || ($parsed->getEstado() !== null && $parsed->getEstado() !== '00')) {
            throw new AuthenticationException('El SII no entregó la semilla para boletas.', $parsed->getEstado(), $response->body);
        }

        return $seed;
    }

    protected function requestToken(string $signedXml): string
    {
        $response = $this->http->request('POST', $this->endpoints->boletaToken, [
            'headers' => ['Content-Type' => 'application/xml', 'Accept' => 'application/xml'],
            'body' => $signedXml,
        ]);
        if (!$response->isSuccessful()) {
            throw new AuthenticationException(sprintf('El SII respondió HTTP %d al solicitar el token de boletas.', $response->status), null, $response->body, $response->status);
        }
        $parsed = SiiResponse::parse($response->body);
        $token = $parsed->body('TOKEN') ?? $parsed->find('TOKEN');
        if ($token === null || $token === '' || ($parsed->getEstado() !== null && $parsed->getEstado() !== '00')) {
            throw new AuthenticationException(sprintf('El SII rechazó la solicitud de token de boletas: %s (estado %s).', $parsed->getGlosa() ?? 'sin glosa', $parsed->getEstado() ?? '?'), $parsed->getEstado(), $response->body);
        }

        return $token;
    }

    protected function service(): string
    {
        return 'boleta';
    }
}
