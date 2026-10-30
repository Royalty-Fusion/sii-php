<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Model;

/** Emisor del documento (contribuyente autorizado como facturador electrónico). */
class Company
{
    private ?string $rut = null;
    private ?string $razonSocial = null;
    private ?string $giro = null;
    /** @var list<int> */
    private array $actecos = [];
    /** @var list<string> */
    private array $telefonos = [];
    private ?string $email = null;
    private ?string $direccion = null;
    private ?string $comuna = null;
    private ?string $ciudad = null;
    private ?string $sucursal = null;
    private ?int $codigoSucursal = null;
    private ?string $codigoVendedor = null;
    private ?string $idAdicional = null;

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

    /** @return list<int> */
    public function getActecos(): array
    {
        return $this->actecos;
    }

    /**
     * Códigos de actividad económica (máximo 4, 6 dígitos cada uno).
     *
     * @param list<int> $actecos
     */
    public function setActecos(array $actecos): static
    {
        $this->actecos = array_values(array_map('intval', $actecos));

        return $this;
    }

    public function addActeco(int $acteco): static
    {
        $this->actecos[] = $acteco;

        return $this;
    }

    /** @return list<string> */
    public function getTelefonos(): array
    {
        return $this->telefonos;
    }

    /** @param list<string> $telefonos máximo 2 */
    public function setTelefonos(array $telefonos): static
    {
        $this->telefonos = array_values($telefonos);

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

    public function getSucursal(): ?string
    {
        return $this->sucursal;
    }

    public function setSucursal(?string $sucursal): static
    {
        $this->sucursal = $sucursal;

        return $this;
    }

    /** Código de sucursal entregado por el SII (CdgSIISucur). */
    public function getCodigoSucursal(): ?int
    {
        return $this->codigoSucursal;
    }

    public function setCodigoSucursal(?int $codigoSucursal): static
    {
        $this->codigoSucursal = $codigoSucursal;

        return $this;
    }

    public function getCodigoVendedor(): ?string
    {
        return $this->codigoVendedor;
    }

    public function setCodigoVendedor(?string $codigoVendedor): static
    {
        $this->codigoVendedor = $codigoVendedor;

        return $this;
    }

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
