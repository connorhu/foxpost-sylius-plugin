<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin;

use CodeConjure\FoxPost\DeliveryKind;
use CodeConjure\FoxPost\Request\CreateParcelRequest;
use CodeConjure\SyliusFoxPostPlugin\Entity\FoxpostParcel;
use CodeConjure\SyliusFoxPostPlugin\Model\FoxPostShipmentInterface;
use CodeConjure\SyliusFoxPostPlugin\Model\FoxPostShippingMethodInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;

final class FoxPostParcelPayloadFactory
{
    /**
     * Build a CreateParcelRequest from a Shipment, with optional field overrides.
     *
     * Address source: always the order's shipping address.
     *
     * @param array<string, mixed> $overrides
     */
    public function buildFromShipment(ShipmentInterface&FoxPostShipmentInterface $shipment, array $overrides = []): CreateParcelRequest
    {
        /** @var OrderInterface $order */
        $order = $shipment->getOrder();
        $kind = DeliveryKind::tryFrom($shipment->getDeliveryKindSlug() ?? '');

        if ($kind === null) {
            throw new \LogicException(sprintf(
                'Shipment #%d has no delivery kind snapshot; cannot build FoxPost payload.',
                $shipment->getId() ?? 0,
            ));
        }

        // A cím MINDIG a szállítási cím: a #99 óta az 1. lépés állítja elő
        // (a Sylius `differentShippingAddress` kapcsolójával), tehát nincs
        // olyan ág, ahol a számlázási cím lenne a címzetté.
        $address = $order->getShippingAddress();

        $recipientName = trim(
            ($address?->getFirstName() ?? '') . ' ' . ($address?->getLastName() ?? ''),
        );

        $method = $shipment->getMethod();
        $defaultSize = $method instanceof FoxPostShippingMethodInterface
            ? $method->getFoxpostDefaultSize()
            : null;

        $codOverride = $overrides['cod'] ?? null;

        return new CreateParcelRequest(
            recipientName:       self::overrideString($overrides, 'recipientName') ?? $recipientName,
            recipientPhone:      self::overrideString($overrides, 'recipientPhone') ?? ($shipment->getPhoneNumber() ?? ''),
            recipientEmail:      self::overrideString($overrides, 'recipientEmail') ?? ($order->getCustomer()?->getEmail() ?? ''),
            deliveryKind:        $kind,
            destinationLockerId: self::overrideString($overrides, 'destinationLockerId') ?? ($kind === DeliveryKind::ParcelLocker ? $shipment->getPickupPointId() : null),
            recipientZip:        self::overrideString($overrides, 'recipientZip') ?? $address?->getPostcode(),
            recipientCity:       self::overrideString($overrides, 'recipientCity') ?? $address?->getCity(),
            recipientAddress:    self::overrideString($overrides, 'recipientAddress') ?? $address?->getStreet(),
            recipientCountry:    self::overrideString($overrides, 'recipientCountry') ?? 'HU',
            size:                self::overrideString($overrides, 'size') ?? $defaultSize,
            cod:                 is_numeric($codOverride) ? (int) $codOverride : null,
            refCode:             self::overrideString($overrides, 'refCode') ?? $order->getNumber(),
            comment:             self::overrideString($overrides, 'comment'),
            deliveryNote:        self::overrideString($overrides, 'deliveryNote'),
            fragile:             (bool) ($overrides['fragile'] ?? false),
        );
    }

    /**
     * Egy override mezőt ad vissza stringként, vagy null-t, ha nincs megadva (illetve nem skalár).
     *
     * @param array<string, mixed> $overrides
     */
    private static function overrideString(array $overrides, string $key): ?string
    {
        $value = $overrides[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Reconstruct a CreateParcelRequest from a stored FoxpostParcel (for re-registration).
     */
    public function buildFromParcel(FoxpostParcel $parcel): CreateParcelRequest
    {
        return new CreateParcelRequest(
            recipientName:       $parcel->getRecipientName(),
            recipientPhone:      $parcel->getRecipientPhone(),
            recipientEmail:      $parcel->getRecipientEmail(),
            deliveryKind:        $parcel->getDeliveryKind(),
            destinationLockerId: $parcel->getDestinationLockerId(),
            recipientZip:        $parcel->getRecipientZip(),
            recipientCity:       $parcel->getRecipientCity(),
            recipientAddress:    $parcel->getRecipientAddress(),
            recipientCountry:    $parcel->getRecipientCountry(),
            size:                $parcel->getSize(),
            cod:                 $parcel->getCod(),
            refCode:             $parcel->getRefCode(),
            comment:             $parcel->getComment(),
            deliveryNote:        $parcel->getDeliveryNote(),
            fragile:             $parcel->isFragile(),
        );
    }
}
