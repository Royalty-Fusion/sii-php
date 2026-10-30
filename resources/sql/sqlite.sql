-- Esquema equivalente para SQLite (pruebas y despliegues pequeños).
CREATE TABLE IF NOT EXISTS sii_folios (
    scope        TEXT    NOT NULL DEFAULT '',
    rut_emisor   TEXT    NOT NULL,
    tipo_dte     INTEGER NOT NULL,
    ultimo_folio INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (scope, rut_emisor, tipo_dte)
);

CREATE TABLE IF NOT EXISTS sii_caf (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    scope              TEXT    NOT NULL DEFAULT '',
    rut_emisor         TEXT    NOT NULL,
    tipo_dte           INTEGER NOT NULL,
    folio_desde        INTEGER NOT NULL,
    folio_hasta        INTEGER NOT NULL,
    fecha_autorizacion TEXT    NOT NULL,
    certificacion      INTEGER NOT NULL DEFAULT 0,
    xml                BLOB    NOT NULL,
    UNIQUE (scope, rut_emisor, tipo_dte, folio_desde)
);
