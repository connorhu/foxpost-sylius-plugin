<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Model;

use CodeConjure\SyliusCarrierBundle\Model\CarrierShipmentInterface;

/**
 * A pickupPointId és a phoneNumber szolgáltatófüggetlen: a TÁROLÁS a hosté
 * (két írható property a bolt szállítmány-entitásán, #116 3.10), a
 * SZERZŐDÉS pedig a #15 óta a közös `codeconjure/sylius-carrier-bundle`-é
 * ({@see CarrierShipmentInterface}) — a pénztár két mezőjét is az a csomag
 * adja, egyszer, akárhány szállító-plugin van telepítve.
 *
 * A FoxPost ehhez csak a szállítmány fajta-pillanatképét teszi hozzá
 * ({@see DeliveryKindAwareInterface}).
 */
interface FoxPostShipmentInterface extends CarrierShipmentInterface, DeliveryKindAwareInterface
{
}
