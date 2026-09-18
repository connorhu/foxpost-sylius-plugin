<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Model;

use Sylius\Component\Core\Model\ShippingMethodInterface;

/**
 * A ShippingMethodInterface-kiterjesztés a jelenlegi valóságot rögzíti: a
 * host mód-entitása mindig Sylius szállítási mód is. Ez nélkül a
 * FoxPostShipmentInterface::getMethod() visszatérési típusa (Sylius
 * ShippingMethodInterface) és e traité nem lenne összeegyeztethető — sem
 * futásidőben (a host entitása egyszerre mindkettő), sem teszt-stubként.
 */
interface FoxPostShippingMethodInterface extends DeliveryKindAwareInterface, ShippingMethodInterface
{
    public function getFoxpostDefaultSize(): ?string;

    public function setFoxpostDefaultSize(?string $foxpostDefaultSize): void;

    /**
     * Kér-e a mód címzett-telefonszámot. A HOST oszlopa, nem a plugin traité:
     * szolgáltatófüggetlen szabály (a futár is kéri, nem csak a FoxPost).
     */
    public function requiresRecipientPhone(): bool;
}
