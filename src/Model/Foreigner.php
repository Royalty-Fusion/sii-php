<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Model;

/** Datos del receptor extranjero (nodo Receptor/Extranjero). */
class Foreigner
{
    public function __construct(
        private ?string $numeroId = null,
        private ?string $nacionalidad = null,
        private ?int $tipoDocumentoId = null,
        private ?string $idAdicional = null,
    ) {
    }

    /** Número de identificación del receptor extranjero (NumId). */
    public function getNumeroId(): ?string
    {
        return $this->numeroId;
    }

    public function setNumeroId(?string $numeroId): static
    {
        $this->numeroId = $numeroId;

        return $this;
    }

    /** Código de país de nacionalidad según tabla de Aduanas (Nacionalidad, 3 caracteres). */
    public function getNacionalidad(): ?string
    {
        return $this->nacionalidad;
    }

    public function setNacionalidad(?string $nacionalidad): static
    {
        $this->nacionalidad = $nacionalidad;

        return $this;
    }

    /** TipoDocID (1 o 2), sólo documentos nacionales. */
    public function getTipoDocumentoId(): ?int
    {
        return $this->tipoDocumentoId;
    }

    public function setTipoDocumentoId(?int $tipoDocumentoId): static
    {
        $this->tipoDocumentoId = $tipoDocumentoId;

        return $this;
    }

    /** IdAdicRecep, sólo documentos de exportación. */
    public function getIdAdicional(): ?string
    {
        return $this->idAdicional;
    }

    public function setIdAdicional(?string $idAdicional): static
    {
        $this->idAdicional = $idAdicional;

        return $this;
    }
}
