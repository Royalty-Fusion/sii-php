<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Caf;

use RoyaltyFusion\Sii\Exception\CafException;
use RoyaltyFusion\Sii\Util\Clock;
use RoyaltyFusion\Sii\Util\Rut;
use RoyaltyFusion\Sii\Util\Text;
use RoyaltyFusion\Sii\Xml\XmlUtil;
use RoyaltyFusion\Sii\Xmldsig\RsaKey;

/**
 * Código de Autorización de Folios (CAF) entregado por el SII.
 *
 * Estructura del archivo:
 * <AUTORIZACION>
 *   <CAF version="1.0"><DA>RE, RS, TD, RNG(D,H), FA, RSAPK(M,E), IDK</DA><FRMA algoritmo="SHA1withRSA">…</FRMA></CAF>
 *   <RSASK>clave privada para timbrar</RSASK>
 *   <RSAPUBK>clave pública</RSAPUBK>
 * </AUTORIZACION>
 */
final class Caf
{
    /** IDK que el SII usa para los CAF del ambiente de certificación (maullin). */
    public const IDK_CERTIFICACION = 100;

    /** Tipos de documento cuyos folios vencen 6 meses después de su autorización. */
    public const TIPOS_CON_VENCIMIENTO = [33, 34, 43, 46, 52, 56, 61];

    private readonly \OpenSSLAsymmetricKey $privateKey;

