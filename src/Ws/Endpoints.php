<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Ws;

use RoyaltyFusion\Sii\Environment;

/**
 * URLs de los servicios del SII para un ambiente. Se pueden sobreescribir (proxy, pruebas).
 */
final class Endpoints
{
    public function __construct(
        public readonly string $seed,
        public readonly string $token,
        public readonly string $upload,
        public readonly string $queryEstUp,
        public readonly string $queryEstDte,
        public readonly string $queryEstDteAv,
        public readonly string $boletaSeed,
        public readonly string $boletaToken,
        public readonly string $boletaUpload,
        public readonly string $boletaApi,
        public readonly string $registroReclamo,
    ) {
    }

    public static function for(Environment $environment): self
    {
        $dte = 'https://'.$environment->dteHost();
        $api = 'https://'.$environment->boletaApiHost().'/recursos/v1';

        return new self(
            seed: $dte.'/DTEWS/CrSeed.jws',
            token: $dte.'/DTEWS/GetTokenFromSeed.jws',
            upload: $dte.'/cgi_dte/UPL/DTEUpload',
            queryEstUp: $dte.'/DTEWS/QueryEstUp.jws',
            queryEstDte: $dte.'/DTEWS/QueryEstDte.jws',
            queryEstDteAv: $dte.'/DTEWS/services/QueryEstDteAv',
            boletaSeed: $api.'/boleta.electronica.semilla',
            boletaToken: $api.'/boleta.electronica.token',
            boletaUpload: 'https://'.$environment->boletaUploadHost().'/recursos/v1/boleta.electronica.envio',
            boletaApi: $api,
            registroReclamo: $environment->registroReclamoUrl(),
        );
    }

    /** @param array<string, string> $overrides */
    public function with(array $overrides): self
    {
        $values = get_object_vars($this);
        foreach ($overrides as $key => $value) {
            if (!\array_key_exists($key, $values)) {
                throw new \RoyaltyFusion\Sii\Exception\InvalidArgumentException(sprintf('Endpoint "%s" desconocido.', $key));
            }
            $values[$key] = $value;
        }

        return new self(...$values);
    }
}
