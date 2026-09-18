<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Unit;

use CodeConjure\SyliusFoxPostPlugin\FoxPostParcelPayloadFactory;
use CodeConjure\SyliusFoxPostPlugin\Model\FoxPostShipmentInterface;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;

/**
 * A címzett a SZÁLLÍTÁSI címből jön, mindig.
 *
 * A #99 előtt egy `foxpostSameAsBilling` pipa döntötte el, hogy a
 * számlázási vagy a szállítási címet küldjük — a cím azóta az 1. lépésen
 * dől el, a Sylius saját `differentShippingAddress`-ével, tehát az
 * `order.shippingAddress` MINDIG a helyes cím.
 *
 * A telefon regressziója: a #99 előtt a házhozszállítási ág a shipment
 * phoneNumberjét ÜRESEN hagyta (a mező csak a csomagautomata blokkban
 * renderelt), így a csomagfeladás üres számmal ment ki.
 */
final class FoxPostParcelPayloadFactoryTest extends TestCase
{
    private function shipmentFor(string $deliveryKindSlug, ?string $phone): ShipmentInterface&FoxPostShipmentInterface
    {
        $shipping = new Address();
        $shipping->setFirstName('Andrea');
        $shipping->setLastName('Kovács');
        $shipping->setPostcode('1014');
        $shipping->setCity('Budapest');
        $shipping->setStreet('Hess András tér 5.');

        $billing = new Address();
        $billing->setFirstName('Béla');
        $billing->setLastName('Nagy');
        $billing->setPostcode('9999');
        $billing->setCity('Rossz');
        $billing->setStreet('Ide ne kerüljön');

        // A CreateParcelRequest a recipientEmailt kötelezőnek és érvényes
        // formátumúnak várja — enélkül a konstruktor dob, függetlenül attól,
        // melyik címet választja a factory.
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('andrea@example.com');

        $order = $this->createStub(OrderInterface::class);
        $order->method('getShippingAddress')->willReturn($shipping);
        $order->method('getBillingAddress')->willReturn($billing);
        $order->method('getCustomer')->willReturn($customer);
        $order->method('getNumber')->willReturn('000001');

        // INTERSECTION TÍPUS: a factory szignatúrája
        // `ShipmentInterface&FoxPostShipmentInterface` — a `getOrder()` a
        // Syliusé, a plugin interfésze nem deklarálja. Egyetlen interfészre
        // készített stub itt TypeError-t adna a híváskor.
        $shipment = $this->createStubForIntersectionOfInterfaces([
            ShipmentInterface::class,
            FoxPostShipmentInterface::class,
        ]);
        $shipment->method('getOrder')->willReturn($order);
        $shipment->method('getDeliveryKindSlug')->willReturn($deliveryKindSlug);
        $shipment->method('getPhoneNumber')->willReturn($phone);

        // SZÁNDÉKOSAN true: a régi `isFoxpostSameAsBilling() ? billing :
        // shipping` hármas emiatt a (rossz) számlázási címre ágazna. Ha ezt
        // kihagynánk, az unconfigured stub alapértelmezése (false) a régi
        // kódon is véletlenül a helyes ágra futna, és a teszt semmit sem
        // mérne a #99 javításából.
        $shipment->method('isFoxpostSameAsBilling')->willReturn(true);

        return $shipment;
    }

    public function testHomeDeliveryUsesTheShippingAddress(): void
    {
        $request = (new FoxPostParcelPayloadFactory())->buildFromShipment(
            $this->shipmentFor('foxpost_home_delivery', '+36301234567'),
        );

        self::assertSame('1014', $request->recipientZip);
        self::assertSame('Budapest', $request->recipientCity);
        self::assertSame('Hess András tér 5.', $request->recipientAddress);
    }

    public function testHomeDeliveryCarriesTheRecipientPhone(): void
    {
        $request = (new FoxPostParcelPayloadFactory())->buildFromShipment(
            $this->shipmentFor('foxpost_home_delivery', '+36301234567'),
        );

        self::assertSame(
            '+36301234567',
            $request->recipientPhone,
            'A #99 előtt ez üres volt: a telefon mező csak a csomagautomata blokkban renderelt.',
        );
    }
}
