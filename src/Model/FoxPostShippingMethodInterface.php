<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Model;

interface FoxPostShippingMethodInterface extends DeliveryKindAwareInterface
{
    public function getFoxpostDefaultSize(): ?string;

    public function setFoxpostDefaultSize(?string $foxpostDefaultSize): void;

    /**
     * Kér-e a mód címzett-telefonszámot. A HOST oszlopa, nem a plugin traité:
     * szolgáltatófüggetlen szabály (a futár is kéri, nem csak a FoxPost).
     */
    public function requiresRecipientPhone(): bool;
}
