<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Model;

/**
 * A pickupPointId és a phoneNumber SZÁNDÉKOSAN nincs traitben: mindkettő
 * szolgáltatófüggetlen, ezért a host oszlopa marad. A plugin csak olvassa.
 *
 * A `foxpostSameAsBilling` pár a #99-ben megszűnt: a szállítási cím az 1.
 * lépésen dől el, a Sylius saját `differentShippingAddress` kapcsolójával.
 * A trait ezzel üresre fogyott, ezért törölve.
 */
interface FoxPostShipmentInterface extends DeliveryKindAwareInterface
{
    public function getPickupPointId(): ?string;

    public function getPhoneNumber(): ?string;
}
