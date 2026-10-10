<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Model;

/**
 * A pickupPointId és a phoneNumber SZÁNDÉKOSAN nincs traitben: mindkettő
 * szolgáltatófüggetlen, ezért a TÁROLÁS a hosté — két írható property a bolt
 * szállítmány-entitásán (#116 3.10).
 *
 * A `foxpostSameAsBilling` pár a #99-ben megszűnt: a szállítási cím az 1.
 * lépésen dől el, a Sylius saját `differentShippingAddress` kapcsolójával.
 * A trait ezzel üresre fogyott, ezért törölve.
 *
 * A két SETTER a #199-ben került ide. Addig a plugin csak olvasta a két
 * mezőt (a formot a host építette), most viszont a plugin
 * `Form\Extension\CheckoutShipmentTypeExtension`-je adja hozzá őket a
 * pénztár shipment formjához — a form pedig a property path-on KERESZTÜL ÍR.
 * Getter-only entitáson ez nem néma hiba, de nem is beszédes: mérve a
 * Symfony `PropertyAccessor` a pénztár szállítási lépését
 * `NoSuchPropertyException`-nel állítja le („Can't get a way to read the
 * property \"pickupPointId\""). A követelmény ezért inkább itt, az
 * interfészen áll, ahol a host fordításkor szembesül vele.
 */
interface FoxPostShipmentInterface extends DeliveryKindAwareInterface
{
    public function getPickupPointId(): ?string;

    public function setPickupPointId(?string $pickupPointId): void;

    public function getPhoneNumber(): ?string;

    public function setPhoneNumber(?string $phoneNumber): void;
}
