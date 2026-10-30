# sii-php — SDK de Facturación Electrónica para el SII de Chile

SDK PHP para emitir, validar, firmar y enviar Documentos Tributarios Electrónicos (DTE) al
**Servicio de Impuestos Internos (SII) de Chile**, conectándose directamente a sus servicios, sin
intermediarios. Su diseño sigue el estilo de [Greenter](https://github.com/thegreenter/greenter)
(el SDK de SUNAT Perú): modelos con setters encadenables, una fachada `See` y resultados tipados.

- PHP **8.3+**, compatible con **Symfony 7.4** (incluye bundle).
- No requiere `ext-soap`: los servicios SOAP del SII se consumen con un cliente liviano sobre
  `symfony/http-client`.
- Incluye los **esquemas XSD oficiales del SII** (`resources/xsd`) y valida todo XML generado antes de enviarlo.

## Documentos soportados

| Código | Documento | XML |
|---|---|---|
| 33 | Factura Electrónica | `DTE/Documento` |
| 34 | Factura No Afecta o Exenta Electrónica | `DTE/Documento` |
| 39 | Boleta Electrónica | `EnvioBOLETA` |
| 41 | Boleta Exenta Electrónica | `EnvioBOLETA` |
| 46 | Factura de Compra Electrónica | `DTE/Documento` |
| 52 | Guía de Despacho Electrónica | `DTE/Documento` |
| 56 | Nota de Débito Electrónica | `DTE/Documento` |
| 61 | Nota de Crédito Electrónica | `DTE/Documento` |
| 110 | Factura de Exportación Electrónica | `DTE/Exportaciones` |
| 111 | Nota de Débito de Exportación Electrónica | `DTE/Exportaciones` |
| 112 | Nota de Crédito de Exportación Electrónica | `DTE/Exportaciones` |

## Módulos

| Módulo | Clases principales | Qué hace |
|---|---|---|
| Certificado digital | `Certificate\Certificate` | Lee `.pfx`/`.p12` (también los cifrados con RC2-40/3DES vía `openssl -legacy`), obtiene RUT del titular, módulo/exponente, vigencia. |
| CAF y timbre | `Caf\Caf`, `Caf\InMemoryCafRepository`, `Xml\Builder\TedBuilder` | Parsea el CAF, verifica que la clave RSASK corresponda a RSAPK, rango, vencimiento (6 meses), ambiente (IDK 100) y estampa el TED (firma SHA1withRSA del DD). |
| Folios | `Folio\FolioManager`, `Folio\FileFolioStore`, `Folio\SiiFolioService` | Asignación atómica del siguiente folio, consulta de disponibles y vencimientos, y solicitud/reobtención de CAF en el sitio del SII (experimental). |
| Modelos | `Model\Dte`, `Model\Boleta`, `Model\Company`, `Model\Client`, `Model\Detail`, … | Representan el documento con la terminología del SII. |
| Cálculo | `Calculator\TotalsCalculator` | Calcula MontoItem, descuentos/recargos, neto, exento, IVA, impuestos adicionales y retenciones, montos brutos, otra moneda. |
| Validación | `Validator\DocumentValidator`, `Validator\XsdValidator` | Reglas de negocio del formato DTE + validación contra el XSD oficial. |
| Firma | `Xmldsig\XmlSigner`, `Xmldsig\XmlSignatureVerifier` | XMLDSig (C14N, RSA-SHA1, KeyValue + X509Certificate) del DTE, del sobre y de la semilla. |
| Emisión | `Emitter`, `See` | Valida → calcula → timbra → firma → valida XSD → verifica firma → arma sobre. |
| Web services | `Ws\DteService`, `Ws\BoletaService`, `Ws\RegistroReclamoService` | Semilla/token, upload `EnvioDTE`, `QueryEstUp`, `QueryEstDte`, `QueryEstDteAv`, API REST de boletas, aceptación/reclamo de DTE. |
| Intercambio | `Intercambio\EnvioDteReader`, `Intercambio\ResponseBuilder` | Lee DTE de proveedores (verifica firma y timbre) y genera `RespuestaDTE` (recepción y resultado comercial) y `EnvioRecibos` (Ley 19.983). |
| Representación impresa | `Report\HtmlReport`, `Report\TimbreGenerator` | HTML del documento con el timbre PDF417 (nivel de corrección 5). |
| Symfony | `Bridge\Symfony\SiiBundle`, `SeeFactory` | Configuración por YAML, caché de tokens en un pool PSR-6, soporte multiempresa. |

## Instalación

```bash
composer require royalty-fusion/sii-php
```

Para el timbre PDF417 de la representación impresa:

```bash
composer require tecnickcom/tc-lib-barcode
```

Extensiones requeridas: `openssl`, `dom`, `libxml`, `mbstring`. Las fechas deben manejarse en hora de Chile
(`date_default_timezone_set('America/Santiago')` o `date.timezone = America/Santiago` en `php.ini`).

## Uso rápido

```php
use RoyaltyFusion\Sii\Certificate\Certificate;
use RoyaltyFusion\Sii\Environment;
use RoyaltyFusion\Sii\Model\{Client, Company, Detail, Dte, Resolution};
use RoyaltyFusion\Sii\See;

$see = new See(
    Certificate::fromFile('/ruta/firma.pfx', 'clave'),
    Environment::CERTIFICACION,                 // o Environment::PRODUCCION
    new Resolution('2024-01-15', 0),            // fecha y número de resolución (0 en certificación)
);
$see->addCaf('/ruta/caf/FoliosSII76123456733.xml');
$see->setCompany((new Company())
    ->setRut('76123456-7')
    ->setRazonSocial('Mi Empresa SpA')
    ->setGiro('Venta de software')
    ->setActecos([620200])
    ->setDireccion('Av. Siempre Viva 123')
    ->setComuna('Santiago')
    ->setCiudad('Santiago'));

$factura = Dte::factura()
    ->setFolio(1)
    ->setFechaEmision(new DateTimeImmutable())
    ->setReceptor((new Client())
        ->setRut('96.790.240-3')
        ->setRazonSocial('Cliente S.A.')
        ->setGiro('Comercio')
        ->setDireccion('Calle 1')
        ->setComuna('Ñuñoa'))
    ->addDetalle(Detail::create('Notebook', 2, 450000, 'UN')->setDescuentoPorcentaje(5))
    ->addDetalle(Detail::create('Instalación', 1, 35000)->setExento());

$result = $see->send($factura);           // valida, timbra, firma, arma el sobre y lo sube
if ($result->isSuccess()) {
    $trackId = $result->getTrackId();
    $xmlParaGuardar = $result->getXml();  // sobre EnvioDTE firmado (ISO-8859-1)
} else {
    echo $result->message;                // ej. "Esquema inválido", "No está autenticado"
}

$estado = $see->getStatus($trackId);      // QueryEstUp
$estado->isFinal();                       // EPR, RSC, RFR, RCT, …
$estado->isAccepted();                    // procesado y sin documentos rechazados
$estado->getTotal('rechazados');
```

### Validar antes de emitir

`send()` y `prepare()` **nunca envían un documento con errores**: si alguna validación falla lanzan
`ValidationException` sin comunicarse con el SII. También puede validar explícitamente:

```php
$resultado = $see->validate($factura);
if (!$resultado->isValid()) {
    foreach ($resultado->getErrors() as $error) {
        // $error->path    → "Encabezado.Receptor.RUTRecep", "Detalle[2].NmbItem", …
        // $error->code    → "RUT_INVALIDO", "LARGO_MAXIMO", "CAF_RANGO", "TOTALES_DIFERENCIA", …
        // $error->message → descripción en español
    }
}
foreach ($resultado->getWarnings() as $advertencia) { /* no bloquean la emisión */ }
```

Qué se valida antes de enviar:

1. **Reglas del formato DTE**: RUT con dígito verificador, campos obligatorios por tipo de documento
   (giro, dirección y comuna del receptor en facturas; IndTraslado en guías; referencias con CodRef en
   notas; información aduanera en exportaciones), largos máximos, catálogos (monedas, impuestos
   adicionales, medios de pago), decimales, rangos de fechas, máximos de líneas (60 en DTE, 1000 en
   boletas), descuentos que no superen el ítem, impuestos adicionales con tasa definida, etc.
2. **Montos**: el SDK calcula todos los totales. Si el ERP informa `MontoItem` o el total esperado
   (`setMontoTotalEsperado()`), se compara y cualquier diferencia es un error.
3. **CAF**: tipo, RUT emisor, folio dentro del rango, fecha de emisión posterior a la autorización,
   folios no vencidos y CAF del ambiente correcto.
4. **Certificado**: vigencia (advertencia 30 días antes de vencer).
5. **XML**: cada DTE firmado y cada sobre se validan contra los **XSD oficiales del SII**.
6. **Firmas**: se verifican las firmas generadas (DigestValue y SignatureValue) antes de enviar.
7. **Sobre**: mismo emisor, sin folios duplicados, sin mezclar boletas y DTE, máximo de documentos,
   resolución coherente con el ambiente.

### Otros documentos

```php
use RoyaltyFusion\Sii\Model\{AdditionalTax, Boleta, Customs, Detail, Driver, DocumentType, Dte, Foreigner,
    GlobalDiscount, OtherCurrency, Payment, Reference, Transport};

// Nota de crédito que corrige montos de la factura 1
$nc = Dte::notaCredito()->setFolio(10)->setFechaEmision(new DateTimeImmutable())->setReceptor($cliente)
    ->addDetalle(Detail::create('Devolución', 1, 12990))
    ->addReferencia(Reference::create(DocumentType::FACTURA, 1, new DateTimeImmutable('2024-05-01'), Reference::CORRIGE_MONTOS, 'Devolución de mercadería'));

// Nota de crédito que corrige texto (sin montos)
$ncTexto = Dte::notaCredito()->setFolio(11)->setFechaEmision(new DateTimeImmutable())->setReceptor($cliente)
    ->addDetalle((new Detail())->setNombre('Donde dice "Calle 1" debe decir "Calle 2"'))
    ->addReferencia(Reference::create(33, 1, new DateTimeImmutable('2024-05-01'), Reference::CORRIGE_TEXTO, 'Corrige dirección'));

// Guía de despacho (traslado interno)
$guia = Dte::guiaDespacho(5)->setFolio(1)->setFechaEmision(new DateTimeImmutable())->setReceptor($cliente)
    ->setTransporte((new Transport())->setPatente('ABCD12')->setChofer(new Driver('11111111-1', 'Pedro Soto'))
        ->setDireccionDestino('Bodega 5')->setComunaDestino('Quilicura'))
    ->addDetalle(Detail::create('Pallet', 4, 0));

// Factura con impuestos adicionales (ILA)
$f = Dte::factura()
    ->addDetalle(Detail::create('Vino', 12, 4500)->addImpuestoAdicional(25))   // tasa por defecto 20,5%
    ->addImpuestoAdicional(new AdditionalTax(28, 5.5))                          // código con tasa propia
    ->addDescuentoGlobal(GlobalDiscount::descuentoPorcentaje(5, 'Cliente frecuente'));

// Precios con IVA incluido en una factura: el SDK desglosa neto e IVA
$fb = Dte::factura()->setMontoBruto(true)->addDetalle(Detail::create('Servicio', 1, 11900));

// Factura de compra con retención total de IVA
$fc = Dte::facturaCompra()->addImpuestoAdicional(new AdditionalTax(15, 19))
    ->addDetalle(Detail::create('Frambuesas', 100, 1500, 'KG')->addImpuestoAdicional(15));

// Boleta (precios con IVA incluido; el SDK desglosa neto e IVA)
$boleta = Boleta::afecta()->setFolio(1)->setFechaEmision(new DateTimeImmutable())
    ->addDetalle(Detail::create('Café', 2, 1990));
$see->send($boleta);                               // API REST de boletas
$see->getStatus($trackId, null, boleta: true);

// Factura de exportación
$exp = Dte::facturaExportacion('DOLAR USA')->setFolio(1)->setFechaEmision(new DateTimeImmutable())
    ->setReceptor((new Client())->setRut('55555555-5')->setRazonSocial('Importer LLC')
        ->setExtranjero(new Foreigner('US-123', '225')))
    ->setTransporte((new Transport())->setAduana((new Customs())
        ->setCodigoModalidadVenta(1)->setCodigoClausulaVenta(5)->setTotalClausulaVenta(12500.50)
        ->setCodigoViaTransporte(1)->setCodigoPuertoEmbarque(906)->setCodigoPuertoDesembarque(262)
        ->setCodigoPaisReceptor(225)->setCodigoPaisDestino(225)))
    ->setOtraMoneda(new OtherCurrency('PESO CL', 950.25))   // equivalente en pesos
    ->addDetalle(Detail::create('Cerezas', 1000, 11.50, 'KG'));
```

### Envío de varios documentos, XML y reenvío

```php
$result = $see->sendBatch([$factura1, $factura2, $notaCredito]);   // un solo sobre EnvioDTE (máx. 2000)

$prepared = $see->prepare($factura);       // DTE timbrado y firmado
$prepared->xml;                            // XML del DTE (ISO-8859-1) para almacenar
$prepared->calculation->totales;           // totales calculados
$prepared->getTed();                       // nodo TED (para el PDF417)

$sobre = $see->getEnvelopeXml([$prepared], '96790240-3');  // sobre para enviar al receptor (intercambio)
$see->sendXml($sobreGuardado, '76123456-7');               // reenvío de un sobre ya firmado

$see->getDocumentStatus($prepared);        // QueryEstDte / estado de boleta
```

### Folios: consultar, asignar y obtener

**Consultar y asignar folios** a partir de los CAF cargados (sin conectarse al SII):

```php
use RoyaltyFusion\Sii\Folio\FileFolioStore;

// Registro del último folio usado. FileFolioStore usa bloqueo de archivo (seguro entre procesos del
// mismo servidor); en un ERP implemente FolioStoreInterface sobre su base de datos (SELECT ... FOR UPDATE).
$see->setFolioStore(new FileFolioStore('/var/lib/erp/sii-folios.json'));
$folios = $see->getFolioManager();

$folios->setUltimoUsado('76123456-7', 33, 1520);   // al migrar desde otro sistema
$folio = $folios->next('76123456-7', 33);          // reserva el siguiente folio (salta CAF vencidos o agotados)

foreach ($folios->status('76123456-7', 33) as $caf) {
    // $caf->desde, $caf->hasta, $caf->ultimoUsado, $caf->disponibles, $caf->getSiguiente(),
    // $caf->fechaVencimiento, $caf->vencido
}
$folios->disponibles('76123456-7', 33);            // total utilizable
$folios->requiereNuevoCaf('76123456-7', 33, 50);   // true si quedan menos de 50

// Si el documento no trae folio, prepare()/send() asignan el siguiente automáticamente
// y lo liberan si la emisión falla.
$see->send(Dte::factura()->setReceptor($cliente)->addDetalle($detalle));
```

**Obtener folios del SII (EXPERIMENTAL).** El SII no ofrece un web service para pedir CAF: solo
existe el formulario web de "Timbraje electrónico". `SiiFolioService` lo automatiza autenticándose con
el certificado digital (debe tener permiso de timbraje para la empresa). Todo CAF descargado se valida
(tipo, RUT, ambiente y par de claves) antes de retornarlo. Si el SII cambia sus formularios, se lanza una
excepción con el mensaje de la página; nunca se retorna un CAF incorrecto.

```php
$sii = $see->getFolioService();

$info = $sii->consultar('76123456-7', 33);         // no solicita nada: máximo autorizado, mensajes del SII
$info->maximoAutorizado;

$caf = $sii->solicitar('76123456-7', 33, 100);     // pide 100 folios y descarga el CAF
file_put_contents("caf/33-{$caf->getFolioDesde()}.xml", $caf->getSource());
$see->addCaf($caf);

$caf = $sii->reobtener('76123456-7', 33, 1);       // vuelve a descargar un CAF ya timbrado
```

### Representación impresa

```php
use RoyaltyFusion\Sii\Report\HtmlReport;

$html = (new HtmlReport())->render($prepared, [
    'resolucion' => new Resolution('2024-01-15', 0),
    'unidad_sii' => 'Santiago Centro',
    'cedible'    => true,           // copia cedible con acuse de recibo Ley 19.983
    'logo'       => 'data:image/png;base64,...',
]);
// Convierta a PDF con Dompdf, wkhtmltopdf o Chrome headless.
```

### Intercambio con proveedores y aceptación/reclamo

```php
use RoyaltyFusion\Sii\Intercambio\{EnvioDteReader, ResponseBuilder};
use RoyaltyFusion\Sii\Ws\RegistroReclamoService;

$envio = (new EnvioDteReader())->read($xmlRecibido);   // valida esquema, firmas y timbre de cada DTE
$builder = new ResponseBuilder($certificado, nombreContacto: 'Contabilidad', mailContacto: 'dte@miempresa.cl');

$builder->recepcionEnvio($envio, 'EnvioDTE.xml', $miRut, idRespuesta: 1);            // acuse de recibo
$builder->resultadoDte([['dte' => $envio->documentos[0], 'estado' => ResponseBuilder::ACEPTADO]], $miRut, 2);
$builder->envioRecibos($envio->documentos, $miRut, 'Bodega central');                 // Ley 19.983

$see->getRegistroReclamo()->registrar('76123456-7', 33, 1234, RegistroReclamoService::ACEPTA_CONTENIDO);
$see->getRegistroReclamo()->listarEventos('76123456-7', 33, 1234);
```

## Symfony 7.4

```php
// config/bundles.php
return [
    // ...
    RoyaltyFusion\Sii\Bridge\Symfony\SiiBundle::class => ['all' => true],
];
```

```yaml
# config/packages/sii.yaml
sii:
    environment: certificacion          # produccion
    certificate:
        path: '%env(SII_CERT_PATH)%'
        password: '%env(SII_CERT_PASSWORD)%'
    resolution: { date: '2024-01-15', number: 0 }
    caf_directory: '%kernel.project_dir%/var/sii/caf'   # o caf_repository: App\Sii\DoctrineCafRepository
    token_cache: cache.app                               # comparte el token entre procesos/workers
    folio_file: '%kernel.project_dir%/var/sii/folios.json'   # o folio_store: App\Sii\DoctrineFolioStore
    company:
        rut: 76123456-7
        razon_social: Mi Empresa SpA
        giro: Venta de software
        actecos: [620200]
        direccion: Av. Siempre Viva 123
        comuna: Santiago
        ciudad: Santiago
```

```php
use RoyaltyFusion\Sii\See;
use RoyaltyFusion\Sii\SeeFactory;

final class FacturacionController
{
    public function __construct(private See $see, private SeeFactory $factory) {}

    public function emitir(): Response
    {
        $result = $this->see->send($factura);
        // ERP multiempresa: una instancia por empresa
        $seeEmpresaB = $this->factory->createFromFile($rutaPfx, $clave, new Resolution('2023-03-01', 80));
    }
}
```

Para almacenar los CAF en la base de datos del ERP implemente `Caf\CafRepositoryInterface`
(métodos `find($rutEmisor, $tipoDte, $folio)` y `findAll($rutEmisor, $tipoDte)`) y configure
`caf_repository`; para el registro de folios implemente `Folio\FolioStoreInterface` y configure `folio_store`.

## Ambientes

| | Certificación | Producción |
|---|---|---|
| Token, upload DTE, consultas | `maullin.sii.cl` | `palena.sii.cl` |
| API boletas (semilla, token, consultas) | `apicert.sii.cl` | `api.sii.cl` |
| Envío de boletas | `pangal.sii.cl` | `rahue.sii.cl` |
| Aceptación/reclamo | `ws2.sii.cl/WSREGISTRORECLAMODTECERT` | `ws1.sii.cl/WSREGISTRORECLAMODTE` |

Las URLs se pueden sobreescribir con `Ws\Endpoints::for($env)->with([...])` (proxy, pruebas).
Los tokens se almacenan (por defecto 30 minutos) y se renuevan automáticamente si el SII los rechaza.

## Proceso de certificación ante el SII

1. Postular en el sitio del SII como "Facturador electrónico – Sistema propio" y obtener la fecha de resolución de certificación.
2. Descargar CAF de prueba en `maullin.sii.cl` para cada tipo de documento.
3. **Set de prueba**: descargar el set, emitir cada caso con `Environment::CERTIFICACION` agregando la
   referencia al caso que indica el set (por ejemplo `TpoDocRef` = `SET` y `RazonRef` = `CASO 1234567-1`;
   en boletas `setCodigoBoleta('SET')`), y enviarlos juntos con `sendBatch()`.
4. **Simulación**: enviar documentos que representen la operación real.
5. **Intercambio**: responder el set de intercambio con `ResponseBuilder` (recepción, resultado y recibos).
6. **Muestras impresas**: generar las representaciones con `HtmlReport` (incluye timbre PDF417).
7. Tras la autorización, cambiar a `Environment::PRODUCCION` con la resolución definitiva y CAF de producción.

## Manejo de errores

| Excepción | Cuándo |
|---|---|
| `ValidationException` | El documento o el XML no cumple las reglas del SII o el XSD (`getResult()` tiene el detalle). No se envía nada. |
| `CertificateException` | Certificado ilegible, contraseña incorrecta, clave privada no corresponde. |
| `CafException` | CAF mal formado o con claves inconsistentes. |
| `AuthenticationException` | El SII no entregó semilla/token (certificado revocado, no autorizado, etc.). |
| `WebServiceException` | Error de comunicación o respuesta inesperada del SII (`getSiiCode()`, `getResponseBody()`). |

Todas implementan `RoyaltyFusion\Sii\Exception\SiiException`. Las respuestas de negocio del SII
(rechazos, estados) se entregan como resultados (`SendResult`, `EnvioStatus`, `DteStatus`), no como excepciones.

## Detalles técnicos relevantes

- Los XML se generan en **ISO-8859-1** y los caracteres no representables se transliteran o eliminan; se escapan `& < > " '`.
- El XML se serializa con un escritor propio, determinista y sin espacios, para que los bytes firmados (TED y XMLDSig) no se alteren.
- Cada DTE declara los mismos namespaces que tendrá dentro del sobre, de modo que la firma del DTE es válida tanto suelto como dentro del `EnvioDTE`.
- Las firmas generadas se verificaron además con `libxmlsec1` (implementación independiente) y el timbre PDF417 se verificó decodificándolo.

## No incluido

- Liquidación-Factura (43), cesión de facturas (AEC/RTC), Libro de Guías y Consumo de Folios (RCOF,
  eliminado por el SII para boletas desde 2022). El Libro de Compras y Ventas fue reemplazado por el RCV,
  que el SII construye a partir de los DTE recibidos.

## Desarrollo

```bash
composer install
vendor/bin/phpunit
```

Los tests usan un certificado y CAF **ficticios** (`tests/Fixtures`) firmados con una clave "SII" falsa;
no sirven para emitir documentos reales.
