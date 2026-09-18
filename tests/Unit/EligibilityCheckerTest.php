<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Unit;

use CodeConjure\FoxPost\DeliveryKind;
use CodeConjure\SyliusFoxPostPlugin\EligibilityChecker;
use CodeConjure\SyliusFoxPostPlugin\EligibilityResult;
use CodeConjure\SyliusFoxPostPlugin\Model\FoxPostShipmentInterface;
use CodeConjure\SyliusFoxPostPlugin\Model\FoxPostShipmentTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShipmentInterface;

/**
 * A FoxPost feladhatóság-ellenőrzés SZERZŐDÉSE.
 *
 * A hibakódok (`no_delivery_kind`, `order_cancelled`, …) az admin felületen
 * jelennek meg, tehát nem belső részletek: a kódkészlet és az, hogy melyik
 * eset melyik kódot adja, kifelé látható viselkedés.
 *
 * A teszt a MAI viselkedést rögzíti — a #116 kiszervezés referencia-készlete,
 * átvéve a boltból a #170 alapján. Adatbázist nem érint: kézzel épített
 * entitásokkal dolgozik.
 */
final class EligibilityCheckerTest extends TestCase
{
    private EligibilityChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new EligibilityChecker();
    }

    // -------------------------------------------------------------------------
    // Korai return ágak — ezek MEGÁLLÍTJÁK az ellenőrzést
    // -------------------------------------------------------------------------

    public function testAShipmentWithoutDeliveryKindIsIneligible(): void
    {
        $shipment = $this->shipment(null);

        $result = $this->checker->check($shipment);

        self::assertFalse($result->eligible);
        self::assertSame(['no_delivery_kind'], $this->codes($result));
    }

    /**
     * A `no_delivery_kind` ág korai `return` — a rendelés állapotát,
     * fizetettségét és a csomagautomata-mezőket már meg sem nézi.
     */
    public function testTheMissingDeliveryKindStopsTheCheck(): void
    {
        $order = $this->order(state: OrderInterface::STATE_CANCELLED, paymentState: 'awaiting_payment');
        $shipment = $this->shipment(null, order: $order);

        $result = $this->checker->check($shipment);

        self::assertSame(['no_delivery_kind'], $this->codes($result));
    }

    public function testACancelledOrderIsIneligible(): void
    {
        $shipment = $this->shipment(
            DeliveryKind::ParcelLocker->value,
            order: $this->order(state: OrderInterface::STATE_CANCELLED),
        );

        $result = $this->checker->check($shipment);

        self::assertFalse($result->eligible);
        self::assertSame(['order_cancelled'], $this->codes($result));
    }

    /**
     * A `order_cancelled` ág is korai `return`: a kifizetetlenség és a hiányzó
     * automata-azonosító sem kerül a listába.
     */
    public function testTheCancelledOrderStopsTheCheck(): void
    {
        $shipment = $this->shipment(
            DeliveryKind::ParcelLocker->value,
            order: $this->order(state: OrderInterface::STATE_CANCELLED, paymentState: 'awaiting_payment'),
        );

        $result = $this->checker->check($shipment);

        self::assertSame(['order_cancelled'], $this->codes($result));
    }

    // -------------------------------------------------------------------------
    // Fizetés
    // -------------------------------------------------------------------------

    /**
     * Minden, ami nem pontosan `paid`, kifizetetlennek számít.
     */
    #[DataProvider('unpaidPaymentStateProvider')]
    public function testAnOrderThatIsNotPaidIsIneligible(string $paymentState): void
    {
        $shipment = $this->parcelLockerShipment($this->order(paymentState: $paymentState));

        $result = $this->checker->check($shipment);

        self::assertFalse($result->eligible);
        self::assertSame(['order_not_paid'], $this->codes($result));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unpaidPaymentStateProvider(): iterable
    {
        yield 'kosár' => ['cart'];
        yield 'fizetésre vár' => ['awaiting_payment'];
        yield 'részben fizetett' => ['partially_paid'];
        yield 'visszatérített' => ['refunded'];
        yield 'nagybetűs paid' => ['PAID'];
    }

    public function testAPaidParcelLockerShipmentIsEligible(): void
    {
        $shipment = $this->parcelLockerShipment();

        $result = $this->checker->check($shipment);

        self::assertTrue($result->eligible);
        self::assertSame([], $result->errors);
        self::assertSame([], $result->warnings);
    }

    // -------------------------------------------------------------------------
    // Csomagautomata (APM)
    // -------------------------------------------------------------------------

    public function testAParcelLockerShipmentWithoutLockerIdIsIneligible(): void
    {
        $shipment = $this->parcelLockerShipment(pickupPointId: null);

        self::assertSame(['missing_locker_id'], $this->codes($this->checker->check($shipment)));
    }

    public function testAnEmptyLockerIdCountsAsMissing(): void
    {
        $shipment = $this->parcelLockerShipment(pickupPointId: '');

        self::assertSame(['missing_locker_id'], $this->codes($this->checker->check($shipment)));
    }

    public function testAParcelLockerShipmentWithoutPhoneNumberIsIneligible(): void
    {
        $shipment = $this->parcelLockerShipment(phoneNumber: null);

        self::assertSame(['missing_phone'], $this->codes($this->checker->check($shipment)));
    }

    public function testAnEmptyPhoneNumberCountsAsMissing(): void
    {
        $shipment = $this->parcelLockerShipment(phoneNumber: '');

        self::assertSame(['missing_phone'], $this->codes($this->checker->check($shipment)));
    }

    /**
     * A nem korai ágak GYŰJTENEK: a felhasználó egyszerre látja az összes hiányt.
     */
    public function testTheNonReturningBranchesCollectEveryError(): void
    {
        $shipment = $this->parcelLockerShipment(
            $this->order(paymentState: 'awaiting_payment'),
            pickupPointId: null,
            phoneNumber: null,
        );

        self::assertSame(
            ['order_not_paid', 'missing_locker_id', 'missing_phone'],
            $this->codes($this->checker->check($shipment)),
        );
    }

    /**
     * A házhozszállítás hibakódjai NEM jelennek meg automata-feladásnál.
     */
    public function testAParcelLockerShipmentIsNotCheckedForAnAddress(): void
    {
        $order = $this->order();
        $order->setBillingAddress(null);
        $order->setShippingAddress(null);
        $shipment = $this->parcelLockerShipment($order);

        self::assertTrue($this->checker->check($shipment)->eligible);
    }

    // -------------------------------------------------------------------------
    // Házhozszállítás (HD)
    // -------------------------------------------------------------------------

    public function testAPaidHomeDeliveryShipmentIsEligible(): void
    {
        $result = $this->checker->check($this->homeDeliveryShipment());

        self::assertTrue($result->eligible);
        self::assertSame([], $result->errors);
    }

    #[DataProvider('missingAddressPartProvider')]
    public function testAHomeDeliveryShipmentWithAnIncompleteAddressIsIneligible(
        string $emptyPart,
        string $expectedCode,
    ): void {
        $address = $this->address();
        match ($emptyPart) {
            'postcode' => $address->setPostcode(null),
            'city' => $address->setCity(null),
            default => $address->setStreet(null),
        };

        $order = $this->order();
        $order->setShippingAddress($address);

        self::assertSame([$expectedCode], $this->codes($this->checker->check($this->homeDeliveryShipment($order))));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function missingAddressPartProvider(): iterable
    {
        yield 'irányítószám nélkül' => ['postcode', 'missing_zip'];
        yield 'város nélkül' => ['city', 'missing_city'];
        yield 'utca nélkül' => ['street', 'missing_address'];
    }

    public function testAHomeDeliveryShipmentWithoutAnAddressReportsEveryAddressError(): void
    {
        $order = $this->order();
        $order->setShippingAddress(null);

        self::assertSame(
            ['missing_zip', 'missing_city', 'missing_address'],
            $this->codes($this->checker->check($this->homeDeliveryShipment($order))),
        );
    }

    /**
     * A szabály: a HD ág mindig a szállítási címet nézi, a számlázási címet
     * soha nem — ez a `foxpostSameAsBilling` megszűnése óta az EGYETLEN
     * igazság (lásd FoxPostParcelPayloadFactoryTest is).
     */
    public function testHomeDeliveryAlwaysUsesTheShippingAddress(): void
    {
        $order = $this->order();
        $order->setBillingAddress($this->address());
        $order->setShippingAddress(new Address());

        $shipment = $this->homeDeliveryShipment($order);

        self::assertSame(
            ['missing_zip', 'missing_city', 'missing_address'],
            $this->codes($this->checker->check($shipment)),
        );
    }

    /**
     * A csomagautomata hibakódjai NEM jelennek meg házhozszállításnál — akkor
     * sem, ha a telefonszám hiányzik.
     */
    public function testHomeDeliveryIsNotCheckedForLockerFields(): void
    {
        $shipment = $this->homeDeliveryShipment(phoneNumber: null);

        self::assertTrue($this->checker->check($shipment)->eligible);
    }

    // -------------------------------------------------------------------------
    // errors vs. warnings
    // -------------------------------------------------------------------------

    /**
     * A `warnings` ma MINDIG üres: a szerződésben szerepel, de a mai kód egyetlen
     * figyelmeztetést sem tölt bele. Ha ez változik, ennek a tesztnek buknia kell.
     */
    public function testTheCheckerNeverProducesWarningsToday(): void
    {
        $shipments = [
            $this->shipment(null),
            $this->shipment(DeliveryKind::ParcelLocker->value, order: $this->order(state: OrderInterface::STATE_CANCELLED)),
            $this->parcelLockerShipment($this->order(paymentState: 'cart')),
            $this->parcelLockerShipment(),
            $this->homeDeliveryShipment(),
        ];

        foreach ($shipments as $shipment) {
            self::assertSame([], $this->checker->check($shipment)->warnings);
        }
    }

    public function testEveryErrorHasACodeAndAMessage(): void
    {
        $shipment = $this->parcelLockerShipment(
            $this->order(paymentState: 'cart'),
            pickupPointId: null,
            phoneNumber: null,
        );

        $result = $this->checker->check($shipment);

        self::assertCount(3, $result->errors);
        foreach ($result->errors as $error) {
            self::assertArrayHasKey('code', $error);
            self::assertArrayHasKey('message', $error);
            self::assertNotSame('', $error['message']);
        }
    }

    // -------------------------------------------------------------------------
    // checkBatch
    // -------------------------------------------------------------------------

    public function testCheckBatchKeepsTheResultsKeyedByShipmentId(): void
    {
        $eligible = $this->parcelLockerShipment();
        $this->setId($eligible, 11);

        $ineligible = $this->shipment(null);
        $this->setId($ineligible, 22);

        $results = $this->checker->checkBatch([$eligible, $ineligible]);

        self::assertSame([11, 22], array_keys($results));
        self::assertTrue($results[11]->eligible);
        self::assertFalse($results[22]->eligible);
    }

    public function testCheckBatchOfNothingIsAnEmptyArray(): void
    {
        self::assertSame([], $this->checker->checkBatch([]));
    }

    // -------------------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function codes(EligibilityResult $result): array
    {
        return array_values(array_map(static fn (array $error): string => $error['code'], $result->errors));
    }

    private function order(string $state = OrderInterface::STATE_NEW, string $paymentState = 'paid'): Order
    {
        $order = new Order();
        $order->setState($state);
        $order->setPaymentState($paymentState);
        // A #99 óta az EligibilityChecker a SZÁLLÍTÁSI címet nézi, tehát az
        // alapértelmezett rendelésnek is azt kell kitöltenie.
        $order->setShippingAddress($this->address());

        return $order;
    }

    private function address(): Address
    {
        $address = new Address();
        $address->setPostcode('1052');
        $address->setCity('Budapest');
        $address->setStreet('Váci utca 1.');

        return $address;
    }

    private function shipment(
        ?string $deliveryKindSlug,
        ?string $pickupPointId = null,
        ?string $phoneNumber = null,
        ?OrderInterface $order = null,
    ): ShipmentInterface&FoxPostShipmentInterface {
        $order ??= $this->order();

        return new class($deliveryKindSlug, $pickupPointId, $phoneNumber, $order) extends Shipment implements FoxPostShipmentInterface {
            // A FoxPostShipmentTrait a FoxPostShipmentInterface (még meglévő)
            // foxpostSameAsBilling-kontraktusát elégíti ki — az
            // EligibilityChecker ezt már nem olvassa, ez itt csak a
            // típusszerződés miatt kell.
            use FoxPostShipmentTrait;

            public function __construct(
                private readonly ?string $deliveryKindSlug,
                private readonly ?string $pickupPoint,
                private readonly ?string $phone,
                private readonly ?OrderInterface $orderOverride,
            ) {
                parent::__construct();
            }

            public function getDeliveryKindSlug(): ?string
            {
                return $this->deliveryKindSlug;
            }

            public function getPickupPointId(): ?string
            {
                return $this->pickupPoint;
            }

            public function getPhoneNumber(): ?string
            {
                return $this->phone;
            }

            public function getOrder(): ?OrderInterface
            {
                return $this->orderOverride;
            }
        };
    }

    private function parcelLockerShipment(
        ?OrderInterface $order = null,
        ?string $pickupPointId = 'HU1234',
        ?string $phoneNumber = '+36301234567',
    ): ShipmentInterface&FoxPostShipmentInterface {
        return $this->shipment(DeliveryKind::ParcelLocker->value, $pickupPointId, $phoneNumber, order: $order);
    }

    private function homeDeliveryShipment(
        ?OrderInterface $order = null,
        ?string $phoneNumber = '+36301234567',
    ): ShipmentInterface&FoxPostShipmentInterface {
        return $this->shipment(DeliveryKind::HomeDelivery->value, phoneNumber: $phoneNumber, order: $order);
    }

    private function setId(ShipmentInterface&FoxPostShipmentInterface $shipment, int $id): void
    {
        $property = new \ReflectionProperty(\Sylius\Component\Shipping\Model\Shipment::class, 'id');
        $property->setValue($shipment, $id);
    }
}
