<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Ws\Auth;

use RoyaltyFusion\Sii\Certificate\Certificate;
use RoyaltyFusion\Sii\Environment;
use RoyaltyFusion\Sii\Exception\AuthenticationException;
use RoyaltyFusion\Sii\Xmldsig\XmlSigner;

/**
 * Flujo de autenticación del SII:
 *  1. obtener una semilla (getSeed)
 *  2. firmar <getToken><item><Semilla>…</Semilla></item></getToken> con el certificado (enveloped)
 *  3. canjear el XML firmado por un token (getToken)
 */
abstract class AbstractTokenProvider implements TokenProviderInterface
{
    public function __construct(
        protected readonly Certificate $certificate,
        protected readonly Environment $environment,
        protected readonly TokenStorageInterface $storage = new InMemoryTokenStorage(),
        protected readonly int $ttlSeconds = 1800,
    ) {
    }

    public function getToken(bool $forceRefresh = false): string
    {
        $key = $this->storageKey();
        if (!$forceRefresh && ($token = $this->storage->get($key)) !== null) {
            return $token;
        }
        $seed = $this->requestSeed();
        if (!preg_match('/^\d+$/', $seed)) {
            throw new AuthenticationException(sprintf('Semilla inválida recibida del SII: "%s".', $seed));
        }
        $token = $this->requestToken($this->signSeed($seed));
        $this->storage->set($key, $token, $this->ttlSeconds);

        return $token;
    }

    public function invalidate(): void
    {
        $this->storage->delete($this->storageKey());
    }

    /** XML de solicitud de token firmado (expuesto para depuración). */
    public function signSeed(string $seed): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<getToken><item><Semilla>'.$seed.'</Semilla></item></getToken>';

        return (new XmlSigner($this->certificate))->signEnveloped($xml);
    }

    abstract protected function requestSeed(): string;

    abstract protected function requestToken(string $signedXml): string;

    abstract protected function service(): string;

    protected function storageKey(): string
    {
        return sprintf('sii_token.%s.%s.%s', $this->service(), $this->environment->value, sha1($this->certificate->getCertificateBase64()));
    }
}