    private function __construct(
        private readonly string $rutEmisor,
        private readonly string $razonSocial,
        private readonly int $tipoDte,
        private readonly int $folioDesde,
        private readonly int $folioHasta,
        private readonly \DateTimeImmutable $fechaAutorizacion,
        private readonly string $modulus,
        private readonly string $exponent,
        private readonly int $idk,
        private readonly string $cafXml,
        string $privateKeyPem,
        private readonly string $source,
    ) {
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new CafException('La clave privada (RSASK) del CAF no es válida.');
        }
        $this->privateKey = $key;
    }

    public static function fromFile(string $path): self
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new CafException(sprintf('No se pudo leer el CAF "%s".', $path));
        }

        return self::fromXml($content);
    }

    public static function fromXml(string $xml): self
    {
        // Los CAF del SII suelen declarar sólo version="1.0" aunque vengan en ISO-8859-1:
        // se normaliza a UTF-8 sin declaración para que el parser no falle con tildes o eñes.
        $utf8 = mb_check_encoding($xml, 'UTF-8') ? $xml : Text::toUtf8($xml);
        try {
            $doc = XmlUtil::load(XmlUtil::stripDeclaration($utf8));
        } catch (\Throwable $e) {
            throw new CafException('El archivo CAF no es un XML válido: '.$e->getMessage(), 0, $e);
        }
        $xpath = new \DOMXPath($doc);
        $get = static function (string $path) use ($xpath): string {
            $nodes = $xpath->query($path);
            if ($nodes === false || $nodes->length === 0) {
                throw new CafException(sprintf('El CAF no contiene el nodo %s.', $path));
            }

            return trim(XmlUtil::text($nodes->item(0)));
        };

        $tipo = (int) $get('/AUTORIZACION/CAF/DA/TD');
        $desde = (int) $get('/AUTORIZACION/CAF/DA/RNG/D');
        $hasta = (int) $get('/AUTORIZACION/CAF/DA/RNG/H');
        if ($desde <= 0 || $hasta < $desde) {
            throw new CafException(sprintf('Rango de folios inválido en el CAF (%d - %d).', $desde, $hasta));
        }
        try {
            $fa = Clock::date($get('/AUTORIZACION/CAF/DA/FA'));
        } catch (\RoyaltyFusion\Sii\Exception\InvalidArgumentException) {
            throw new CafException('Fecha de autorización (FA) inválida en el CAF.');
        }

        // El nodo CAF debe incluirse en el TED exactamente como lo entregó el SII (sin espacios entre nodos).
        if (!preg_match('#<CAF\b[^>]*>.*</CAF>#s', $utf8, $m)) {
            throw new CafException('No se encontró el nodo <CAF> en el archivo.');
        }
        $cafXml = (string) preg_replace('/>\s+</', '><', trim($m[0]));

        $rut = $get('/AUTORIZACION/CAF/DA/RE');
        if (!Rut::isValid($rut)) {
            throw new CafException(sprintf('RUT emisor "%s" del CAF inválido.', $rut));
        }

        $caf = new self(
            Rut::normalize($rut),
            $get('/AUTORIZACION/CAF/DA/RS'),
            $tipo,
            $desde,
            $hasta,
            $fa,
            $get('/AUTORIZACION/CAF/DA/RSAPK/M'),
            $get('/AUTORIZACION/CAF/DA/RSAPK/E'),
            (int) $get('/AUTORIZACION/CAF/DA/IDK'),
            $cafXml,
            $get('/AUTORIZACION/RSASK'),
            $xml,
        );
        $caf->assertKeyPair();

        return $caf;
    }

    public function getRutEmisor(): string
    {
        return $this->rutEmisor;
    }

    public function getRazonSocial(): string
    {
        return $this->razonSocial;
    }

    public function getTipoDte(): int
    {
        return $this->tipoDte;
    }

    public function getFolioDesde(): int
    {
        return $this->folioDesde;
    }

    public function getFolioHasta(): int
    {
        return $this->folioHasta;
    }

    public function getCantidadFolios(): int
    {
        return $this->folioHasta - $this->folioDesde + 1;
    }

    public function getFechaAutorizacion(): \DateTimeImmutable
    {
        return $this->fechaAutorizacion;
    }

    public function getIdk(): int
    {
        return $this->idk;
    }

    public function isCertificacion(): bool
    {
        return $this->idk === self::IDK_CERTIFICACION;
    }

    public function contains(int $folio): bool
    {
        return $folio >= $this->folioDesde && $folio <= $this->folioHasta;
    }

    /** Fecha de vencimiento de los folios (null si el tipo de documento no vence). */
    public function getFechaVencimiento(): ?\DateTimeImmutable
    {
        if (!\in_array($this->tipoDte, self::TIPOS_CON_VENCIMIENTO, true)) {
            return null;
        }

        return $this->fechaAutorizacion->modify('+6 months');
    }

    public function isVencido(?\DateTimeInterface $at = null): bool
    {
        $vencimiento = $this->getFechaVencimiento();
        if ($vencimiento === null) {
            return false;
        }
        return Clock::today($at) > $vencimiento;
    }

    /** Nodo <CAF> (UTF-8) listo para insertarse en el TED. */
    public function getCafXml(): string
    {
        return $this->cafXml;
    }

    /** Contenido original del archivo de autorización. */
    public function getSource(): string
    {
        return $this->source;
    }

    public function getPublicKey(): \OpenSSLAsymmetricKey
    {
        $key = RsaKey::fromModulusExponent($this->modulus, $this->exponent);
        if ($key === null) {
            throw new CafException('La clave pública (RSAPK) del CAF no es válida.');
        }

        return $key;
    }

    /** Firma el nodo DD del timbre (SHA1withRSA) con la clave privada del CAF. */
    public function sign(string $data): string
    {
        $signature = '';
        if (!openssl_sign($data, $signature, $this->privateKey, \OPENSSL_ALGO_SHA1)) {
            throw new CafException('No se pudo firmar el timbre con la clave del CAF.');
        }

        return $signature;
    }

    public function verify(string $data, string $signature): bool
    {
        return openssl_verify($data, $signature, $this->getPublicKey(), \OPENSSL_ALGO_SHA1) === 1;
    }

    /**
     * Verifica la firma FRMA del SII sobre el nodo DA, usando la clave pública del SII asociada al IDK.
     */
    public function verifySiiSignature(string $siiPublicKeyPem): bool
    {
        if (!preg_match('#<DA>.*</DA>#s', $this->cafXml, $da) || !preg_match('#<FRMA[^>]*>([^<]+)</FRMA>#', $this->cafXml, $frma)) {
            return false;
        }
        $key = openssl_pkey_get_public($siiPublicKeyPem);
        $signature = base64_decode($frma[1], true);
        if ($key === false || $signature === false) {
            return false;
        }

        return openssl_verify(Text::toLatin1($da[0]), $signature, $key, \OPENSSL_ALGO_SHA1) === 1;
    }

    private function assertKeyPair(): void
    {
        $details = openssl_pkey_get_details($this->privateKey);
        $n = base64_decode($this->modulus, true);
        if ($details === false || $n === false || ltrim($details['rsa']['n'] ?? '', "\x00") !== ltrim($n, "\x00")) {
            throw new CafException('La clave privada RSASK del CAF no corresponde a la clave pública RSAPK.');
        }
    }
}
