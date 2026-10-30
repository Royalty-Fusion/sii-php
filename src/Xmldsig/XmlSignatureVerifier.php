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
        $reference = XmlUtil::first($xpath, 'ds:SignedInfo/ds:Reference', $signature);
        $digestNode = XmlUtil::first($xpath, 'ds:SignedInfo/ds:Reference/ds:DigestValue', $signature);
        $signedInfo = XmlUtil::first($xpath, 'ds:SignedInfo', $signature);
        $valueNode = XmlUtil::first($xpath, 'ds:SignatureValue', $signature);
        $certNode = XmlUtil::first($xpath, 'ds:KeyInfo/ds:X509Data/ds:X509Certificate', $signature);
        $modulusNode = XmlUtil::first($xpath, 'ds:KeyInfo/ds:KeyValue/ds:RSAKeyValue/ds:Modulus', $signature);
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
                $sigs = $copyXpath->query('//ds:Signature');
                $sig = $sigs === false ? null : $sigs->item($index);
                if ($sig instanceof \DOMNode) {
                    $sig->parentNode?->removeChild($sig);
                }
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
            $exponentNode = XmlUtil::first($xpath, 'ds:KeyInfo/ds:KeyValue/ds:RSAKeyValue/ds:Exponent', $signature);
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
        foreach (XmlUtil::elements($xpath, '//ds:Signature') as $node) {
            if ($node->isSameNode($signature)) {
                return $i;
            }
            ++$i;
        }

        return 0;
    }
}
