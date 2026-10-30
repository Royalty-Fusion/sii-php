<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Folio;

use Psr\Log\LoggerInterface;
use RoyaltyFusion\Sii\Caf\Caf;
use RoyaltyFusion\Sii\Certificate\Certificate;
use RoyaltyFusion\Sii\Environment;
use RoyaltyFusion\Sii\Exception\CafException;
use RoyaltyFusion\Sii\Exception\InvalidArgumentException;
use RoyaltyFusion\Sii\Exception\WebServiceException;
use RoyaltyFusion\Sii\Model\DocumentType;
use RoyaltyFusion\Sii\Util\Rut;
use RoyaltyFusion\Sii\Ws\Portal\HtmlForm;
use RoyaltyFusion\Sii\Ws\Portal\PortalSession;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Timbraje electrónico en el sitio web del SII: solicitar folios (CAF nuevo), consultar el máximo
 * autorizado y reobtener un CAF ya timbrado.
 *
 * EXPERIMENTAL: el SII no publica un web service para obtener folios; esta clase automatiza los
 * formularios de "Timbraje electrónico" (cvc_cgi/dte) autenticándose con el certificado digital.
 * El SII puede cambiar esos formularios sin aviso. Todo CAF descargado se valida con Caf::fromXml()
 * (RUT, tipo y par de claves) antes de retornarlo, por lo que un cambio en el sitio produce una
 * excepción, nunca un CAF incorrecto.
 */
final class SiiFolioService
{
    private const MAX_PASOS = 8;

    private readonly PortalSession $session;
    private readonly string $base;

    public function __construct(
        Certificate $certificate,
        private readonly Environment $environment,
        ?HttpClientInterface $client = null,
        ?LoggerInterface $logger = null,
        ?PortalSession $session = null,
    ) {
        $this->session = $session ?? new PortalSession($certificate, $client, $logger);
        $this->base = 'https://'.$environment->dteHost().'/cvc_cgi/dte/';
    }

    /**
     * Solicita al SII un nuevo rango de folios y retorna el CAF descargado.
     */
    public function solicitar(string $rutEmisor, DocumentType|int $tipo, int $cantidad): Caf
    {
        if ($cantidad < 1) {
            throw new InvalidArgumentException('La cantidad de folios debe ser mayor a 0.');
        }
        $rut = Rut::parse($rutEmisor);
        $tipoDte = $tipo instanceof DocumentType ? $tipo->value : $tipo;
        $values = ['RUT_EMP' => (string) $rut->number, 'DV_EMP' => $rut->dv, 'COD_DOCTO' => (string) $tipoDte, 'CANT_DOCTOS' => (string) $cantidad];

        return $this->runFlow('of_solicita_folios', $values, $rutEmisor, $tipoDte, static function (HtmlForm $form) use ($cantidad): void {
            $max = $form->get('MAX_AUTOR');
            if ($form->has('CANT_DOCTOS') && $max !== null && is_numeric($max) && (int) $max > 0 && $cantidad > (int) $max) {
                throw new CafException(sprintf('El SII autoriza como máximo %d folios en esta solicitud (se pidieron %d).', (int) $max, $cantidad));
            }
        });
    }

    /**
     * Consulta (sin solicitar) la información de timbraje: máximo de folios autorizados, etc.
     */
    public function consultar(string $rutEmisor, DocumentType|int $tipo): FolioInfo
    {
        $rut = Rut::parse($rutEmisor);
        $tipoDte = $tipo instanceof DocumentType ? $tipo->value : $tipo;
        $values = ['RUT_EMP' => (string) $rut->number, 'DV_EMP' => $rut->dv, 'COD_DOCTO' => (string) $tipoDte];

        [$html, $url] = $this->start('of_solicita_folios');
        for ($paso = 0; $paso < self::MAX_PASOS; ++$paso) {
            $form = $this->findForm($html, $url);
            if ($form->has('CANT_DOCTOS')) {
                // Página de solicitud: se lee la información y NO se envía.
                $max = $form->get('MAX_AUTOR');

                return new FolioInfo($tipoDte, $max !== null && is_numeric($max) ? (int) $max : null, $form->fields, HtmlForm::text($html));
            }
            [$html, $url] = $this->submit($this->fill($form, $values));
        }

        throw new WebServiceException('No se llegó a la página de solicitud de folios del SII.');
    }

