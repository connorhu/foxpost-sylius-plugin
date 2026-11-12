<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Model;

use CodeConjure\SyliusCarrierBundle\Model\CarrierShippingMethodInterface;

/**
 * A `getDeliveryKindSlug()`, a `requiresRecipientPhone()` és a
 * `deliversToPickupPoint()` a közös szállító-szerződésé
 * ({@see CarrierShippingMethodInterface}, #15) — a host egyszer implementálja,
 * minden szállító-plugin ugyanazt olvassa. A FoxPost-sajátja csak a
 * csomagméret alapértéke.
 *
 * A {@see DeliveryKindAwareInterface}-t SZÁNDÉKOSAN nem terjeszti ki, pedig
 * ugyanazt a `getDeliveryKindSlug()`-ot deklarálja: a PHP elfogadja, de a
 * PHPUnit mock-generátora egy ilyen típust nem tud dublőrözni („Interfaces
 * must not declare the same method") — sem itt, sem a host tesztjeiben.
 */
interface FoxPostShippingMethodInterface extends CarrierShippingMethodInterface
{
    public function getFoxpostDefaultSize(): ?string;

    public function setFoxpostDefaultSize(?string $foxpostDefaultSize): void;
}
