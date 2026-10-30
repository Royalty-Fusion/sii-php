<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Model;

/** Información de transporte (nodo Transporte), usada en guías de despacho y exportaciones. */
class Transport
{
    private ?string $patente = null;
    private ?string $patenteCarro = null;
    private ?string $rutTransportista = null;
    private ?Driver $chofer = null;
    private ?string $direccionDestino = null;
    private ?string $comunaDestino = null;
    private ?string $ciudadDestino = null;
    private ?Customs $aduana = null;

    public function getPatente(): ?string
    {
        return $this->patente;
    }

    public function setPatente(?string $patente): static
    {
        $this->patente = $patente;

        return $this;
    }

    public function getPatenteCarro(): ?string
    {
        return $this->patenteCarro;
    }

    public function setPatenteCarro(?string $patenteCarro): static
    {
        $this->patenteCarro = $patenteCarro;

        return $this;
    }

    public function getRutTransportista(): ?string
    {
        return $this->rutTransportista;
    }

    public function setRutTransportista(?string $rutTransportista): static
    {
        $this->rutTransportista = $rutTransportista;

        return $this;
    }

    public function getChofer(): ?Driver
    {
        return $this->chofer;
    }

    public function setChofer(?Driver $chofer): static
    {
        $this->chofer = $chofer;

        return $this;
    }

    public function getDireccionDestino(): ?string
    {
        return $this->direccionDestino;
    }

    public function setDireccionDestino(?string $direccionDestino): static
    {
        $this->direccionDestino = $direccionDestino;

        return $this;
    }

    public function getComunaDestino(): ?string
    {
        return $this->comunaDestino;
    }

    public function setComunaDestino(?string $comunaDestino): static
    {
        $this->comunaDestino = $comunaDestino;

        return $this;
    }

    public function getCiudadDestino(): ?string
    {
        return $this->ciudadDestino;
    }

    public function setCiudadDestino(?string $ciudadDestino): static
    {
        $this->ciudadDestino = $ciudadDestino;

        return $this;
    }

    public function getAduana(): ?Customs
    {
        return $this->aduana;
    }

    public function setAduana(?Customs $aduana): static
    {
        $this->aduana = $aduana;

        return $this;
    }
}
