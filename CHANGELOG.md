# Changelog

Todos los cambios relevantes de `royaltyfusion/sii-php`. El formato sigue
[Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y el proyecto usa
[versionado semántico](https://semver.org/lang/es/). Mientras la versión sea `0.x`, un cambio de versión
menor (`0.1` → `0.2`) puede incluir cambios incompatibles.

## [0.1.0] - 2026-10-30

Primera versión para integración con ERP.

### Emisión
- Documentos 33, 34, 39, 41, 46, 52, 56, 61, 110, 111 y 112 con la estructura exacta de los XSD oficiales del SII (incluidos en `resources/xsd`).
- Certificado digital desde `.pfx`/`.p12` (con respaldo `openssl -legacy` para cifrados RC2-40/3DES), PEM o contenido en memoria.
- CAF: lectura (también ISO-8859-1 sin declaración de encoding), verificación de claves, rango, vencimiento a 6 meses y ambiente; timbre electrónico (TED).
- Firma XMLDSig del DTE, del sobre `EnvioDTE`/`EnvioBOLETA` y de la semilla.
- Cálculo de totales: descuentos y recargos por línea y globales, exentos, IVA sobre el neto total, impuestos adicionales y retenciones, montos brutos, otra moneda. En CLP todos los montos son enteros.
- Validación de negocio, CAF, certificado y XSD antes de enviar; verificación de las firmas generadas.

### Servicios del SII
- Semilla y token (DTE y boletas) con caché de tokens (memoria o PSR-6).
- Upload de `EnvioDTE`, API REST de boletas, `QueryEstUp`, `QueryEstDte`, `QueryEstDteAv`, registro de aceptación/reclamo.
- Contrato de resultados: `isPending()`, `isAccepted()`, `isAcceptedWithObjections()`, `isRejected()` (y `isPartiallyRejected()`, `isReceived()`, `isMismatch()`, `isAnnulled()`, `isQueryError()`).
- `SendResult::toArray()`/`fromArray()`, `See::resumeStatus()` y `See::reconcile()` para emisión asíncrona.
- `UploadUncertainException` cuando se corta la comunicación durante la subida (la subida no se reintenta automáticamente).

### Folios y persistencia
- `FolioManager` con asignación atómica del siguiente folio y consulta de disponibles y vencimientos.
- `FileFolioStore`, `PdoFolioStore` (`SELECT … FOR UPDATE` o `GET_LOCK`) y `PdoCafRepository` con scope por empresa/establecimiento; esquemas en `resources/sql`.
- Sin un `FolioStore` configurado no se asignan folios automáticamente.
- `SiiFolioService` (experimental, desactivado por defecto): solicitar, consultar y reobtener CAF en el sitio web de timbraje.

### Integración
- `SeeConfig` inmutable por empresa y `SeeFactory::create(SeeConfig)`; bundle para Symfony 6.4, 7.4 y 8.x.
- Hora de Chile (`Util\Clock`) para todo lo generado, independiente de la zona horaria global; reloj inyectable.
- `Util\Redactor`: los logs no contienen token, semilla firmada, `SignatureValue`, `X509Certificate`, `RSASK`, claves privadas ni la contraseña del certificado.
- `See::getRepresentationData()` para imprimir con TCPDF (TED y parámetros del PDF417); `HtmlReport` opcional.
- Intercambio: lectura de sobres recibidos, `RespuestaDTE` y `EnvioRecibos`.
- `Rut::CONSUMIDOR_FINAL`, `Rut::isConsumidorFinal()` y `Client::consumidorFinal()`.

### Calidad
- Pruebas unitarias y CI con PHP 8.3, 8.4 y 8.5 sobre Symfony 6.4 y 7.4, más un job de PHPStan nivel 6.
- Suite `certificacion` contra los servidores reales del SII, activada por variables de entorno.

[0.1.0]: https://github.com/Royalty-Fusion/sii-php/releases/tag/v0.1.0
