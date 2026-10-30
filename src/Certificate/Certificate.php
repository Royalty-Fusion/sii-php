<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Certificate;

use RoyaltyFusion\Sii\Exception\CertificateException;
use RoyaltyFusion\Sii\Util\Rut;

/**
 * Certificado digital (firma electrónica) usado para firmar los DTE, el sobre de envío y la solicitud de token.
 *
 * Normalmente se carga desde el archivo .pfx / .p12 entregado por la entidad certificadora
 * (E-Sign, Acepta, E-CertChile, etc.).
 */
final class Certificate
{
    /** OID del RUT del titular en el subjectAltName (otherName) de los certificados chilenos. */
    public const RUT_OID = '1.3.6.1.4.1.8321.1';

    private readonly \OpenSSLAsymmetricKey $privateKey;

    /** @var array<string, mixed> */
    private readonly array $parsed;

    private readonly string $certificatePem;

    private ?string $rut = null;

    private function __construct(string $certificatePem, \OpenSSLAsymmetricKey $privateKey)
    {
        $parsed = openssl_x509_parse($certificatePem);
        if ($parsed === false) {
            throw new CertificateException('No se pudo interpretar el certificado X.509: '.self::opensslErrors());
        }
        if (!openssl_x509_check_private_key($certificatePem, $privateKey)) {
            throw new CertificateException('La clave privada no corresponde al certificado.');
        }
        $details = openssl_pkey_get_details($privateKey);
        if ($details === false || ($details['type'] ?? null) !== \OPENSSL_KEYTYPE_RSA) {
            throw new CertificateException('El SII sólo admite certificados con clave RSA.');
        }

        $this->certificatePem = $certificatePem;
        $this->privateKey = $privateKey;
        $this->parsed = $parsed;
    }

    /**
     * Carga un certificado desde el contenido binario de un archivo PKCS#12 (.pfx / .p12).
     *
     * Si OpenSSL 3 rechaza el archivo por usar algoritmos heredados (RC2-40, 3DES),
     * se reintenta con el binario `openssl` y la opción `-legacy`.
     */
    public static function fromPkcs12(string $content, string $password): self
    {
        $certs = [];
        if (@openssl_pkcs12_read($content, $certs, $password)) {
            return self::fromPkcs12Result($certs);
        }
        $error = self::opensslErrors();

        $pem = self::readPkcs12WithCli($content, $password);
        if ($pem === null) {
            throw new CertificateException('No se pudo leer el archivo PKCS#12 (¿contraseña incorrecta?). '.$error);
        }

        return self::fromPem($pem, $pem);
    }

