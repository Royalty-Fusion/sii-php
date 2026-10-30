<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Model;

/** Información aduanera para documentos de exportación (nodo Transporte/Aduana). Los códigos corresponden a las tablas del Servicio Nacional de Aduanas. */
class Customs
{
    private ?int $codigoModalidadVenta = null;
    private ?int $codigoClausulaVenta = null;
    private float|int|null $totalClausulaVenta = null;
    private ?int $codigoViaTransporte = null;
    private ?string $nombreTransporte = null;
    private ?string $rutCompaniaTransporte = null;
    private ?string $nombreCompaniaTransporte = null;
    private ?string $idAdicionalTransporte = null;
    private ?string $booking = null;
    private ?string $operador = null;
    private ?int $codigoPuertoEmbarque = null;
    private ?string $idAdicionalPuertoEmbarque = null;
    private ?int $codigoPuertoDesembarque = null;
    private ?string $idAdicionalPuertoDesembarque = null;
    private ?int $tara = null;
    private ?int $codigoUnidadTara = null;
    private float|int|null $pesoBruto = null;
    private ?int $codigoUnidadPesoBruto = null;
    private float|int|null $pesoNeto = null;
    private ?int $codigoUnidadPesoNeto = null;
    private ?int $totalItems = null;
    private ?int $totalBultos = null;
    private float|int|null $montoFlete = null;
    private float|int|null $montoSeguro = null;
    private ?int $codigoPaisReceptor = null;
    private ?int $codigoPaisDestino = null;
    /** @var list<Package> */
    private array $bultos = [];

    /** CodModVenta (1 a firme, 2 bajo condición, 3 consignación libre, ...) */
    public function getCodigoModalidadVenta(): ?int
    {
        return $this->codigoModalidadVenta;
    }

    public function setCodigoModalidadVenta(?int $codigoModalidadVenta): static
    {
        $this->codigoModalidadVenta = $codigoModalidadVenta;

        return $this;
    }

    /** CodClauVenta (1 CIF, 2 CFR, 3 EXW, 4 FAS, 5 FOB, ...) */
    public function getCodigoClausulaVenta(): ?int
    {
        return $this->codigoClausulaVenta;
    }

    public function setCodigoClausulaVenta(?int $codigoClausulaVenta): static
    {
        $this->codigoClausulaVenta = $codigoClausulaVenta;

        return $this;
    }

    /** TotClauVenta */
    public function getTotalClausulaVenta(): float|int|null
    {
        return $this->totalClausulaVenta;
    }

    public function setTotalClausulaVenta(float|int|null $totalClausulaVenta): static
    {
        $this->totalClausulaVenta = $totalClausulaVenta;

        return $this;
    }

    /** CodViaTransp (1 marítima, 4 aérea, 5 postal, 7 carretera, ...) */
    public function getCodigoViaTransporte(): ?int
    {
        return $this->codigoViaTransporte;
    }

    public function setCodigoViaTransporte(?int $codigoViaTransporte): static
    {
        $this->codigoViaTransporte = $codigoViaTransporte;

        return $this;
    }

    /** NombreTransp */
    public function getNombreTransporte(): ?string
    {
        return $this->nombreTransporte;
    }

    public function setNombreTransporte(?string $nombreTransporte): static
    {
        $this->nombreTransporte = $nombreTransporte;

        return $this;
    }

    /** RUTCiaTransp */
    public function getRutCompaniaTransporte(): ?string
    {
        return $this->rutCompaniaTransporte;
    }

    public function setRutCompaniaTransporte(?string $rutCompaniaTransporte): static
    {
        $this->rutCompaniaTransporte = $rutCompaniaTransporte;

        return $this;
    }

    /** NomCiaTransp */
    public function getNombreCompaniaTransporte(): ?string
    {
        return $this->nombreCompaniaTransporte;
    }

    public function setNombreCompaniaTransporte(?string $nombreCompaniaTransporte): static
    {
        $this->nombreCompaniaTransporte = $nombreCompaniaTransporte;

        return $this;
    }

    /** IdAdicTransp */
    public function getIdAdicionalTransporte(): ?string
    {
        return $this->idAdicionalTransporte;
    }

    public function setIdAdicionalTransporte(?string $idAdicionalTransporte): static
    {
        $this->idAdicionalTransporte = $idAdicionalTransporte;

        return $this;
    }

    /** Booking */
    public function getBooking(): ?string
    {
        return $this->booking;
    }

    public function setBooking(?string $booking): static
    {
        $this->booking = $booking;

        return $this;
    }

    /** Operador */
    public function getOperador(): ?string
    {
        return $this->operador;
    }

    public function setOperador(?string $operador): static
    {
        $this->operador = $operador;

        return $this;
    }

    /** CodPtoEmbarque */
    public function getCodigoPuertoEmbarque(): ?int
    {
        return $this->codigoPuertoEmbarque;
    }

    public function setCodigoPuertoEmbarque(?int $codigoPuertoEmbarque): static
    {
        $this->codigoPuertoEmbarque = $codigoPuertoEmbarque;

        return $this;
    }

