<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Tests\Ws;

/** Respuestas de ejemplo con el formato que entregan los servicios del SII. */
final class SiiResponses
{
    public static function soap(string $method, string $innerXml): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<soapenv:Body><ns1:'.$method.'Response soapenv:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/" xmlns:ns1="http://DefaultNamespace">'
            .'<'.$method.'Return xsi:type="xsd:string">'.htmlspecialchars($innerXml, \ENT_XML1).'</'.$method.'Return>'
            .'</ns1:'.$method.'Response></soapenv:Body></soapenv:Envelope>';
    }

    public static function seed(string $seed = '005938471932'): string
    {
        return self::soap('getSeed', '<?xml version="1.0" encoding="UTF-8"?><SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema"><SII:RESP_BODY><SEMILLA>'.$seed.'</SEMILLA></SII:RESP_BODY><SII:RESP_HDR><ESTADO>00</ESTADO></SII:RESP_HDR></SII:RESPUESTA>');
    }

    public static function token(string $token = 'ABCDEFGHIJKL1'): string
    {
        return self::soap('getToken', '<?xml version="1.0" encoding="UTF-8"?><SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema"><SII:RESP_BODY><TOKEN>'.$token.'</TOKEN></SII:RESP_BODY><SII:RESP_HDR><ESTADO>00</ESTADO><GLOSA>Token Creado</GLOSA></SII:RESP_HDR></SII:RESPUESTA>');
    }

    public static function tokenError(): string
    {
        return self::soap('getToken', '<?xml version="1.0" encoding="UTF-8"?><SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema"><SII:RESP_HDR><ESTADO>10</ESTADO><GLOSA>Error: Certificado Revocado</GLOSA></SII:RESP_HDR></SII:RESPUESTA>');
    }

    public static function upload(string $status = '0', string $trackId = '0123456789'): string
    {
        return "<?xml version=\"1.0\"?>\n<RECEPCIONDTE>\n<RUTSENDER>11111111-1</RUTSENDER>\n<RUTCOMPANY>76192083-9</RUTCOMPANY>\n<FILE>EnvioDTE.xml</FILE>\n<TIMESTAMP>2026-11-02 10:31:00</TIMESTAMP>\n<STATUS>{$status}</STATUS>\n"
            .($status === '0' ? "<TRACKID>{$trackId}</TRACKID>\n" : "<DETAIL><ERROR>Error de prueba</ERROR></DETAIL>\n")
            .'</RECEPCIONDTE>';
    }

    public static function estUp(string $estado = 'EPR'): string
    {
        return self::soap('getEstUp', '<?xml version="1.0" encoding="UTF-8"?><SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema"><SII:RESP_HDR><TRACKID>0123456789</TRACKID><ESTADO>'.$estado.'</ESTADO><GLOSA>Envio Procesado</GLOSA><NUM_ATENCION>123 ( 2026/11/02 10:35:00)</NUM_ATENCION></SII:RESP_HDR>'
            .'<SII:RESP_BODY><TIPO_DOCTO>33</TIPO_DOCTO><INFORMADOS>2</INFORMADOS><ACEPTADOS>2</ACEPTADOS><RECHAZADOS>0</RECHAZADOS><REPAROS>0</REPAROS>'
            .'<TIPO_DOCTO>61</TIPO_DOCTO><INFORMADOS>1</INFORMADOS><ACEPTADOS>0</ACEPTADOS><RECHAZADOS>1</RECHAZADOS><REPAROS>0</REPAROS></SII:RESP_BODY></SII:RESPUESTA>');
    }

    public static function estDte(): string
    {
        return self::soap('getEstDte', '<?xml version="1.0" encoding="UTF-8"?><SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema"><SII:RESP_HDR><ESTADO>DOK</ESTADO><GLOSA_ESTADO>Documento Recibido por el SII. Datos Coinciden con los Registrados</GLOSA_ESTADO><ERR_CODE>2</ERR_CODE><GLOSA_ERR>-</GLOSA_ERR><NUM_ATENCION>456</NUM_ATENCION></SII:RESP_HDR></SII:RESPUESTA>');
    }

    public static function boletaSeed(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema"><SII:RESP_BODY><SEMILLA>030434403134</SEMILLA></SII:RESP_BODY><SII:RESP_HDR><ESTADO>00</ESTADO></SII:RESP_HDR></SII:RESPUESTA>';
    }

    public static function boletaToken(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema"><SII:RESP_BODY><TOKEN>BOLETATOKEN1</TOKEN></SII:RESP_BODY><SII:RESP_HDR><ESTADO>00</ESTADO><GLOSA>Token Creado</GLOSA></SII:RESP_HDR></SII:RESPUESTA>';
    }

    public static function reclamo(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/"><S:Body><ns2:ingresarAceptacionReclamoDocResponse xmlns:ns2="http://ws.registroreclamodte.diii.sdi.sii.cl"><return><codResp>0</codResp><descResp>Accion Completada OK</descResp></return></ns2:ingresarAceptacionReclamoDocResponse></S:Body></S:Envelope>';
    }

    public static function eventos(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/"><S:Body><ns2:listarEventosHistDocResponse xmlns:ns2="http://ws.registroreclamodte.diii.sdi.sii.cl"><return><codResp>15</codResp><descResp>Listado de eventos del documento</descResp>'
            .'<listaEventosDoc><codEvento>ACD</codEvento><descEvento>Acepta Contenido del Documento</descEvento><rutResponsable>96790240</rutResponsable><dvResponsable>3</dvResponsable><fechaEvento>2026-11-03 09:00:00</fechaEvento></listaEventosDoc>'
            .'</return></ns2:listarEventosHistDocResponse></S:Body></S:Envelope>';
    }
}
