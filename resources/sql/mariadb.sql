-- Tablas opcionales para PdoFolioStore y PdoCafRepository (MariaDB 10.5+ / MySQL 8).
-- "scope" separa empresas y establecimientos del ERP multiempresa (ej. 'tenant42:sucursal3').

CREATE TABLE IF NOT EXISTS sii_folios (
    scope         VARCHAR(64)     NOT NULL DEFAULT '',
    rut_emisor    VARCHAR(10)     NOT NULL,
    tipo_dte      SMALLINT        NOT NULL,
    ultimo_folio  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    actualizado   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (scope, rut_emisor, tipo_dte)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ATENCIÓN: la columna xml contiene la clave privada de timbraje del CAF (RSASK).
-- Restrinja el acceso a esta tabla, no la incluya en logs ni respaldos sin cifrar.
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
