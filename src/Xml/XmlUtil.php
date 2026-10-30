<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Xml;

use RoyaltyFusion\Sii\Exception\XmlException;

final class XmlUtil
{
    public const NS_SII_DTE = 'http://www.sii.cl/SiiDte';
    public const NS_XSI = 'http://www.w3.org/2001/XMLSchema-instance';
    public const NS_DSIG = 'http://www.w3.org/2000/09/xmldsig#';

    public const LATIN1_HEADER = '<?xml version="1.0" encoding="ISO-8859-1"?>';

    /**
     * Carga un XML en un DOMDocument sin alterar espacios en blanco (necesario para C14N).
     */
    public static function load(string $xml): \DOMDocument
    {
        $doc = new \DOMDocument();
        $doc->preserveWhiteSpace = true;
        $doc->formatOutput = false;
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $ok = $doc->loadXML($xml, \LIBXML_NONET | \LIBXML_PARSEHUGE);
            if (!$ok) {
                throw new XmlException('XML mal formado: '.self::lastErrors());
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $doc;
    }

    public static function xpath(\DOMDocument $doc): \DOMXPath
    {
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('sii', self::NS_SII_DTE);
        $xpath->registerNamespace('ds', self::NS_DSIG);

        return $xpath;
    }

    public static function findById(\DOMDocument $doc, string $id): \DOMElement
    {
        if (!preg_match('/^[A-Za-z_][\w.\-]*$/', $id)) {
            throw new XmlException(sprintf('ID "%s" inválido.', $id));
        }
        $nodes = (new \DOMXPath($doc))->query(sprintf('//*[@ID="%s"]', $id));
        if ($nodes === false || $nodes->length !== 1 || !$nodes->item(0) instanceof \DOMElement) {
            throw new XmlException(sprintf('No se encontró un único nodo con ID="%s".', $id));
        }

        return $nodes->item(0);
    }

    /** Canonicalización C14N inclusiva 20010315 (sin comentarios). */
    public static function c14n(\DOMNode $node): string
    {
        $c14n = $node->C14N(false, false);
        if ($c14n === false) {
            throw new XmlException('No se pudo canonicalizar el nodo XML.');
        }

        return $c14n;
    }

    public static function lastErrors(): string
    {
        $messages = [];
        foreach (libxml_get_errors() as $error) {
            $messages[] = sprintf('línea %d: %s', $error->line, trim($error->message));
        }

        return implode('; ', $messages);
    }

    /** Elimina la declaración XML inicial. */
    public static function stripDeclaration(string $xml): string
    {
        return trim((string) preg_replace('/^\s*<\?xml[^>]*\?>/', '', $xml));
    }
}
