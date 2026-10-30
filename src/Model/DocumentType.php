<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Model;

/** Tipos de Documento Tributario Electrónico soportados por el SII. */
enum DocumentType: int
{
    case FACTURA = 33;
    case FACTURA_EXENTA = 34;
    case BOLETA = 39;
    case BOLETA_EXENTA = 41;
    case FACTURA_COMPRA = 46;
    case GUIA_DESPACHO = 52;
    case NOTA_DEBITO = 56;
    case NOTA_CREDITO = 61;
    case FACTURA_EXPORTACION = 110;
    case NOTA_DEBITO_EXPORTACION = 111;
    case NOTA_CREDITO_EXPORTACION = 112;

    public function label(): string
    {
        return match ($this) {
            self::FACTURA => 'Factura Electrónica',
            self::FACTURA_EXENTA => 'Factura No Afecta o Exenta Electrónica',
            self::BOLETA => 'Boleta Electrónica',
            self::BOLETA_EXENTA => 'Boleta Exenta Electrónica',
            self::FACTURA_COMPRA => 'Factura de Compra Electrónica',
            self::GUIA_DESPACHO => 'Guía de Despacho Electrónica',
            self::NOTA_DEBITO => 'Nota de Débito Electrónica',
            self::NOTA_CREDITO => 'Nota de Crédito Electrónica',
            self::FACTURA_EXPORTACION => 'Factura de Exportación Electrónica',
            self::NOTA_DEBITO_EXPORTACION => 'Nota de Débito de Exportación Electrónica',
            self::NOTA_CREDITO_EXPORTACION => 'Nota de Crédito de Exportación Electrónica',
        };
    }

    public function isBoleta(): bool
    {
        return $this === self::BOLETA || $this === self::BOLETA_EXENTA;
    }

    public function isExportacion(): bool
    {
        return \in_array($this, [self::FACTURA_EXPORTACION, self::NOTA_DEBITO_EXPORTACION, self::NOTA_CREDITO_EXPORTACION], true);
    }

    /** Documentos sin IVA: todos sus montos son exentos. */
    public function isExento(): bool
    {
        return $this === self::FACTURA_EXENTA || $this === self::BOLETA_EXENTA || $this->isExportacion();
    }

    /** Notas de crédito/débito: requieren referencia al documento que modifican. */
    public function isNota(): bool
    {
        return \in_array($this, [self::NOTA_DEBITO, self::NOTA_CREDITO, self::NOTA_DEBITO_EXPORTACION, self::NOTA_CREDITO_EXPORTACION], true);
    }

    /** Nombre del nodo bajo DTE en el XML (Documento | Exportaciones). */
    public function xmlNode(): string
    {
        return $this->isExportacion() ? 'Exportaciones' : 'Documento';
    }
}
