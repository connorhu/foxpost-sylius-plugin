<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Twig;

use CodeConjure\SyliusFoxPostPlugin\CachedParcelLockerProvider;
use CodeConjure\SyliusFoxPostPlugin\Entity\FoxpostParcel;
use CodeConjure\SyliusFoxPostPlugin\Repository\FoxpostParcelRepository;
use Sylius\Component\Core\Model\ShipmentInterface;
use Twig\Extension\RuntimeExtensionInterface;

final class FoxPostRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly CachedParcelLockerProvider $provider,
        private readonly FoxpostParcelRepository $parcelRepository,
    ) {
    }

    /**
     * @return array{name: string|null, address: string|null}
     */
    public function pickupPointInfo(string $pickupPointId): array
    {
        return [
            'name' => $this->provider->getDisplayName($pickupPointId),
            'address' => $this->provider->getDisplayAddress($pickupPointId),
        ];
    }

    public function foxpostParcelForShipment(ShipmentInterface $shipment): ?FoxpostParcel
    {
        return $this->parcelRepository->findOneByShipment($shipment);
    }
}
