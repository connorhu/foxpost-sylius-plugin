<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Repository;

use CodeConjure\SyliusFoxPostPlugin\Entity\FoxpostParcel;
use CodeConjure\SyliusFoxPostPlugin\Entity\FoxpostParcelStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\ResourceRepositoryTrait;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;

/**
 * @extends ServiceEntityRepository<FoxpostParcel>
 * @implements RepositoryInterface<FoxpostParcel>
 *
 * Szándékosan NEM `final`: a `FoxPostTrackingSyncService` a konkrét osztályra
 * van típusozva, és a PHPUnit nem tud `final` osztályról dublőrt készíteni.
 * A leszármaztatás nem tervezett bővítési pont — csak a teszt-dublőr kedvéért
 * van nyitva. Lásd: tests/Unit/Components/FoxPost/FoxPostTrackingSyncServiceTest.php
 */
class FoxpostParcelRepository extends ServiceEntityRepository implements RepositoryInterface
{
    use ResourceRepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FoxpostParcel::class);
    }

    public function findOneByShipment(ShipmentInterface $shipment): ?FoxpostParcel
    {
        $parcel = $this->createQueryBuilder('p')
            ->andWhere('p.shipment = :shipment')
            ->setParameter('shipment', $shipment)
            ->getQuery()
            ->getOneOrNullResult();

        return $parcel instanceof FoxpostParcel ? $parcel : null;
    }

    /** @return list<FoxpostParcel> */
    public function findActiveForTracking(): array
    {
        $parcels = $this->createQueryBuilder('p')
            ->andWhere('p.internalStatus NOT IN (:terminals)')
            ->andWhere('p.barcode IS NOT NULL')
            ->setParameter('terminals', [
                FoxpostParcelStatus::Delivered->value,
                FoxpostParcelStatus::Returned->value,
                FoxpostParcelStatus::Cancelled->value,
                FoxpostParcelStatus::Eligible->value,
                FoxpostParcelStatus::ValidationFailed->value,
                FoxpostParcelStatus::RegistrationFailed->value,
            ])
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            is_array($parcels) ? $parcels : [],
            static fn (mixed $parcel): bool => $parcel instanceof FoxpostParcel,
        ));
    }

    public function createAdminGridQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.createdAt', 'DESC');
    }
}
