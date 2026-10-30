<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Xmldsig;

use RoyaltyFusion\Sii\Certificate\Certificate;
use RoyaltyFusion\Sii\Exception\SignatureException;
use RoyaltyFusion\Sii\Xml\XmlUtil;

/**
 * Firma XMLDSig según el esquema xmldsignature_v10.xsd del SII:
 * C14N 20010315, RSA-SHA1, SHA1, KeyInfo con RSAKeyValue y X509Certificate.
 *
 * El XML firmado se manipula como string para no alterar ningún byte del contenido firmado
 * (DOMDocument sólo se usa para calcular la canonicalización).
 */
final class XmlSigner
{
    public const C14N = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';
    public const RSA_SHA1 = 'http://www.w3.org/2000/09/xmldsig#rsa-sha1';
    public const SHA1 = 'http://www.w3.org/2000/09/xmldsig#sha1';
    public const ENVELOPED = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';

    private const PLACEHOLDER = '@@SII_SIGNATURE_VALUE@@';

    public function __construct(private readonly Certificate $certificate)
    {
    }

    /**
     * Firma el nodo con atributo ID="$referenceId" e inserta la firma como último hijo del elemento raíz
     * (DTE → firma sobre Documento; EnvioDTE/EnvioBOLETA → firma sobre SetDTE).
     */
    public function signById(string $xml, string $referenceId): string
    {
        $doc = XmlUtil::load($xml);
        $digest = base64_encode(sha1(XmlUtil::c14n(XmlUtil::findById($doc, $referenceId)), true));

        return $this->insertSignature($xml, $doc, '#'.$referenceId, $digest, false);
    }

    /**
     * Firma el documento completo (Reference URI="" + enveloped-signature), usado para getToken.
     */
    public function signEnveloped(string $xml): string
    {
        $doc = XmlUtil::load($xml);
        if ($doc->documentElement === null) {
            throw new SignatureException('XML sin elemento raíz.');
        }
        $digest = base64_encode(sha1(XmlUtil::c14n($doc->documentElement), true));

        return $this->insertSignature($xml, $doc, '', $digest, true);
    }

    private function insertSignature(string $xml, \DOMDocument $doc, string $uri, string $digest, bool $enveloped): string
    {
        $root = $doc->documentElement;
        if ($root === null) {
            throw new SignatureException('XML sin elemento raíz.');
        }
        $closing = '</'.$root->tagName.'>';
        $pos = strrpos($xml, $closing);
        if ($pos === false) {
            throw new SignatureException(sprintf('No se encontró el cierre "%s" para insertar la firma.', $closing));
        }

        $transforms = $enveloped
            ? '<Transforms><Transform Algorithm="'.self::ENVELOPED.'"/></Transforms>'
            : '';
        $signedInfo = '<SignedInfo>'
            .'<CanonicalizationMethod Algorithm="'.self::C14N.'"/>'
            .'<SignatureMethod Algorithm="'.self::RSA_SHA1.'"/>'
            .'<Reference URI="'.$uri.'">'.$transforms
            .'<DigestMethod Algorithm="'.self::SHA1.'"/>'
            .'<DigestValue>'.$digest.'</DigestValue>'
            .'</Reference>'
            .'</SignedInfo>';

        $signature = '<Signature xmlns="'.XmlUtil::NS_DSIG.'">'.$signedInfo
            .'<SignatureValue>'.self::PLACEHOLDER.'</SignatureValue>'
            .'<KeyInfo><KeyValue><RSAKeyValue>'
            .'<Modulus>'.self::wrap($this->certificate->getModulus()).'</Modulus>'
            .'<Exponent>'.$this->certificate->getExponent().'</Exponent>'
            .'</RSAKeyValue></KeyValue>'
            .'<X509Data><X509Certificate>'.self::wrap($this->certificate->getCertificateBase64()).'</X509Certificate></X509Data>'
            .'</KeyInfo></Signature>';

        $signedXml = substr($xml, 0, $pos).$signature.substr($xml, $pos);

        // La canonicalización de SignedInfo depende de los namespaces heredados: se calcula en su contexto final.
        $signedDoc = XmlUtil::load($signedXml);
        $nodes = XmlUtil::xpath($signedDoc)->query('/*/ds:Signature[last()]/ds:SignedInfo');
        if ($nodes === false || !$nodes->item(0) instanceof \DOMNode) {
            throw new SignatureException('No se pudo ubicar SignedInfo en el XML firmado.');
        }
        $signatureValue = base64_encode($this->certificate->sign(XmlUtil::c14n($nodes->item(0))));

        return str_replace(self::PLACEHOLDER, self::wrap($signatureValue), $signedXml);
    }

    private static function wrap(string $base64): string
    {
        return rtrim(chunk_split($base64, 76, "\n"), "\n");
    }
}
