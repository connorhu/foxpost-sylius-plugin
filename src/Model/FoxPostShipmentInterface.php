<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Model;

/**
 * A pickupPointId és a phoneNumber SZÁNDÉKOSAN nincs a traitben: mindkettő
 * szolgáltatófüggetlen, ezért a host oszlopa marad. A plugin csak olvassa.
 */
interface FoxPostShipmentInterface extends DeliveryKindAwareInterface
{
    public function getPickupPointId(): ?string;

    public function getPhoneNumber(): ?string;

    public function isFoxpostSameAsBilling(): bool;

    public function setFoxpostSameAsBilling(bool $foxpostSameAsBilling): void;
}
