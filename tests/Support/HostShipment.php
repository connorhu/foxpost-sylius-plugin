<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Support;

use CodeConjure\FoxPost\DeliveryKind;
use CodeConjure\SyliusFoxPostPlugin\Model\FoxPostShipmentInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Shipment;

/**
 * Egy szállítmány, amilyet a BEFOGADÓ BOLT ad: a Sylius `Shipment`-je plusz a
 * plugin host-szerződése.
 *
 * A plugin szándékosan nem szállít saját szállítmány-entitást és traitet sem
 * a két mód-szintű mezőre — azok tárolása a hosté (#116 3.10), a plugin csak
 * a formot adja hozzá (#199). Ez az osztály ezért nem dublőr, hanem a HOST
 * oldalának helyettese: pontosan azt a négy metódust adja, amit a
 * `FoxPostShipmentInterface` kér, valódi, írható propertyk fölött — így a
 * form adat-leképezése is VALÓDI a tesztekben.
 */
final class HostShipment extends Shipment implements FoxPostShipmentInterface
{
    private ?string $pickupPointId;

    private ?string $phoneNumber;

    private ?OrderInterface $orderOverride;

    public function __construct(
        private readonly ?string $deliveryKindSlug = DeliveryKind::ParcelLocker->value,
        ?string $pickupPointId = null,
        ?string $phoneNumber = null,
        ?OrderInterface $order = null,
    ) {
        parent::__construct();

        $this->pickupPointId = $pickupPointId;
        $this->phoneNumber = $phoneNumber;
        $this->orderOverride = $order;
    }

    public function getDeliveryKindSlug(): ?string
    {
        return $this->deliveryKindSlug;
    }

    /**
     * A Sylius `Shipment::getOrder()`-je a kapcsolt rendelést adja; a tesztek
     * viszont egy KÉSZ rendelést akarnak átadni, a kapcsolat kiépítése
     * nélkül. A felülírás ezért opcionális: ha nem adtak rendelést, az
     * eredeti viselkedés marad.
     */
    public function getOrder(): ?OrderInterface
    {
        return $this->orderOverride ?? parent::getOrder();
    }

    public function getPickupPointId(): ?string
    {
        return $this->pickupPointId;
    }

    public function setPickupPointId(?string $pickupPointId): void
    {
        $this->pickupPointId = $pickupPointId;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    public function setPhoneNumber(?string $phoneNumber): void
    {
        $this->phoneNumber = $phoneNumber;
    }
}
