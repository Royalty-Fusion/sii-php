<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Intercambio;

use RoyaltyFusion\Sii\Exception\XmlException;
use RoyaltyFusion\Sii\Util\Text;
use RoyaltyFusion\Sii\Validator\XsdValidator;
use RoyaltyFusion\Sii\Xml\XmlUtil;
use RoyaltyFusion\Sii\Xmldsig\RsaKey;
use RoyaltyFusion\Sii\Xmldsig\XmlSignatureVerifier;

/**
 * Lee un EnvioDTE recibido de un proveedor: valida el esquema, las firmas (sobre y cada DTE)
 * y el timbre electrónico de cada documento, y extrae sus datos principales.
 */
final class EnvioDteReader
{
    public function __construct(
        private readonly XsdValidator $xsd = new XsdValidator(),
        private readonly XmlSignatureVerifier $verifier = new XmlSignatureVerifier(),
    ) {
    }

    public function read(string $xml): ReceivedEnvelope
    {
        $doc = XmlUtil::load($xml);
        $xpath = XmlUtil::xpath($doc);
        if ($doc->documentElement?->localName !== 'EnvioDTE') {
            throw new XmlException('El archivo no es un EnvioDTE.');
        }
        $schema = $this->xsd->validate($xml, 'EnvioDTE_v10.xsd');

        $checks = [];
        foreach ($this->verifier->verifyAll($xml) as $check) {
            $checks[$check->referenceUri] = $check->isValid();
        }
        $setId = (string) $xpath->evaluate('string(/sii:EnvioDTE/sii:SetDTE/@ID)');
        $car = static fn (string $n): string => trim((string) $xpath->evaluate('string(/sii:EnvioDTE/sii:SetDTE/sii:Caratula/sii:'.$n.')'));

        $utf8 = mb_check_encoding($xml, 'UTF-8') ? $xml : Text::toUtf8($xml);
        preg_match_all('#<DTE[\s>].*?</DTE>#s', $utf8, $raw);

        $documentos = [];
        foreach (XmlUtil::elements($xpath, '/sii:EnvioDTE/sii:SetDTE/sii:DTE') as $i => $dte) {
            $node = XmlUtil::first($xpath, 'sii:Documento|sii:Exportaciones|sii:Liquidacion', $dte);
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $v = static fn (string $path): string => trim((string) $xpath->evaluate('string('.$path.')', $node));
            $id = $node->getAttribute('ID');
            $int = static fn (string $s): ?int => $s === '' ? null : (int) round((float) $s);
            $documentos[] = new ReceivedDte(
                (int) $v('sii:Encabezado/sii:IdDoc/sii:TipoDTE'),
                (int) $v('sii:Encabezado/sii:IdDoc/sii:Folio'),
                self::fecha($v('sii:Encabezado/sii:IdDoc/sii:FchEmis')),
                $v('sii:Encabezado/sii:Emisor/sii:RUTEmisor'),
                $v('sii:Encabezado/sii:Emisor/sii:RznSoc'),
                $v('sii:Encabezado/sii:Receptor/sii:RUTRecep'),
                (int) round((float) $v('sii:Encabezado/sii:Totales/sii:MntTotal')),
                $checks['#'.$id] ?? false,
                $this->verifyTed($xpath, $node, $raw[0][$i] ?? null),
                $raw[0][$i] ?? (string) $doc->saveXML($dte),
                $int($v('sii:Encabezado/sii:Totales/sii:MntNeto')),
                $int($v('sii:Encabezado/sii:Totales/sii:IVA')),
                $int($v('sii:Encabezado/sii:Totales/sii:MntExe')),
            );
        }

        $digest = $xpath->evaluate('string(/sii:EnvioDTE/ds:Signature/ds:SignedInfo/ds:Reference/ds:DigestValue)');

        return new ReceivedEnvelope(
            $setId,
            $car('RutEmisor'),
            $car('RutEnvia'),
            $car('RutReceptor'),
            $car('FchResol') ?: null,
            $car('NroResol') !== '' ? (int) $car('NroResol') : null,
            $car('TmstFirmaEnv') ?: null,
            $checks['#'.$setId] ?? false,
            \is_string($digest) && $digest !== '' ? trim($digest) : null,
            $documentos,
            $schema->isValid(),
            array_map('strval', $schema->getErrors()),
        );
    }

    private static function fecha(string $ymd): \DateTimeImmutable
    {
        try {
            return \RoyaltyFusion\Sii\Util\Clock::date($ymd);
        } catch (\Throwable) {
            return \RoyaltyFusion\Sii\Util\Clock::today();
        }
    }

    /** Verifica FRMT del TED con la clave pública del CAF incluido en el propio timbre. */
    private function verifyTed(\DOMXPath $xpath, \DOMElement $documento, ?string $rawDte): bool
    {
        $dd = XmlUtil::first($xpath, 'sii:TED/sii:DD', $documento);
        $frmt = trim((string) $xpath->evaluate('string(sii:TED/sii:FRMT)', $documento));
        $m = trim((string) $xpath->evaluate('string(sii:TED/sii:DD/sii:CAF/sii:DA/sii:RSAPK/sii:M)', $documento));
        $e = trim((string) $xpath->evaluate('string(sii:TED/sii:DD/sii:CAF/sii:DA/sii:RSAPK/sii:E)', $documento));
        $signature = base64_decode($frmt, true);
        $key = RsaKey::fromModulusExponent($m, $e);
        if (!$dd instanceof \DOMElement || $signature === false || $key === null) {
            return false;
        }
        $candidates = [];
        if ($rawDte !== null && preg_match('#<DD>.*?</DD>#s', $rawDte, $match)) {
            $candidates[] = (string) preg_replace('/>\s+</', '><', $match[0]);
        }
        // Alternativa: el DD reconstruido (sin namespaces heredados ni espacios entre nodos).
        $candidates[] = self::serialize($dd);
        foreach ($candidates as $flat) {
            if (openssl_verify(Text::toLatin1($flat), $signature, $key, \OPENSSL_ALGO_SHA1) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function serialize(\DOMNode $node): string
    {
        if ($node instanceof \DOMText) {
            return trim($node->data) === '' ? '' : Text::escape($node->data);
        }
        if (!$node instanceof \DOMElement) {
            return '';
        }
        $out = '<'.$node->localName;
        foreach ($node->attributes ?? [] as $attr) {
            $out .= ' '.$attr->name.'="'.Text::escape($attr->value).'"';
        }
        $out .= '>';
        foreach ($node->childNodes as $child) {
            $out .= self::serialize($child);
        }

        return $out.'</'.$node->localName.'>';
    }
}
