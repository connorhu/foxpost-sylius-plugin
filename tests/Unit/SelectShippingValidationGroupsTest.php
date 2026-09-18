<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Unit;

use CodeConjure\SyliusFoxPostPlugin\Form\Extension\SelectShippingTypeExtension;
use CodeConjure\SyliusFoxPostPlugin\Model\FoxPostShipmentInterface;
use CodeConjure\SyliusFoxPostPlugin\Model\FoxPostShippingMethodInterface;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A címzett-telefon kötelezőségét a MÓD flagje dönti el, nem a DeliveryKind.
 *
 * A futáros módoknak (`deliveryKind = null`) is kell telefon, a személyes
 * átvételnek (szintén null) nem — a nullable enum ezt nem tudja elmondani.
 *
 * Lásd: a bolt docs/superpowers/specs/2026-09-18-penztar-szallitasi-lepes-design.md 4.3
 */
final class SelectShippingValidationGroupsTest extends TestCase
{
    /** @return array<string> */
    private function groupsFor(?string $deliveryKindSlug, bool $requiresPhone): array
    {
        // Ugyanaz a metszet-stub minta, mint a shipmenten: getMethod()
        // Sylius ShippingMethodInterface-t ígér, a valós host mód-entitása
        // pedig egyszerre az és FoxPostShippingMethodInterface is — a
        // plugin publikált kontraktusát ezért nem kell emiatt bővíteni.
        $method = $this->createStubForIntersectionOfInterfaces([ShippingMethodInterface::class, FoxPostShippingMethodInterface::class]);
        $method->method('getDeliveryKindSlug')->willReturn($deliveryKindSlug);
        $method->method('requiresRecipientPhone')->willReturn($requiresPhone);

        // A shipmenten a getMethod() a Sylius ShipmentInterface metódusa, nem
        // a FoxPostShipmentInterface-é (az utóbbi csak a plugin-specifikus
        // oszlopokat mondja meg) — a valós host entitás mindkettőt
        // implementálja, ezért itt is a metszetüket stubozzuk.
        $shipment = $this->createStubForIntersectionOfInterfaces([ShipmentInterface::class, FoxPostShipmentInterface::class]);
        $shipment->method('getMethod')->willReturn($method);

        $order = $this->createStub(OrderInterface::class);
        $order->method('getShipments')->willReturn(new ArrayCollection([$shipment]));

        $form = $this->createStub(FormInterface::class);
        $form->method('getData')->willReturn($order);

        $resolver = new OptionsResolver();
        $resolver->setDefined(['validation_groups']);
        (new SelectShippingTypeExtension())->configureOptions($resolver);

        $groups = $resolver->resolve()['validation_groups'];

        return $groups($form);
    }

    public function testACourierMethodWithoutADeliveryKindStillAsksForThePhone(): void
    {
        self::assertContains(
            SelectShippingTypeExtension::RECIPIENT_PHONE_VALIDATION_GROUP,
            $this->groupsFor(null, true),
            'A GLS/MPL futárnak nincs FoxPost deliveryKindja, de kér telefont.',
        );
    }

    public function testPersonalPickupDoesNotAskForThePhone(): void
    {
        self::assertNotContains(
            SelectShippingTypeExtension::RECIPIENT_PHONE_VALIDATION_GROUP,
            $this->groupsFor(null, false),
        );
    }

    public function testTheParcelLockerGroupStillGuardsThePickupPoint(): void
    {
        self::assertContains(
            SelectShippingTypeExtension::FOXPOST_PARCEL_LOCKER_VALIDATION_GROUP,
            $this->groupsFor('foxpost_parcel_locker', true),
        );
    }
}