    /**
     * Reobtiene (vuelve a descargar) un CAF ya timbrado, identificado por su folio inicial.
     */
    public function reobtener(string $rutEmisor, DocumentType|int $tipo, int $folioDesde): Caf
    {
        $rut = Rut::parse($rutEmisor);
        $tipoDte = $tipo instanceof DocumentType ? $tipo->value : $tipo;
        $values = ['RUT_EMP' => (string) $rut->number, 'DV_EMP' => $rut->dv, 'COD_DOCTO' => (string) $tipoDte, 'FOLIO_INI' => (string) $folioDesde, 'FOLIO_INICIAL' => (string) $folioDesde];

        $caf = $this->runFlow('rf_reobtencion1_folios', $values, $rutEmisor, $tipoDte);
        if ($caf->getFolioDesde() !== $folioDesde) {
            throw new CafException(sprintf('El SII entregó el CAF que inicia en el folio %d y se pidió el %d.', $caf->getFolioDesde(), $folioDesde));
        }

        return $caf;
    }

    /**
     * @param array<string, string>        $values
     * @param (callable(HtmlForm): void)|null $beforeSubmit
     */
    private function runFlow(string $page, array $values, string $rutEmisor, int $tipoDte, ?callable $beforeSubmit = null): Caf
    {
        [$body, $url] = $this->start($page);
        for ($paso = 0; $paso < self::MAX_PASOS; ++$paso) {
            if (str_contains($body, '<AUTORIZACION')) {
                return $this->toCaf($body, $rutEmisor, $tipoDte);
            }
            $form = $this->fill($this->findForm($body, $url), $values);
            if ($beforeSubmit !== null) {
                $beforeSubmit($form);
            }
            [$body, $url] = $this->submit($form);
        }

        throw new WebServiceException('El flujo de timbraje del SII no entregó el archivo CAF.');
    }

    /** @return array{0: string, 1: string} */
    private function start(string $page): array
    {
        $url = $this->base.$page;
        $this->session->login($url);
        [$body, $finalUrl] = $this->session->request('GET', $url);

        return [$body, $finalUrl];
    }

    /** @return array{0: string, 1: string} */
    private function submit(HtmlForm $form): array
    {
        $fields = $form->fields;
        if (($button = $form->primaryButton()) !== null) {
            $fields[$button['name']] = $button['value'];
        }
        if ($form->method === 'GET') {
            $sep = str_contains($form->action, '?') ? '&' : '?';
            [$body, $url] = $this->session->request('GET', $form->action.$sep.http_build_query($fields));
        } else {
            [$body, $url] = $this->session->request('POST', $form->action, $fields);
        }

        return [$body, $url];
    }

    /** @param array<string, string> $values */
    private function fill(HtmlForm $form, array $values): HtmlForm
    {
        foreach ($values as $name => $value) {
            if (!$form->has($name)) {
                continue;
            }
            if (isset($form->options[$name]) && $form->options[$name] !== [] && !\in_array($value, $form->options[$name], true)) {
                throw new CafException(sprintf('El SII no ofrece el valor "%s" para %s (opciones: %s). ¿El emisor está autorizado para este tipo de documento?', $value, $name, implode(', ', $form->options[$name])));
            }
            $form->set($name, $value);
        }

        return $form;
    }

    private function findForm(string $html, string $url): HtmlForm
    {
        foreach (HtmlForm::parseAll($html, $url) as $form) {
            if ($form->fields !== [] || $form->buttons !== []) {
                return $form;
            }
        }
        $text = HtmlForm::text($html);

        throw new WebServiceException('El SII no devolvió el formulario esperado: '.mb_substr($text !== '' ? $text : 'página vacía', 0, 500), null, $html);
    }

    private function toCaf(string $body, string $rutEmisor, int $tipoDte): Caf
    {
        if (!preg_match('#<AUTORIZACION>.*</AUTORIZACION>#s', $body, $m)) {
            throw new CafException('La respuesta del SII no contiene un CAF completo.');
        }
        $caf = Caf::fromXml($m[0]);
        if ($caf->getTipoDte() !== $tipoDte || !Rut::parse($rutEmisor)->equals($caf->getRutEmisor())) {
            throw new CafException(sprintf('El CAF descargado es del tipo %d / RUT %s, no del solicitado.', $caf->getTipoDte(), $caf->getRutEmisor()));
        }
        if ($caf->isCertificacion() === $this->environment->isProduccion()) {
            throw new CafException('El CAF descargado no corresponde al ambiente configurado.');
        }

        return $caf;
    }
}
