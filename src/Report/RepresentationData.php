<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Report;

/**
 * Datos de la representación impresa de un DTE, listos para cualquier motor de PDF (TCPDF, Dompdf, …).
 *
 * El timbre se entrega como la cadena del TED para el PDF417 junto con sus parámetros:
 *   $pdf->write2DBarcode($data->tedLatin1, $data->pdf417['tcpdf_type'], $x, $y, $data->pdf417['ancho_mm'], $data->pdf417['alto_mm']);
 */
final class RepresentationData
{
    /**
     * @param array<string, mixed>       $documento
     * @param array<string, mixed>       $emisor
     * @param array<string, mixed>|null  $receptor
     * @param list<array<string, mixed>> $detalle
     * @param list<array<string, mixed>> $descuentosGlobales
     * @param array<string, mixed>       $totales
     * @param list<array<string, mixed>> $referencias
     * @param array<string, mixed>|null  $resolucion
     * @param array<string, mixed>       $pdf417
     */
    public function __construct(
        public readonly array $documento,
        public readonly array $emisor,
        public readonly ?array $receptor,
        public readonly array $detalle,
        public readonly array $descuentosGlobales,
        public readonly array $totales,
        public readonly array $referencias,
        public readonly ?array $resolucion,
        public readonly ?string $oficinaSii,
        /** Nodo TED en UTF-8. */
        public readonly string $ted,
        /** Nodo TED en ISO-8859-1: los bytes que se codifican en el PDF417. */
        public readonly string $tedLatin1,
        public readonly array $pdf417,
        /** Leyendas obligatorias bajo el timbre. */
        public readonly string $leyendaTimbre,
        public readonly bool $admiteCedible,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'documento' => $this->documento,
            'emisor' => $this->emisor,
            'receptor' => $this->receptor,
            'detalle' => $this->detalle,
            'descuentos_globales' => $this->descuentosGlobales,
            'totales' => $this->totales,
            'referencias' => $this->referencias,
            'resolucion' => $this->resolucion,
            'oficina_sii' => $this->oficinaSii,
            'ted' => $this->ted,
            'pdf417' => $this->pdf417,
            'leyenda_timbre' => $this->leyendaTimbre,
            'admite_cedible' => $this->admiteCedible,
        ];
    }
}
