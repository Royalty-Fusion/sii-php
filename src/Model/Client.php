<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Model;

/** Receptor del documento. */
class Client
{
    private ?string $rut = null;
    private ?string $razonSocial = null;
    private ?string $giro = null;
    private ?string $codigoInterno = null;
    private ?string $contacto = null;
    private ?string $email = null;
    private ?string $telefono = null;
    private ?string $direccion = null;
    private ?string $comuna = null;
    private ?string $ciudad = null;
    private ?string $direccionPostal = null;
    private ?string $comunaPostal = null;
    private ?string $ciudadPostal = null;
    private ?Foreigner $extranjero = null;

    /** Constructor final: permite los constructores estáticos con new static() de forma segura. */
    final public function __construct()
    {
    }

    /**
     * Receptor genérico para boletas sin cliente identificado (RUT 66666666-6). Sólo es válido en
     * boletas (39, 41) y en notas de crédito/débito que referencian una boleta.
     */
    public static function consumidorFinal(string $razonSocial = 'Consumidor final'): static
    {
        return (new static())->setRut(\RoyaltyFusion\Sii\Util\Rut::CONSUMIDOR_FINAL)->setRazonSocial($razonSocial);
    }

    public function isConsumidorFinal(): bool
    {
        return $this->rut !== null && \RoyaltyFusion\Sii\Util\Rut::isConsumidorFinal($this->rut);
    }

    public function getRut(): ?string
    {
        return $this->rut;
    }

    public function setRut(?string $rut): static
    {
        $this->rut = $rut;

        return $this;
    }

    public function getRazonSocial(): ?string
    {
        return $this->razonSocial;
    }

    public function setRazonSocial(?string $razonSocial): static
    {
        $this->razonSocial = $razonSocial;

        return $this;
    }

    public function getGiro(): ?string
    {
        return $this->giro;
    }

    public function setGiro(?string $giro): static
    {
        $this->giro = $giro;

        return $this;
    }

    public function getCodigoInterno(): ?string
    {
        return $this->codigoInterno;
    }

    public function setCodigoInterno(?string $codigoInterno): static
    {
        $this->codigoInterno = $codigoInterno;

        return $this;
    }

    public function getContacto(): ?string
    {
        return $this->contacto;
    }

    public function setContacto(?string $contacto): static
    {
        $this->contacto = $contacto;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /** Sólo se informa en boletas (TelefonoRecep). */
    public function getTelefono(): ?string
    {
        return $this->telefono;
    }

    public function setTelefono(?string $telefono): static
    {
        $this->telefono = $telefono;

        return $this;
    }

    public function getDireccion(): ?string
    {
        return $this->direccion;
    }

    public function setDireccion(?string $direccion): static
    {
        $this->direccion = $direccion;

        return $this;
    }

    public function getComuna(): ?string
    {
        return $this->comuna;
    }

    public function setComuna(?string $comuna): static
    {
        $this->comuna = $comuna;

        return $this;
    }

    public function getCiudad(): ?string
    {
        return $this->ciudad;
    }

    public function setCiudad(?string $ciudad): static
    {
        $this->ciudad = $ciudad;

        return $this;
    }

    public function getDireccionPostal(): ?string
    {
        return $this->direccionPostal;
    }

    public function setDireccionPostal(?string $direccionPostal): static
    {
        $this->direccionPostal = $direccionPostal;

        return $this;
    }

    public function getComunaPostal(): ?string
    {
        return $this->comunaPostal;
    }

    public function setComunaPostal(?string $comunaPostal): static
    {
        $this->comunaPostal = $comunaPostal;

        return $this;
    }

    public function getCiudadPostal(): ?string
    {
        return $this->ciudadPostal;
    }

    public function setCiudadPostal(?string $ciudadPostal): static
    {
        $this->ciudadPostal = $ciudadPostal;

        return $this;
    }

    public function getExtranjero(): ?Foreigner
    {
        return $this->extranjero;
    }

    public function setExtranjero(?Foreigner $extranjero): static
    {
        $this->extranjero = $extranjero;

        return $this;
    }
}
