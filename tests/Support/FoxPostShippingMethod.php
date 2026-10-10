<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Support;

use CodeConjure\SyliusFoxPostPlugin\Model\FoxPostShippingMethodInterface;
use CodeConjure\SyliusFoxPostPlugin\Model\FoxPostShippingMethodTrait;
use Sylius\Component\Core\Model\ShippingMethod;

/**
 * Egy szállítási mód, amilyet a BEFOGADÓ BOLT ad: a Sylius
 * `ShippingMethod`-ja plusz a plugin host-szerződése.
 *
 * A `requiresRecipientPhone()` és a `getDeliveryKindSlug()` szándékosan NEM a
 * plugin traitjéből jön: mindkettő szolgáltatófüggetlen (a futár is kér
 * telefont, pedig nincs FoxPost típusa), ezért a host oszlopa — a trait csak
 * a `foxpostDefaultSize`-t adja. Ez az osztály tehát a host oldalát
 * helyettesíti, nem dublőr.
 */
final class FoxPostShippingMethod extends ShippingMethod implements FoxPostShippingMethodInterface
{
    use FoxPostShippingMethodTrait;

    public function __construct(
        private readonly bool $requiresRecipientPhone = true,
        private readonly ?string $deliveryKindSlug = null,
    ) {
        parent::__construct();
    }

    public function requiresRecipientPhone(): bool
    {
        return $this->requiresRecipientPhone;
    }

    public function getDeliveryKindSlug(): ?string
    {
        return $this->deliveryKindSlug;
    }
}
