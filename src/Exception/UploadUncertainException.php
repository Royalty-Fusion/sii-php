<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Exception;

/**
 * El sobre se transmitió pero no se obtuvo una respuesta válida (corte de red, timeout, error 5xx).
 * No se sabe si el SII lo recibió: NO reutilice los folios ni reenvíe a ciegas.
 *
 * Guarde getState() y reconcílielo después con See::reconcile($estado), que consulta cada documento
 * por tipo, folio, RUT, monto y fecha. Si el SII responde FAU ("no recibido") puede reenviar el mismo
 * sobre (See::sendXml()) con los mismos folios.
 */
final class UploadUncertainException extends WebServiceException
{
    /** @var array<string, mixed> */
    private array $state = [];

    /** @param array<string, mixed> $state */
    public function withState(array $state): self
    {
        $copy = new self($this->getMessage(), $this->getSiiCode(), $this->getResponseBody(), $this->getHttpStatus(), $this->getPrevious());
        $copy->state = $state;

        return $copy;
    }

    /**
     * Estado persistible (mismo formato que SendResult::toArray(), con track_id null).
     *
     * @return array<string, mixed>
     */
    public function getState(): array
    {
        return $this->state;
    }
}
