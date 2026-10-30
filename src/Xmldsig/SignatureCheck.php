<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Xmldsig;

/** Resultado de la verificación de una firma XMLDSig. */
final class SignatureCheck
{
    public function __construct(
        public readonly string $referenceUri,
        public readonly bool $digestValid,
        public readonly bool $signatureValid,
        public readonly ?string $error = null,
    ) {
    }

    public function isValid(): bool
    {
        return $this->digestValid && $this->signatureValid;
    }
}
