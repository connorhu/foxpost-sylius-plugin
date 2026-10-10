<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Support;

use CodeConjure\FoxPost\DeliveryKind;
use CodeConjure\SyliusFoxPostPlugin\Model\FoxPostShipmentInterface;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Shipping\Model\ShippingMethodInterface;

/**
 * Egy szállítmány, amilyet a befogadó bolt ad: a Sylius `Shipment`-je PLUSZ a
 * plugin host-szerződése (`FoxPostShipmentInterface` + a trait).
 *
 * A plugin szándékosan nem szállít saját szállítmány-entitást — a hostéba
 * épül bele (l. `docs/installation.md`). Ez az osztály ezért nem dublőr,
 * hanem a HOST oldalának a helyettese a tesztekben: ugyanazokat a
 * metódusokat adja, amiket egy bekötött bolt `Shipment`-je.
 *
 * A `FoxPostShipmentTrait` használata a #99-ben szűnt meg: a trait egyetlen
 * tartalma a `foxpostSameAsBilling` oszlop volt, és a szállítási cím azóta a
 * pénztár 1. lépésén dől el, a Sylius saját `differentShippingAddress`
 * kapcsolójával. A két mód-szintű mező (`pickupPointId`, `phoneNumber`)
 * sosem volt a traitben: azok tárolása a hosté (#116 3.10) — a #199 óta
 * írható propertyként, mert a pénztár formját a plugin adja, és egy form a
 * property path-on keresztül ÍR.
 */
final class FoxPostShipment extends Shipment implements FoxPostShipmentInterface
{
    private OrderInterface $foxpostOrder;

    private ?string $pickupPointId;

    private ?string $phoneNumber;

    public function __construct(
        private readonly ?string $deliveryKindSlug = DeliveryKind::ParcelLocker->value,
        ?string $pickupPointId = 'HU1234',
        ?string $phoneNumber = '+36301234567',
        ?OrderInterface $order = null,
        int $id = ParcelFixture::SHIPMENT_ID,
        ?ShippingMethodInterface $method = null,
    ) {
        parent::__construct();

        $this->pickupPointId = $pickupPointId;
        $this->phoneNumber = $phoneNumber;
        $this->foxpostOrder = $order ?? self::order();
        (new \ReflectionClass(Shipment::class))->getProperty('id')->setValue($this, $id);

        // A telefon kötelezőségét a #99 óta a MÓD flagje dönti el, nem a
        // delivery kind — mód nélkül tehát az `EligibilityChecker` sosem
        // jelezne hiányzó telefont, és a tesztek a mért ágat kerülnék el.
        $this->setMethod($method ?? new FoxPostShippingMethod());
    }

    public function getDeliveryKindSlug(): ?string
    {
        return $this->deliveryKindSlug;
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

    public function getOrder(): OrderInterface
    {
        return $this->foxpostOrder;
    }

    /**
     * A megrendelőnek VALÓDI e-mail címe van: a mag `CreateParcelRequest`-je
     * érvényes e-mailt kér (`FILTER_VALIDATE_EMAIL`), különben a
     * payload-építés `\InvalidArgumentException`-nel áll le — vagyis egy
     * customer nélküli rendelés nem a controller logikáját mérné.
     */
    public static function order(string $number = '2026/00042'): Order
    {
        $address = new Address();
        $address->setFirstName('Teszt');
        $address->setLastName('Elek');
        $address->setPostcode('1052');
        $address->setCity('Budapest');
        $address->setStreet('Váci utca 1.');

        $customer = new Customer();
        $customer->setEmail('teszt@example.com');

        $order = new Order();
        $order->setNumber($number);
        // A cím a #99 óta MINDIG a szállítási cím — abból építi a payloadot a
        // `FoxPostParcelPayloadFactory` és azt ellenőrzi az
        // `EligibilityChecker` is. A számlázási cím a valós rendelésen is
        // kitöltött, de FoxPost-oldalon nincs szerepe.
        $order->setShippingAddress($address);
        $order->setBillingAddress(clone $address);
        $order->setCustomer($customer);
        // Az `EligibilityChecker` kifizetett rendelést kér — enélkül minden
        // feladás `order_not_paid`-en bukik, és a teszt nem a mért ágat járná.
        $order->setPaymentState(OrderPaymentStates::STATE_PAID);

        return $order;
    }
}