    public static function fromFile(string $path, string $password): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new CertificateException(sprintf('No existe o no se puede leer el certificado "%s".', $path));
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new CertificateException(sprintf('No se pudo leer el certificado "%s".', $path));
        }
        if (str_contains($content, '-----BEGIN')) {
            return self::fromPem($content, $content, $password);
        }

        return self::fromPkcs12($content, $password);
    }

    /**
     * @param string      $certificatePem certificado en formato PEM
     * @param string      $privateKeyPem  clave privada en formato PEM (puede ser el mismo string)
     * @param string|null $passphrase     contraseña de la clave privada, si está cifrada
     */
    public static function fromPem(string $certificatePem, string $privateKeyPem, ?string $passphrase = null): self
    {
        if (!preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $certificatePem, $m)) {
            throw new CertificateException('No se encontró un certificado PEM.');
        }
        $key = openssl_pkey_get_private($privateKeyPem, $passphrase ?? '');
        if ($key === false) {
            throw new CertificateException('No se pudo leer la clave privada: '.self::opensslErrors());
        }

        // Si el PEM trae la cadena completa, se usa el certificado que corresponde a la clave privada.
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $certificatePem, $all);
        foreach ($all[0] as $candidate) {
            if (openssl_x509_check_private_key($candidate, $key)) {
                return new self($candidate."\n", $key);
            }
        }

        return new self($m[0]."\n", $key);
    }

    public function getPrivateKey(): \OpenSSLAsymmetricKey
    {
        return $this->privateKey;
    }

    public function getCertificatePem(): string
    {
        return $this->certificatePem;
    }

    /** Certificado DER codificado en base64, sin encabezados ni saltos de línea. */
    public function getCertificateBase64(): string
    {
        return (string) preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $this->certificatePem);
    }

    /** Módulo RSA (base64) para el nodo RSAKeyValue/Modulus. */
    public function getModulus(): string
    {
        return base64_encode($this->rsaDetail('n'));
    }

    /** Exponente RSA (base64) para el nodo RSAKeyValue/Exponent. */
    public function getExponent(): string
    {
        return base64_encode($this->rsaDetail('e'));
    }

    /** Firma $data con RSA-SHA1 (algoritmo exigido por el SII). Retorna la firma binaria. */
    public function sign(string $data, int $algorithm = \OPENSSL_ALGO_SHA1): string
    {
        $signature = '';
        if (!openssl_sign($data, $signature, $this->privateKey, $algorithm)) {
            throw new CertificateException('Error al firmar con el certificado: '.self::opensslErrors());
        }

        return $signature;
    }

    /**
     * RUT del titular del certificado (RutEnvia en la carátula del envío).
     *
     * Se obtiene del subjectAltName (otherName OID 1.3.6.1.4.1.8321.1) y, en su defecto,
     * del serialNumber del sujeto.
     */
    public function getRut(): ?string
    {
        if ($this->rut !== null) {
            return $this->rut;
        }

        $candidates = [];
        $san = $this->parsed['extensions']['subjectAltName'] ?? null;
        if (\is_string($san)) {
            $candidates[] = $san;
        }
        $der = base64_decode($this->getCertificateBase64(), true);
        if ($der !== false) {
            // OID 1.3.6.1.4.1.8321.1 codificado en DER.
            $oid = "\x06\x08\x2B\x06\x01\x04\x01\xC1\x01\x01";
            $pos = strpos($der, $oid);
            if ($pos !== false) {
                $candidates[] = substr($der, $pos + \strlen($oid), 40);
            }
        }
        $subject = $this->parsed['subject'] ?? [];
        if (\is_array($subject) && isset($subject['serialNumber'])) {
            $candidates[] = \is_array($subject['serialNumber']) ? implode(' ', $subject['serialNumber']) : (string) $subject['serialNumber'];
        }

        foreach ($candidates as $candidate) {
            if (preg_match_all('/(\d{1,2}\.?\d{3}\.?\d{3}-[\dkK])/', $candidate, $m)) {
                foreach ($m[1] as $value) {
                    if (Rut::isValid($value)) {
                        return $this->rut = Rut::normalize($value);
                    }
                }
            }
        }

        return null;
    }

    public function getName(): string
    {
        $subject = $this->parsed['subject'] ?? [];
        $cn = \is_array($subject) ? ($subject['CN'] ?? '') : '';

        return \is_array($cn) ? implode(' ', $cn) : (string) $cn;
    }

    public function getEmail(): ?string
    {
        $subject = $this->parsed['subject'] ?? [];
        $email = \is_array($subject) ? ($subject['emailAddress'] ?? null) : null;
        if ($email === null) {
            $san = $this->parsed['extensions']['subjectAltName'] ?? '';
            if (\is_string($san) && preg_match('/email:([^\s,]+)/', $san, $m)) {
                $email = $m[1];
            }
        }

        return \is_array($email) ? (string) reset($email) : $email;
    }

    public function getIssuer(): string
    {
        $issuer = $this->parsed['issuer'] ?? [];
        $cn = \is_array($issuer) ? ($issuer['CN'] ?? ($issuer['O'] ?? '')) : '';

        return \is_array($cn) ? implode(' ', $cn) : (string) $cn;
    }

    public function getValidFrom(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->setTimestamp((int) $this->parsed['validFrom_time_t']);
    }

    public function getValidTo(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->setTimestamp((int) $this->parsed['validTo_time_t']);
    }

    public function isValidAt(?\DateTimeInterface $at = null): bool
    {
        $ts = ($at ?? new \DateTimeImmutable())->getTimestamp();

        return $ts >= (int) $this->parsed['validFrom_time_t'] && $ts <= (int) $this->parsed['validTo_time_t'];
    }

    /** Días restantes de vigencia (negativo si está vencido). */
    public function getDaysToExpire(?\DateTimeInterface $at = null): int
    {
        $ts = ($at ?? new \DateTimeImmutable())->getTimestamp();

        return (int) floor(((int) $this->parsed['validTo_time_t'] - $ts) / 86400);
    }

    private function rsaDetail(string $key): string
    {
        $details = openssl_pkey_get_details($this->privateKey);
        if ($details === false || !isset($details['rsa'][$key])) {
            throw new CertificateException('No se pudo obtener la clave pública RSA.');
        }

        return $details['rsa'][$key];
    }

    /**
     * @param array<string, mixed> $certs
     */
    private static function fromPkcs12Result(array $certs): self
    {
        $cert = $certs['cert'] ?? null;
        $key = $certs['pkey'] ?? null;
        if (!\is_string($cert) || !\is_string($key)) {
            throw new CertificateException('El archivo PKCS#12 no contiene certificado y clave privada.');
        }

        return self::fromPem($cert, $key);
    }

    private static function readPkcs12WithCli(string $content, string $password): ?string
    {
        if (!\function_exists('proc_open')) {
            return null;
        }
        $binary = getenv('SII_OPENSSL_BIN') ?: 'openssl';
        $tmp = tempnam(sys_get_temp_dir(), 'sii_p12_');
        if ($tmp === false) {
            return null;
        }
        try {
            file_put_contents($tmp, $content);
            chmod($tmp, 0600);
            foreach ([['-legacy'], []] as $extra) {
                $cmd = array_merge([$binary, 'pkcs12', '-in', $tmp, '-nodes', '-passin', 'env:SII_P12_PASSWORD'], $extra);
                $env = getenv();
                $env['SII_P12_PASSWORD'] = $password;
                $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
                if (!\is_resource($proc)) {
                    return null;
                }
                $out = stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                if (proc_close($proc) === 0 && \is_string($out) && str_contains($out, 'PRIVATE KEY')) {
                    return $out;
                }
            }

            return null;
        } finally {
            @unlink($tmp);
        }
    }

    private static function opensslErrors(): string
    {
        $errors = [];
        while (($e = openssl_error_string()) !== false) {
            $errors[] = $e;
        }

        return implode('; ', $errors);
    }
}