    /** IdAdicPtoEmb */
    public function getIdAdicionalPuertoEmbarque(): ?string
    {
        return $this->idAdicionalPuertoEmbarque;
    }

    public function setIdAdicionalPuertoEmbarque(?string $idAdicionalPuertoEmbarque): static
    {
        $this->idAdicionalPuertoEmbarque = $idAdicionalPuertoEmbarque;

        return $this;
    }

    /** CodPtoDesemb */
    public function getCodigoPuertoDesembarque(): ?int
    {
        return $this->codigoPuertoDesembarque;
    }

    public function setCodigoPuertoDesembarque(?int $codigoPuertoDesembarque): static
    {
        $this->codigoPuertoDesembarque = $codigoPuertoDesembarque;

        return $this;
    }

    /** IdAdicPtoDesemb */
    public function getIdAdicionalPuertoDesembarque(): ?string
    {
        return $this->idAdicionalPuertoDesembarque;
    }

    public function setIdAdicionalPuertoDesembarque(?string $idAdicionalPuertoDesembarque): static
    {
        $this->idAdicionalPuertoDesembarque = $idAdicionalPuertoDesembarque;

        return $this;
    }

    /** Tara */
    public function getTara(): ?int
    {
        return $this->tara;
    }

    public function setTara(?int $tara): static
    {
        $this->tara = $tara;

        return $this;
    }

    /** CodUnidMedTara */
    public function getCodigoUnidadTara(): ?int
    {
        return $this->codigoUnidadTara;
    }

    public function setCodigoUnidadTara(?int $codigoUnidadTara): static
    {
        $this->codigoUnidadTara = $codigoUnidadTara;

        return $this;
    }

    /** PesoBruto */
    public function getPesoBruto(): float|int|null
    {
        return $this->pesoBruto;
    }

    public function setPesoBruto(float|int|null $pesoBruto): static
    {
        $this->pesoBruto = $pesoBruto;

        return $this;
    }

    /** CodUnidPesoBruto */
    public function getCodigoUnidadPesoBruto(): ?int
    {
        return $this->codigoUnidadPesoBruto;
    }

    public function setCodigoUnidadPesoBruto(?int $codigoUnidadPesoBruto): static
    {
        $this->codigoUnidadPesoBruto = $codigoUnidadPesoBruto;

        return $this;
    }

    /** PesoNeto */
    public function getPesoNeto(): float|int|null
    {
        return $this->pesoNeto;
    }

    public function setPesoNeto(float|int|null $pesoNeto): static
    {
        $this->pesoNeto = $pesoNeto;

        return $this;
    }

    /** CodUnidPesoNeto */
    public function getCodigoUnidadPesoNeto(): ?int
    {
        return $this->codigoUnidadPesoNeto;
    }

    public function setCodigoUnidadPesoNeto(?int $codigoUnidadPesoNeto): static
    {
        $this->codigoUnidadPesoNeto = $codigoUnidadPesoNeto;

        return $this;
    }

    /** TotItems */
    public function getTotalItems(): ?int
    {
        return $this->totalItems;
    }

    public function setTotalItems(?int $totalItems): static
    {
        $this->totalItems = $totalItems;

        return $this;
    }

    /** TotBultos */
    public function getTotalBultos(): ?int
    {
        return $this->totalBultos;
    }

    public function setTotalBultos(?int $totalBultos): static
    {
        $this->totalBultos = $totalBultos;

        return $this;
    }

    /** MntFlete */
    public function getMontoFlete(): float|int|null
    {
        return $this->montoFlete;
    }

    public function setMontoFlete(float|int|null $montoFlete): static
    {
        $this->montoFlete = $montoFlete;

        return $this;
    }

    /** MntSeguro */
    public function getMontoSeguro(): float|int|null
    {
        return $this->montoSeguro;
    }

    public function setMontoSeguro(float|int|null $montoSeguro): static
    {
        $this->montoSeguro = $montoSeguro;

        return $this;
    }

    /** CodPaisRecep */
    public function getCodigoPaisReceptor(): ?int
    {
        return $this->codigoPaisReceptor;
    }

    public function setCodigoPaisReceptor(?int $codigoPaisReceptor): static
    {
        $this->codigoPaisReceptor = $codigoPaisReceptor;

        return $this;
    }

    /** CodPaisDestin */
    public function getCodigoPaisDestino(): ?int
    {
        return $this->codigoPaisDestino;
    }

    public function setCodigoPaisDestino(?int $codigoPaisDestino): static
    {
        $this->codigoPaisDestino = $codigoPaisDestino;

        return $this;
    }

    /** @return list<Package> */
    public function getBultos(): array
    {
        return $this->bultos;
    }

    /** @param list<Package> $bultos máximo 10 */
    public function setBultos(array $bultos): static
    {
        $this->bultos = array_values($bultos);

        return $this;
    }

    public function addBulto(Package $bulto): static
    {
        $this->bultos[] = $bulto;

        return $this;
    }
}
