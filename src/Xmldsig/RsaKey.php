<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Xmldsig;

/**
 * Construye una clave pública RSA a partir de módulo y exponente en base64
 * (nodos RSAKeyValue de XMLDSig y RSAPK del CAF).
 */
final class RsaKey
{
    public static function fromModulusExponent(string $modulusBase64, string $exponentBase64): ?\OpenSSLAsymmetricKey
    {
        $pem = self::toPem($modulusBase64, $exponentBase64);

        return $pem === null ? null : (openssl_pkey_get_public($pem) ?: null);
    }

    public static function toPem(string $modulusBase64, string $exponentBase64): ?string
    {
        $n = base64_decode((string) preg_replace('/\s+/', '', $modulusBase64), true);
        $e = base64_decode((string) preg_replace('/\s+/', '', $exponentBase64), true);
        if ($n === false || $e === false || $n === '' || $e === '') {
            return null;
        }
        $rsaPublicKey = self::sequence(self::integer($n).self::integer($e));
        // AlgorithmIdentifier rsaEncryption (1.2.840.113549.1.1.1) + NULL
        $algorithm = self::sequence("\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01\x05\x00");
        $spki = self::sequence($algorithm.self::tlv("\x03", "\x00".$rsaPublicKey));

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private static function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || \ord($bytes[0]) > 0x7F) {
            $bytes = "\x00".$bytes;
        }

        return self::tlv("\x02", $bytes);
    }

    private static function sequence(string $content): string
    {
        return self::tlv("\x30", $content);
    }

    private static function tlv(string $tag, string $content): string
    {
        $len = \strlen($content);
        if ($len < 0x80) {
            return $tag.\chr($len).$content;
        }
        $lenBytes = ltrim(pack('N', $len), "\x00");

        return $tag.\chr(0x80 | \strlen($lenBytes)).$lenBytes.$content;
    }
}
