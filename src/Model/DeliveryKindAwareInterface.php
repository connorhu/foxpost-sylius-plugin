<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Model;

/**
 * A host szállítási entitásai ezen keresztül mondják meg, milyen átvételi mód
 * tartozik hozzájuk. SLUG, nem enum: a szállítási módok enumja a hosté marad,
 * mert nem szolgáltató-specifikus (a #182 Packeta ugyanoda tesz case-t).
 *
 * A plugin a CodeConjure\FoxPost\DeliveryKind::tryFrom()-mal fordít; ami nem az
 * övé, arra null jön vissza, és a plugin nem illetékes.
 */
interface DeliveryKindAwareInterface
{
    public function getDeliveryKindSlug(): ?string;
}
