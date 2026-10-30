<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Validator;

use RoyaltyFusion\Sii\Exception\XmlException;
use RoyaltyFusion\Sii\Xml\XmlUtil;

/**
 * Valida XML contra los esquemas XSD oficiales del SII incluidos en resources/xsd.
 */
final class XsdValidator
{
    /** Elemento raíz => esquema. */
    public const SCHEMAS = [
        'DTE' => 'DTE_v10.xsd',
        'EnvioDTE' => 'EnvioDTE_v10.xsd',
        'EnvioBOLETA' => 'EnvioBOLETA_v11.xsd',
        'RespuestaDTE' => 'RespuestaEnvioDTE_v10.xsd',
        'EnvioRecibos' => 'EnvioRecibos_v10.xsd',
        'AEC' => 'AEC_v10.xsd',
        'ConsumoFolios' => 'ConsumoFolio_v10.xsd',
        'LibroCompraVenta' => 'LibroCV_v10.xsd',
        'LibroGuia' => 'LibroGuia_v10.xsd',
    ];

    private readonly string $schemaDir;

    public function __construct(?string $schemaDir = null)
    {
        $this->schemaDir = rtrim($schemaDir ?? \dirname(__DIR__, 2).'/resources/xsd', '/');
    }

    public function getSchemaDir(): string
    {
        return $this->schemaDir;
    }

    /**
     * @param string|null $schema nombre del archivo XSD; si es null se deduce del elemento raíz
     */
    public function validate(string $xml, ?string $schema = null): ValidationResult
    {
        $result = new ValidationResult();
        try {
            $doc = XmlUtil::load($xml);
        } catch (XmlException $e) {
            return $result->error('XML', $e->getMessage(), 'XML_MAL_FORMADO');
        }
        $root = $doc->documentElement?->localName ?? '';
        $schema ??= self::SCHEMAS[$root] ?? null;
        if ($schema === null) {
            return $result->error('XML', sprintf('No hay un esquema XSD para el elemento raíz <%s>.', $root), 'XSD_DESCONOCIDO');
        }
        $path = $this->schemaDir.'/'.$schema;
        if (!is_file($path)) {
            return $result->error('XML', sprintf('No se encontró el esquema %s.', $path), 'XSD_NO_ENCONTRADO');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            if (!$doc->schemaValidate($path)) {
                foreach (libxml_get_errors() as $error) {
                    $result->error(
                        sprintf('XSD línea %d', $error->line),
                        self::translate(trim($error->message)),
                        'XSD_'.$error->code,
                    );
                }
                if ($result->isValid()) {
                    $result->error('XSD', 'El XML no cumple el esquema '.$schema.'.', 'XSD');
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $result;
    }

    private static function translate(string $message): string
    {
        return str_replace('{http://www.sii.cl/SiiDte}', '', $message);
    }
}
