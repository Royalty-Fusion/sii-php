# sii-php — SDK de Facturación Electrónica para el SII de Chile

SDK PHP para emitir, validar, firmar y enviar Documentos Tributarios Electrónicos (DTE) al
**Servicio de Impuestos Internos (SII) de Chile**, conectándose directamente a sus servicios, sin
intermediarios. Su diseño sigue el estilo de [Greenter](https://github.com/thegreenter/greenter)
(el SDK de SUNAT Perú): modelos con setters encadenables, una fachada `See` y resultados tipados.

- PHP **8.3 a 8.5**, compatible con **Symfony 6.4, 7.4 y 8.x** (incluye bundle opcional).
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
| Symfony / multiempresa | `Bridge\Symfony\SiiBundle`, `SeeFactory`, `SeeConfig` | Configuración por YAML, caché de tokens PSR-6, una configuración inmutable por empresa. |
| Persistencia | `Pdo\PdoFolioStore`, `Pdo\PdoCafRepository` | Folios y CAF en MariaDB/MySQL/PostgreSQL/SQLite con `ext-pdo`. |
| Estados | `Ws\Result\SendResult`, `EnvioStatus`, `DteStatus` | Resultados persistibles (`toArray()`), consulta diferida y reconciliación. |

## Instalación

El paquete es privado. Agregue el repositorio (acceso por SSH) en el `composer.json` del proyecto:

```json
{
    "repositories": [
        { "type": "vcs", "url": "git@github.com:Royalty-Fusion/sii-php.git" }
    ],
    "require": {
        "royaltyfusion/sii-php": "^0.1"
    }
}
```

```bash
composer update royaltyfusion/sii-php
```

Para el timbre PDF417 de la representación impresa:

```bash
composer require tecnickcom/tc-lib-barcode
```

Requisitos: PHP 8.3 a 8.5, Symfony 6.4, 7.4 u 8.x (sólo `symfony/http-client`; el bundle es opcional) y las
extensiones `openssl`, `dom`, `libxml` y `mbstring`. `ext-pdo` es opcional (`PdoFolioStore`, `PdoCafRepository`).

### Zona horaria

**No cambie la zona horaria global.** El SDK genera en hora de Chile (`America/Santiago`) todo lo que el SII
exige (`TmstFirma`, `TSTED`, `TmstFirmaEnv`, fecha de emisión por defecto, vencimiento del CAF y validación de
fechas), sin importar `date_default_timezone_get()`. Para controlar la hora (pruebas, colas) inyecte un reloj;
el instante que retorne se convierte siempre a hora de Chile:

```php
$see = new See($certificado, Environment::PRODUCCION, $resolucion, clock: fn () => new DateTimeImmutable('now'));
// o, por empresa: SeeConfig::...->withClock(fn () => $miReloj->now())
```

Las fechas que el ERP asigna al documento (`setFechaEmision()`, referencias, vencimientos) se toman como fecha
calendario tal cual vienen. Si no indica la fecha de emisión se usa la fecha actual de Chile; para obtenerla
use `RoyaltyFusion\Sii\Util\Clock::today()` (no `new DateTimeImmutable()`, que usa la zona del servidor).

## Uso rápido

```php
use RoyaltyFusion\Sii\Certificate\Certificate;
use RoyaltyFusion\Sii\Environment;
use RoyaltyFusion\Sii\Model\{Client, Company, Detail, Dte, Resolution};
use RoyaltyFusion\Sii\See;
use RoyaltyFusion\Sii\Util\Clock;

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
    ->setFolio(1)                             // o un FolioStore para asignarlo automáticamente
    ->setFechaEmision(Clock::today())         // opcional: por defecto, la fecha actual de Chile
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

### Contrato de resultados para el ERP

| Resultado | Cómo se detecta | ¿Final? | Acción del ERP |
|---|---|---|---|
| Error de validación local | `ValidationException` (`getResult()`) | Sí | Corregir los datos. **No se envió nada**; el folio asignado automáticamente se libera. |
| Error de comunicación antes de subir (semilla, token) | `AuthenticationException` / `WebServiceException` | No | Reintentar más tarde. No se envió nada; los folios siguen libres. |
| Upload rechazado por el SII (STATUS ≠ 0) | `SendResult::isRejected()` (`status`, `message`, `errors`) | Sí | Corregir y reenviar. Los folios **no** quedaron registrados en el SII. |
| Comunicación cortada **durante** la subida | `UploadUncertainException` (`getState()`) | No | Guardar `getState()`, **no reutilizar los folios**, reconciliar con `See::reconcile()`. |
| Envío recibido con TrackID | `SendResult::isPending()` / `isSuccess()` | No | Guardar `SendResult::toArray()` y consultar con `See::resumeStatus()` (cron). |
| `REC`, `SOK`, `CRT`, `FOK`, `PDR` | `EnvioStatus::isPending()` | No | Volver a consultar (procesando). |
| Código numérico (ej. `-11`) | `EnvioStatus::isQueryError()` (también `isPending()`) | No | Reintentar la consulta más tarde (TrackID aún no disponible o error de consulta). |
| `EPR` sin rechazos ni reparos | `EnvioStatus::isAccepted()` | Sí | Documentos aceptados. |
| `EPR` con reparos, `RPR`, `RLV` | `EnvioStatus::isAcceptedWithObjections()` | Sí | Aceptados; revisar las observaciones. |
| `EPR` con algunos rechazados | `EnvioStatus::isPartiallyRejected()` | Sí | Consultar cada documento (`getDocumentStatus()` / `reconcile()`); reemitir sólo los rechazados. |
| `EPR` con todos rechazados, `RSC`, `RFR`, `RCT`, `RCH` | `EnvioStatus::isRejected()` | Sí | Corregir y reenviar; los folios rechazados no quedan usados. |
| `DOK`, `TMD`, `TMC`, `MMD`, `MMC` | `DteStatus::isAccepted()` | Sí | Documento vigente en el SII. |
| `FAU` (no recibido) | `DteStatus::isPending()` | No | Si la subida fue incierta: reenviar el **mismo** sobre con `sendXml()`. |
| `DNK` (datos no coinciden) | `DteStatus::isMismatch()` / `isReceived()` | Sí | El folio existe en el SII: revisar monto/fecha/RUT consultados; **no** reutilizar el folio. |
| `FAN`, `ANC`, `AND` | `DteStatus::isAnnulled()` | Sí | Documento anulado (directamente o por nota). |
| `FNA`, `EMP` | `DteStatus::isRejected()` | Sí | No autorizado: revisar CAF / autorización del emisor. |

#### Emisión asíncrona (cron) y reconciliación

```php
try {
    $result = $see->send($factura);
    $erp->guardarEnvio(json_encode($result->toArray()));     // TrackID, RUT emisor/envía, documentos, fecha, sobre firmado
} catch (UploadUncertainException $e) {
    $erp->guardarEnvio(json_encode($e->getState()));          // mismo formato, sin TrackID
}

// En otro proceso:
$estado = json_decode($fila->envio, true);
if ($estado['track_id'] !== null) {
    $status = $see->resumeStatus($estado);                    // sin rearmar ni refirmar el documento
} else {
    foreach ($see->reconcile($estado) as $id => $dte) {       // por tipo, folio, RUT, monto y fecha
        if ($dte->isPending()) { /* FAU: reenviar el mismo sobre */ $see->sendXml(base64_decode($estado['sobre']), $estado['rut_emisor']); break; }
    }
}
```

### Montos en pesos (CLP)

En documentos nacionales todos los montos que calcula `TotalsCalculator` son **enteros** (por línea, descuentos,
neto, exento, IVA, impuestos y total). Sólo hay decimales en documentos de exportación (moneda extranjera, 2
decimales) y en los montos de `OtherCurrency`. El IVA se calcula sobre el neto total, no por línea. Si el ERP
informa `setMontoTotalEsperado()` y no coincide, el error indica la diferencia y las líneas redondeadas, por
ejemplo: `diferencia 1. Posibles causas: Línea 1 "Tornillo": 3 × 33.5 = 100.5, redondeado a 101; IVA por línea = 20, pero el SII calcula el IVA sobre el neto total = 19`.

### Consumidor final

`Rut::CONSUMIDOR_FINAL` (`66666666-6`), `Rut::isConsumidorFinal()` y `Client::consumidorFinal()`. Una boleta
sin receptor usa este RUT automáticamente. El validador lo rechaza en facturas y lo admite sólo en boletas y en
notas de crédito/débito que referencian una boleta (39/41).

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
use RoyaltyFusion\Sii\Pdo\PdoFolioStore;

// Registro del último folio usado (obligatorio para asignar folios automáticamente):
//  - PdoFolioStore: base de datos (SELECT ... FOR UPDATE o GET_LOCK), seguro entre servidores;
//  - FileFolioStore: archivo con flock, seguro entre procesos de un mismo servidor.
$see->setFolioStore(new PdoFolioStore($pdo, 'tenant42:sucursal3'));
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

**Obtener folios del SII (EXPERIMENTAL, desactivado por defecto).** El SII no ofrece un web service para
pedir CAF: solo existe el formulario web de "Timbraje electrónico". `SiiFolioService` lo automatiza
autenticándose con el certificado digital (debe tener permiso de timbraje para la empresa). Hay que activarlo
explícitamente; si no, `getFolioService()` lanza una excepción. Todo CAF descargado se valida (tipo, RUT,
ambiente y par de claves) antes de retornarlo. Si el SII cambia sus formularios, se lanza una excepción con el
texto de la página del SII; nunca se retorna un CAF incorrecto.

```php
$see->enableFolioService();                        // o SeeConfig::withFolioService()
$sii = $see->getFolioService();

$info = $sii->consultar('76123456-7', 33);         // no solicita nada: máximo autorizado, mensajes del SII
$info->maximoAutorizado;

$caf = $sii->solicitar('76123456-7', 33, 100);     // pide 100 folios y descarga el CAF
file_put_contents("caf/33-{$caf->getFolioDesde()}.xml", $caf->getSource());
$see->addCaf($caf);

$caf = $sii->reobtener('76123456-7', 33, 1);       // vuelve a descargar un CAF ya timbrado
```

### Representación impresa

**Con TCPDF u otro motor (sin dependencias nuevas):** `See::getRepresentationData()` entrega todos los datos
del documento y el TED listo para el PDF417.

```php
$data = $see->getRepresentationData($prepared);      // usa la resolución y la oficina configuradas
$data->documento;      // tipo, nombre, folio, fecha_emision, ...
$data->emisor;         // rut, rut_formateado, razon_social, giro, direccion, comuna, ...
$data->receptor;       // null en boletas sin receptor
$data->detalle;        // líneas con cantidad, precio, descuento y monto
$data->totales;        // monto_neto, monto_exento, iva, impuestos, monto_total, otra_moneda
$data->referencias;
$data->resolucion;     // ['numero' => 0, 'fecha' => '2024-01-15', 'texto' => 'Res. 0 de 2024']
$data->oficinaSii;     // "SANTIAGO ORIENTE"
$data->leyendaTimbre;  // "Timbre Electrónico SII - Res. 0 de 2024 - Verifique documento: www.sii.cl"
$data->admiteCedible;  // facturas y guías

// PDF417 con nivel de corrección 5 (exigido por el SII):
$pdf->write2DBarcode($data->tedLatin1, $data->pdf417['tcpdf_type'], $x, $y, $data->pdf417['ancho_mm'], $data->pdf417['alto_mm']);
```

`HtmlReport` (HTML con el PDF417 embebido) sigue disponible y requiere `tecnickcom/tc-lib-barcode`:

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

## Symfony (6.4, 7.4 y 8.x)

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

Sin la sección `certificate` el bundle sólo registra `SeeFactory` (ERP multiempresa).

## Multiempresa

`SeeFactory` comparte sólo infraestructura (cliente HTTP, caché de tokens, logger por defecto). Todo lo de
cada empresa llega en un `SeeConfig` inmutable, sin estado global:

```php
use RoyaltyFusion\Sii\Pdo\{PdoCafRepository, PdoFolioStore};
use RoyaltyFusion\Sii\{SeeConfig, SeeFactory};

$config = SeeConfig::fromPkcs12($empresa->pfx, $empresa->clavePfx)    // o fromPem() / fromFile() / create($certificate)
    ->withEnvironment(Environment::PRODUCCION)
    ->withResolution(new Resolution('2023-03-01', 80))
    ->withCafRepository(new PdoCafRepository($pdo, "tenant{$id}:{$sucursal}"))
    ->withFolioStore(new PdoFolioStore($pdo, "tenant{$id}:{$sucursal}"))  // nunca compartido entre empresas
    ->withCompany($emisor)
    ->withClock(fn () => $reloj->now())                                    // opcional
    ->withLogger($logger)                                                  // opcional (se redacta)
    ->withOficinaSii('Santiago Oriente');
$see = $factory->create($config);
```

Ver `examples/erp-multiempresa.php` (certificado, CAF y folios en la base de datos).

### Tablas para PDO (MariaDB)

`PdoFolioStore` y `PdoCafRepository` sólo requieren `ext-pdo`. El `scope` (prefijo) identifica a la empresa y
al establecimiento, porque en un ERP multiempresa el RUT solo no basta. `PdoCafRepository` acepta además el
nombre de la tabla y de la columna de scope (`new PdoCafRepository($pdo, 'tenant42', 'mis_caf', 'tenant_id')`).

```sql
CREATE TABLE IF NOT EXISTS sii_folios (
    scope         VARCHAR(64)     NOT NULL DEFAULT '',
    rut_emisor    VARCHAR(10)     NOT NULL,
    tipo_dte      SMALLINT        NOT NULL,
    ultimo_folio  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    actualizado   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (scope, rut_emisor, tipo_dte)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sii_caf (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope              VARCHAR(64)     NOT NULL DEFAULT '',
    rut_emisor         VARCHAR(10)     NOT NULL,
    tipo_dte           SMALLINT        NOT NULL,
    folio_desde        BIGINT UNSIGNED NOT NULL,
    folio_hasta        BIGINT UNSIGNED NOT NULL,
    fecha_autorizacion DATE            NOT NULL,
    certificacion      TINYINT(1)      NOT NULL DEFAULT 0,
    xml                MEDIUMBLOB      NOT NULL,
    creado             TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sii_caf (scope, rut_emisor, tipo_dte, folio_desde),
    KEY ix_sii_caf_rango (scope, rut_emisor, tipo_dte, folio_desde, folio_hasta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

(`resources/sql/mariadb.sql` y `resources/sql/sqlite.sql`.) `PdoFolioStore` usa `SELECT … FOR UPDATE` dentro de
una transacción (o `new PdoFolioStore($pdo, $scope, 'sii_folios', 'get_lock')` para `GET_LOCK()` de MariaDB); si
el PDO ya está en una transacción del ERP, la reutiliza (un rollback del ERP libera el folio).

> **Seguridad del CAF.** El XML del CAF incluye la clave privada de timbraje (`RSASK`): con ella se puede timbrar
> documentos a nombre del emisor. No la registre en logs (el `Redactor` la oculta), no la exponga por APIs ni
> en respuestas al navegador, restrinja el acceso a la tabla `sii_caf` y cifre los respaldos.

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
- Los logs pasan por `Util\Redactor`: nunca se registran el token, la semilla firmada, `SignatureValue`, `X509Certificate`, la clave del CAF (`RSASK`), claves privadas ni la contraseña del certificado.

## Pendiente / no incluido

Planificado para versiones siguientes:

- Cesión de facturas (AEC), Libro de Guías y Liquidación-Factura (43).
- Consulta del Registro de Compras y Ventas (RCV) para conciliación.

No incluido: Consumo de Folios (RCOF), eliminado por el SII para boletas desde 2022, y el Libro de Compras y
Ventas, reemplazado por el RCV que el SII construye a partir de los DTE recibidos.

## Desarrollo

```bash
composer install
vendor/bin/phpunit                 # pruebas unitarias (suite "unit")
vendor/bin/phpstan analyse         # nivel 6
```

### Pruebas reales en certificación

La suite `certificacion` envía documentos reales a `maullin.sii.cl`, `apicert.sii.cl` y `pangal.sii.cl`: semilla y
token (DTE y boletas), factura 33, guía 52, boleta 39, notas de crédito 61 con `CodRef` 1 y 3, `getStatus`,
`getDocumentStatus`, reconciliación y lectura de un sobre de intercambio. Sólo corre si están definidas las
variables; sin ellas se omite, y la CI no la ejecuta.

```bash
SII_CERT_PFX=/ruta/firma.pfx SII_CERT_PASSWORD=*** SII_RUT_EMISOR=76123456-7 \
SII_CAF_DIR=/ruta/caf-certificacion SII_RESOLUCION_FECHA=2024-01-15 \
vendor/bin/phpunit --testsuite certificacion
```

Opcionales: `SII_RESOLUCION_NUMERO`, `SII_RUT_RECEPTOR`, `SII_RAZON_SOCIAL`, `SII_GIRO`, `SII_ACTECO`,
`SII_DIRECCION`, `SII_COMUNA`, `SII_INTERCAMBIO_XML` (sobre de un tercero), `SII_ESPERA_SEGUNDOS` y
`SII_FOLIO_STORE` (archivo con los folios ya usados; por defecto `SII_CAF_DIR/.folios-certificacion.json`).

Los tests usan un certificado y CAF **ficticios** (`tests/Fixtures`) firmados con una clave "SII" falsa;
no sirven para emitir documentos reales.
