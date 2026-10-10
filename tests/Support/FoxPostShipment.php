<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Support;

use CodeConjure\FoxPost\DeliveryKind;
use CodeConjure\SyliusFoxPostPlugin\Model\FoxPostShipmentInterface;
use CodeConjure\SyliusFoxPostPlugin\Model\FoxPostShipmentTrait;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\OrderPaymentStates;

/**
 * Egy szállítmány, amilyet a befogadó bolt ad: a Sylius `Shipment`-je PLUSZ a
 * plugin host-szerződése (`FoxPostShipmentInterface` + a trait).
 *
 * A plugin szándékosan nem szállít saját szállítmány-entitást — a hostéba
 * épül bele (l. `docs/installation.md`). Ez az osztály ezért nem dublőr,
 * hanem a HOST oldalának a helyettese a tesztekben: ugyanazokat a
 * metódusokat adja, amiket egy bekötött bolt `Shipment`-je.
 */
final class FoxPostShipment extends Shipment implements FoxPostShipmentInterface
{
    use FoxPostShipmentTrait;

    private OrderInterface $foxpostOrder;

    public function __construct(
        private readonly ?string $deliveryKindSlug = DeliveryKind::ParcelLocker->value,
        private readonly ?string $pickupPointId = 'HU1234',
        private readonly ?string $phoneNumber = '+36301234567',
        ?OrderInterface $order = null,
        int $id = ParcelFixture::SHIPMENT_ID,
    ) {
        parent::__construct();

        $this->foxpostOrder = $order ?? self::order();
        (new \ReflectionClass(Shipment::class))->getProperty('id')->setValue($this, $id);
    }

    public function getDeliveryKindSlug(): ?string
    {
        return $this->deliveryKindSlug;
    }

    public function getPickupPointId(): ?string
    {
        return $this->pickupPointId;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
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
        $order->setBillingAddress($address);
        $order->setCustomer($customer);
        // Az `EligibilityChecker` kifizetett rendelést kér — enélkül minden
        // feladás `order_not_paid`-en bukik, és a teszt nem a mért ágat járná.
        $order->setPaymentState(OrderPaymentStates::STATE_PAID);

        return $order;
    }
}
