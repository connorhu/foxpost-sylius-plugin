<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Unit\Model;

use CodeConjure\SyliusCarrierBundle\Model\CarrierShipmentInterface;
use CodeConjure\SyliusCarrierBundle\Model\CarrierShippingMethodInterface;
use CodeConjure\SyliusFoxPostPlugin\Model\FoxPostShipmentInterface;
use CodeConjure\SyliusFoxPostPlugin\Model\FoxPostShippingMethodInterface;
use CodeConjure\SyliusFoxPostPlugin\Tests\Support\FoxPostShippingMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A FoxPost host-szerződése a közös szállító-szerződésre épül (#15): a host
 * entitásai EGYSZER implementálják a `codeconjure/sylius-carrier-bundle`
 * interfészeit, és a pénztár két mezőjét a közös csomag adja — nem a FoxPost.
 */
final class CarrierContractTest extends TestCase
{
    /** @return iterable<string, array{class-string, class-string}> */
    public static function contracts(): iterable
    {
        yield 'szállítmány' => [FoxPostShipmentInterface::class, CarrierShipmentInterface::class];
        yield 'szállítási mód' => [FoxPostShippingMethodInterface::class, CarrierShippingMethodInterface::class];
    }

    /**
     * @param class-string $foxpost
     * @param class-string $carrier
     */
    #[DataProvider('contracts')]
    public function testTheFoxPostContractExtendsTheCarrierContract(string $foxpost, string $carrier): void
    {
        self::assertTrue(is_subclass_of($foxpost, $carrier));
    }

    /** @return iterable<string, array{?string, bool}> */
    public static function deliveryKinds(): iterable
    {
        yield 'csomagautomata' => ['foxpost_parcel_locker', true];
        yield 'házhozszállítás' => ['foxpost_home_delivery', false];
        yield 'nem FoxPost-mód' => [null, false];
    }

    #[DataProvider('deliveryKinds')]
    public function testTheParcelLockerIsThePickupPointKind(?string $kind, bool $pickupPoint): void
    {
        // A tesztbeli host-mód ugyanazt a szabályt követi, amit a
        // telepítési leírás a hostnak ajánl.
        self::assertSame($pickupPoint, (new FoxPostShippingMethod(deliveryKindSlug: $kind))->deliversToPickupPoint());
    }
}
