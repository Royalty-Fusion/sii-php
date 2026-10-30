<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Ws;

use RoyaltyFusion\Sii\Exception\WebServiceException;
use RoyaltyFusion\Sii\Xml\XmlUtil;

/**
 * Cliente SOAP 1.1 liviano (sin ext-soap) para los servicios del SII.
 *
 * - Servicios JWS de Axis (CrSeed, GetTokenFromSeed, QueryEstUp, QueryEstDte): RPC/encoded,
 *   cuya respuesta es un string con un XML embebido (getXxxReturn).
 * - Servicios JAX-WS (registro de reclamos): document/literal.
 */
final class SoapClient
{
    public const NS_ENVELOPE = 'http://schemas.xmlsoap.org/soap/envelope/';
    public const NS_RPC = 'http://DefaultNamespace';

    public function __construct(private readonly HttpTransport $http)
    {
    }

    /**
     * Invoca un método RPC/encoded y retorna el string contenido en el elemento de retorno.
     *
     * @param array<string, string|int> $params
     */
    public function callRpc(string $url, string $method, array $params = [], ?string $token = null): string
    {
        $body = '';
        foreach ($params as $name => $value) {
            $body .= '<'.$name.' xsi:type="xsd:string">'.htmlspecialchars((string) $value, \ENT_XML1 | \ENT_QUOTES, 'UTF-8').'</'.$name.'>';
        }
        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soapenv:Envelope xmlns:soapenv="'.self::NS_ENVELOPE.'" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<soapenv:Body><ns1:'.$method.' soapenv:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/" xmlns:ns1="'.self::NS_RPC.'">'
            .$body
            .'</ns1:'.$method.'></soapenv:Body></soapenv:Envelope>';

        $doc = $this->post($url, $envelope, $token);
        $return = XmlUtil::first(new \DOMXPath($doc), '//*[local-name()="Body"]/*[1]/*[1]');
        if ($return === null) {
            throw new WebServiceException(sprintf('Respuesta SOAP de %s sin valor de retorno.', $method), null, $doc->saveXML() ?: null);
        }

        return trim($return->textContent);
    }

    /**
     * Invoca un método document/literal y retorna el elemento de respuesta.
     *
     * @param array<string, string|int> $params
     */
    public function callDocument(string $url, string $namespace, string $method, array $params = [], ?string $token = null): \DOMElement
    {
        $body = '';
        foreach ($params as $name => $value) {
            $body .= '<'.$name.'>'.htmlspecialchars((string) $value, \ENT_XML1 | \ENT_QUOTES, 'UTF-8').'</'.$name.'>';
        }
        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soapenv:Envelope xmlns:soapenv="'.self::NS_ENVELOPE.'" xmlns:ws="'.$namespace.'">'
            .'<soapenv:Header/><soapenv:Body><ws:'.$method.'>'.$body.'</ws:'.$method.'></soapenv:Body></soapenv:Envelope>';

        $doc = $this->post($url, $envelope, $token);
        $nodes = (new \DOMXPath($doc))->query('//*[local-name()="Body"]/*[1]');
        $response = $nodes === false ? null : $nodes->item(0);
        if (!$response instanceof \DOMElement) {
            throw new WebServiceException(sprintf('Respuesta SOAP de %s vacía.', $method), null, $doc->saveXML() ?: null);
        }

        return $response;
    }

    private function post(string $url, string $envelope, ?string $token): \DOMDocument
    {
        $headers = [
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '""',
            'Accept' => 'text/xml',
        ];
        if ($token !== null) {
            $headers['Cookie'] = 'TOKEN='.$token;
        }
        $response = $this->http->request('POST', $url, ['headers' => $headers, 'body' => $envelope]);
        try {
            $doc = XmlUtil::load($response->body);
        } catch (\Throwable $e) {
            throw new WebServiceException(sprintf('Respuesta no válida del SII (HTTP %d) en %s.', $response->status, $url), null, $response->body, $response->status, $e);
        }
        $fault = (new \DOMXPath($doc))->query('//*[local-name()="Fault"]');
        if ($fault !== false && $fault->length > 0) {
            $xpath = new \DOMXPath($doc);
            $message = $xpath->evaluate('string(//*[local-name()="Fault"]/*[local-name()="faultstring"])');
            throw new WebServiceException('SOAP Fault del SII: '.trim((string) $message), null, $response->body, $response->status);
        }
        if (!$response->isSuccessful()) {
            throw new WebServiceException(sprintf('El SII respondió HTTP %d en %s.', $response->status, $url), null, $response->body, $response->status);
        }

        return $doc;
    }
}
