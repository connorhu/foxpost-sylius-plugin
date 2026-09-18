<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Model;

use CodeConjure\FoxPost\DeliveryKind;
use CodeConjure\SyliusFoxPostPlugin\Model\FoxPostShipmentInterface;
use CodeConjure\SyliusFoxPostPlugin\Model\FoxPostShipmentTrait;
use PHPUnit\Framework\TestCase;

final class FoxPostShipmentTraitTest extends TestCase
{
    public function testTheTraitDefaultsToSameAsBilling(): void
    {
        self::assertTrue($this->shipment()->isFoxpostSameAsBilling());
    }

    public function testTheTraitStoresTheFlag(): void
    {
        $shipment = $this->shipment();
        $shipment->setFoxpostSameAsBilling(false);

        self::assertFalse($shipment->isFoxpostSameAsBilling());
    }

    public function testOurOwnSlugsResolveToADeliveryKind(): void
    {
        self::assertSame(DeliveryKind::ParcelLocker, DeliveryKind::tryFrom($this->shipment('foxpost_parcel_locker')->getDeliveryKindSlug()));
        self::assertSame(DeliveryKind::HomeDelivery, DeliveryKind::tryFrom($this->shipment('foxpost_home_delivery')->getDeliveryKindSlug()));
    }

    /**
     * A #182 Packetája ugyanezen az oszlopon fog ülni. A plugin nem illetékes rá.
     */
    public function testAForeignSlugResolvesToNothing(): void
    {
        self::assertNull(DeliveryKind::tryFrom($this->shipment('packeta_pickup_point')->getDeliveryKindSlug()));
    }

    private function shipment(?string $slug = null): FoxPostShipmentInterface
    {
        return new class($slug) implements FoxPostShipmentInterface {
            use FoxPostShipmentTrait;

            public function __construct(private readonly ?string $slug)
            {
            }

            public function getDeliveryKindSlug(): ?string
            {
                return $this->slug;
            }

            public function getPickupPointId(): ?string
            {
                return null;
            }

            public function getPhoneNumber(): ?string
            {
                return null;
            }
        };
    }
}
