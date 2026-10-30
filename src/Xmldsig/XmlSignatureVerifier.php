<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Xmldsig;

use RoyaltyFusion\Sii\Xml\XmlUtil;

/**
 * Verifica las firmas XMLDSig de un XML (DTE, EnvioDTE, EnvioBOLETA, respuestas de intercambio).
 */
final class XmlSignatureVerifier
{
    /**
     * Verifica todas las firmas del documento.
     *
     * @return list<SignatureCheck>
     */
    public function verifyAll(string $xml): array
    {
        $doc = XmlUtil::load($xml);
        $xpath = XmlUtil::xpath($doc);
        $results = [];
        $signatures = $xpath->query('//ds:Signature');
        if ($signatures === false) {
            return [];
        }
        foreach ($signatures as $signature) {
            if ($signature instanceof \DOMElement) {
                $results[] = $this->verifySignature($doc, $xpath, $signature);
            }
        }

        return $results;
    }

    public function isValid(string $xml): bool
    {
        $checks = $this->verifyAll($xml);
        if ($checks === []) {
            return false;
        }
        foreach ($checks as $check) {
            if (!$check->isValid()) {
                return false;
            }
        }

        return true;
    }

    private function verifySignature(\DOMDocument $doc, \DOMXPath $xpath, \DOMElement $signature): SignatureCheck
    {
        $reference = $xpath->query('ds:SignedInfo/ds:Reference', $signature)?->item(0);
        $digestNode = $xpath->query('ds:SignedInfo/ds:Reference/ds:DigestValue', $signature)?->item(0);
        $signedInfo = $xpath->query('ds:SignedInfo', $signature)?->item(0);
        $valueNode = $xpath->query('ds:SignatureValue', $signature)?->item(0);
        $certNode = $xpath->query('ds:KeyInfo/ds:X509Data/ds:X509Certificate', $signature)?->item(0);
        $modulusNode = $xpath->query('ds:KeyInfo/ds:KeyValue/ds:RSAKeyValue/ds:Modulus', $signature)?->item(0);
        if (!$reference instanceof \DOMElement || $digestNode === null || $signedInfo === null || $valueNode === null) {
            return new SignatureCheck('', false, false, 'Firma incompleta.');
        }

        $uri = $reference->getAttribute('URI');
        try {
            if ($uri === '') {
                $copy = new \DOMDocument();
                $copy->preserveWhiteSpace = true;
                $copy->loadXML((string) $doc->saveXML());
                $copyXpath = XmlUtil::xpath($copy);
                // Transformación enveloped-signature: se quita la firma evaluada.
                $index = $this->indexOf($xpath, $signature);
                $sig = $copyXpath->query('//ds:Signature')?->item($index);
                $sig?->parentNode?->removeChild($sig);
                $target = $copy->documentElement;
            } else {
                $target = XmlUtil::findById($doc, ltrim($uri, '#'));
            }
            if ($target === null) {
                return new SignatureCheck($uri, false, false, 'Referencia no encontrada.');
            }
            $digest = base64_encode(sha1(XmlUtil::c14n($target), true));
        } catch (\Throwable $e) {
            return new SignatureCheck($uri, false, false, $e->getMessage());
        }
        $digestOk = hash_equals(trim($digestNode->textContent), $digest);

        $publicKey = null;
        if ($certNode !== null) {
            $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split((string) preg_replace('/\s+/', '', $certNode->textContent), 64, "\n")."-----END CERTIFICATE-----\n";
            $publicKey = openssl_pkey_get_public($pem) ?: null;
        }
        if ($publicKey === null && $modulusNode !== null) {
            $exponentNode = $xpath->query('ds:KeyInfo/ds:KeyValue/ds:RSAKeyValue/ds:Exponent', $signature)?->item(0);
            $publicKey = RsaKey::fromModulusExponent($modulusNode->textContent, $exponentNode?->textContent ?? 'AQAB');
        }
        if ($publicKey === null) {
            return new SignatureCheck($uri, $digestOk, false, 'Sin clave pública en KeyInfo.');
        }
        $value = base64_decode((string) preg_replace('/\s+/', '', $valueNode->textContent), true);
        $signatureOk = $value !== false && openssl_verify(XmlUtil::c14n($signedInfo), $value, $publicKey, \OPENSSL_ALGO_SHA1) === 1;

        return new SignatureCheck($uri, $digestOk, $signatureOk, $digestOk ? ($signatureOk ? null : 'SignatureValue inválido.') : 'DigestValue no coincide.');
    }

    private function indexOf(\DOMXPath $xpath, \DOMElement $signature): int
    {
        $i = 0;
        foreach ($xpath->query('//ds:Signature') ?: [] as $node) {
            if ($node->isSameNode($signature)) {
                return $i;
            }
            ++$i;
        }

        return 0;
    }
}
