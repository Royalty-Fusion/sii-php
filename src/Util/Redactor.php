<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Util;

/**
 * Oculta secretos antes de escribir en logs: token del SII (en XML, cookies y parámetros SOAP),
 * semilla firmada (getToken), SignatureValue, X509Certificate, clave privada del CAF (RSASK),
 * claves privadas PEM y valores registrados explícitamente (por ejemplo, la contraseña del certificado).
 */
final class Redactor
{
    public const MASK = '***';

    /** @var list<string> */
    private array $secrets = [];

    /** @param list<string> $secrets */
    public function __construct(array $secrets = [])
    {
        foreach ($secrets as $secret) {
            $this->addSecret($secret);
        }
    }

    /** Registra un valor literal que nunca debe aparecer en los logs (contraseñas, tokens conocidos). */
    public function addSecret(?string $secret): self
    {
        if ($secret !== null && \strlen($secret) >= 4 && !\in_array($secret, $this->secrets, true)) {
            $this->secrets[] = $secret;
        }

        return $this;
    }

    public function redact(string $text): string
    {
        $patterns = [
            // Elementos XML cuyo contenido es secreto (con o sin prefijo, también escapados en SOAP).
            '#(<(?:[\w-]+:)?(TOKEN|Token|SignatureValue|X509Certificate|RSASK|pszXml|FirmaDte)\b[^>]*>).*?(</(?:[\w-]+:)?\2>)#s' => '$1'.self::MASK.'$3',
            '#(&lt;(?:[\w-]+:)?(TOKEN|SignatureValue|X509Certificate|RSASK)&gt;).*?(&lt;/(?:[\w-]+:)?\2&gt;)#s' => '$1'.self::MASK.'$3',
            // Semilla firmada (solicitud de token) completa.
            '#<getToken>.*?</getToken>#s' => '<getToken>'.self::MASK.'</getToken>',
            '#&lt;getToken&gt;.*?&lt;/getToken&gt;#s' => '&lt;getToken&gt;'.self::MASK.'&lt;/getToken&gt;',
            // Claves privadas PEM.
            '#-----BEGIN ([A-Z ]*)PRIVATE KEY-----.*?-----END \1PRIVATE KEY-----#s' => '-----BEGIN $1PRIVATE KEY-----'.self::MASK.'-----END $1PRIVATE KEY-----',
            // Cookies y parámetros con el token.
            '#\b(TOKEN|CSESSIONID)=[^;\s&"]+#i' => '$1='.self::MASK,
            '#("token"\s*:\s*")[^"]*(")#i' => '$1'.self::MASK.'$2',
        ];
        $text = (string) preg_replace(array_keys($patterns), array_values($patterns), $text);
        foreach ($this->secrets as $secret) {
            $text = str_replace([$secret, htmlspecialchars($secret, \ENT_QUOTES | \ENT_XML1), rawurlencode($secret)], self::MASK, $text);
        }

        return $text;
    }

    /**
     * Redacta recursivamente el contexto de un mensaje de log.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function redactContext(array $context): array
    {
        foreach ($context as $key => $value) {
            if (\is_string($value)) {
                $context[$key] = preg_match('/password|passphrase|clave|secret|token/i', (string) $key) === 1 ? self::MASK : $this->redact($value);
            } elseif (\is_array($value)) {
                $context[$key] = $this->redactContext($value);
            }
        }

        return $context;
    }
}
