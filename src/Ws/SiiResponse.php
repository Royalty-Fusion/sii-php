<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Ws;

use RoyaltyFusion\Sii\Exception\WebServiceException;
use RoyaltyFusion\Sii\Xml\XmlUtil;

/**
 * Respuesta XML estándar del SII:
 * <SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema"><SII:RESP_HDR>…</SII:RESP_HDR><SII:RESP_BODY>…</SII:RESP_BODY></SII:RESPUESTA>
 */
final class SiiResponse
{
    private function __construct(
        private readonly \DOMDocument $doc,
        private readonly \DOMXPath $xpath,
        public readonly string $raw,
    ) {
    }

    public static function parse(string $xml): self
    {
        try {
            $doc = XmlUtil::load(trim($xml));
        } catch (\Throwable $e) {
            throw new WebServiceException('Respuesta del SII no es un XML válido.', null, $xml, null, $e);
        }

        return new self($doc, new \DOMXPath($doc), $xml);
    }

    /** Valor del primer nodo con ese nombre local dentro de RESP_HDR. */
    public function header(string $name): ?string
    {
        return $this->value(sprintf('//*[local-name()="RESP_HDR"]/*[local-name()="%s"]', $name));
    }

    /** Valor del primer nodo con ese nombre local dentro de RESP_BODY. */
    public function body(string $name): ?string
    {
        return $this->value(sprintf('//*[local-name()="RESP_BODY"]/*[local-name()="%s"]', $name));
    }

    /** Valor del primer nodo con ese nombre local en cualquier parte. */
    public function find(string $name): ?string
    {
        return $this->value(sprintf('//*[local-name()="%s"]', $name));
    }

    /**
     * Hijos de RESP_BODY en orden como pares [nombre, valor].
     *
     * @return list<array{0: string, 1: string}>
     */
    public function bodyChildren(): array
    {
        $out = [];
        $nodes = $this->xpath->query('//*[local-name()="RESP_BODY"]/*');
        foreach ($nodes ?: [] as $node) {
            $out[] = [$node->localName ?? $node->nodeName, trim($node->textContent)];
        }

        return $out;
    }

    public function getEstado(): ?string
    {
        return $this->header('ESTADO');
    }

    public function getGlosa(): ?string
    {
        return $this->header('GLOSA') ?? $this->header('GLOSA_ESTADO');
    }

    public function document(): \DOMDocument
    {
        return $this->doc;
    }

    private function value(string $query): ?string
    {
        $nodes = $this->xpath->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        return trim((string) $nodes->item(0)?->textContent);
    }
}
