<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Rcv;

/**
 * Cruza los documentos del RCV con los registrados en el ERP por RUT de la contraparte, tipo y folio.
 */
final class RcvConciliacion
{
    /**
     * @param list<RcvDocument>                                                            $sii
     * @param iterable<array{rut: string, tipo: int, folio: int, monto_total: int|float}> $erp
     */
    public function conciliar(array $sii, iterable $erp, int $tolerancia = 0): RcvConciliacionResultado
    {
        $porClave = [];
        foreach ($sii as $doc) {
            $porClave[$doc->getKey()] = $doc;
        }
        $soloErp = [];
        $diferencias = [];
        $coincidentes = [];
        foreach ($erp as $registro) {
            $clave = RcvDocument::key((string) $registro['rut'], (int) $registro['tipo'], (int) $registro['folio']);
            $doc = $porClave[$clave] ?? null;
            if ($doc === null) {
                $soloErp[] = $registro;
                continue;
            }
            unset($porClave[$clave]);
            $diferencia = (int) round((float) $registro['monto_total']) - $doc->montoTotal;
            if (abs($diferencia) > $tolerancia) {
                $diferencias[] = ['sii' => $doc, 'erp' => $registro, 'diferencia' => $diferencia];
            } else {
                $coincidentes[] = $doc;
            }
        }

        return new RcvConciliacionResultado($coincidentes, array_values($porClave), $soloErp, $diferencias);
    }
}
