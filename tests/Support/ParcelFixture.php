<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Support;

use CodeConjure\FoxPost\DeliveryKind;
use CodeConjure\SyliusFoxPostPlugin\Entity\FoxpostParcel;
use CodeConjure\SyliusFoxPostPlugin\Entity\FoxpostParcelStatus;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShipmentInterface;

/**
 * Egy VALÓDI, perzisztált csomagsor a tesztekhez.
 *
 * A #185 és a #198 ugyanarra a tényre épül: a `foxpost_parcel` tábla ma üres
 * (mérve: `SELECT COUNT(*)` → 0), ezért az admin csomaglista soha nem rendelt
 * sort, és a renderelési hibák nem tudtak elsülni. Ez az osztály állítja elő
 * azt a sort, ami élesben az első csomagfeladásnál jönne létre — dublo nélkül,
 * a plugin és a Sylius igazi entitásaiból.
 *
 * Az `id` és a szállítmány `id`-ja reflexióval kerül be: mindkettő
 * adatbázis-generált (`#[ORM\GeneratedValue]`), setter nélkül — a gridnek és
 * az admin sablonoknak viszont pont ezek kellenek (a `path()` hívások
 * paraméterei).
 */
final class ParcelFixture
{
    public const int PARCEL_ID = 11;

    public const int SHIPMENT_ID = 4242;

    public const string ORDER_NUMBER = 'EZ-2026-0042';

    public const string BARCODE = 'CSGA1234567';

    public const string CREATED_AT = '2026-10-08 12:00:00';

    public const string LABEL_GENERATED_AT = '2026-10-09 08:30:00';

    public static function parcel(
        FoxpostParcelStatus $status = FoxpostParcelStatus::LabelReady,
        ?string $barcode = self::BARCODE,
        DeliveryKind $deliveryKind = DeliveryKind::ParcelLocker,
        int $id = self::PARCEL_ID,
        ?ShipmentInterface $shipment = null,
    ): FoxpostParcel {
        $parcel = new FoxpostParcel();
        // A szállítmány alapból a Sylius sima `Shipment`-je: a gridnek és az
        // admin sablonoknak csak az `id`-ja kell. A tömeges feladás tesztje
        // viszont a HOST szállítmányát adja át (`FoxPostShipment`), mert ott a
        // csomagsor a feladás BEMENETE, nem csak egy megjelenítendő érték.
        $parcel->setShipment($shipment ?? self::shipment());
        $parcel->setOrderNumber(self::ORDER_NUMBER);
        $parcel->setDeliveryKind($deliveryKind);
        $parcel->setShippingMethodCode('foxpost_apm');
        $parcel->setBarcode($barcode);
        $parcel->setRecipientName('Teszt Elek');
        $parcel->setRecipientPhone('+36301234567');
        $parcel->setRecipientEmail('teszt@example.com');
        $parcel->setDestinationLockerId('HU1234');
        $parcel->setInternalStatus($status);
        $parcel->setLabelGeneratedAt(new \DateTimeImmutable(self::LABEL_GENERATED_AT));

        self::forceId($parcel, $id);

        // A `createdAt` a konstruktorban kap "most"-ot, setter nélkül — a grid
        // dátum-mezőjére viszont csak determinisztikus értékkel lehet állítani.
        self::forceProperty($parcel, 'createdAt', new \DateTimeImmutable(self::CREATED_AT));

        return $parcel;
    }

    public static function shipment(int $id = self::SHIPMENT_ID): ShipmentInterface
    {
        $shipment = new Shipment();
        self::forceId($shipment, $id);

        return $shipment;
    }

    private static function forceId(object $entity, int $id): void
    {
        self::forceProperty($entity, 'id', $id);
    }

    private static function forceProperty(object $entity, string $property, mixed $value): void
    {
        $reflection = new \ReflectionClass($entity);

        while (!$reflection->hasProperty($property)) {
            $parent = $reflection->getParentClass();
            \assert($parent !== false, \sprintf('A(z) %s osztálynak nincs `%s` property-je.', $entity::class, $property));
            $reflection = $parent;
        }

        $reflection->getProperty($property)->setValue($entity, $value);
    }
}
