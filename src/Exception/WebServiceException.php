<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Exception;

/** Error de comunicación o respuesta de error de un web service del SII. */
class WebServiceException extends \RuntimeException implements SiiException
{
    public function __construct(
        string $message,
        private readonly ?string $siiCode = null,
        private readonly ?string $responseBody = null,
        private readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Código de estado/error informado por el SII (ej. "05", "RCT", "-11"). */
    public function getSiiCode(): ?string
    {
        return $this->siiCode;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }
}
