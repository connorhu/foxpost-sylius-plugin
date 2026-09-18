<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Twig;

use CodeConjure\SyliusFoxPostPlugin\CachedParcelLockerProvider;
use CodeConjure\SyliusFoxPostPlugin\Entity\FoxpostParcel;
use CodeConjure\SyliusFoxPostPlugin\Repository\FoxpostParcelRepository;
use Sylius\Component\Core\Model\ShipmentInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\RouterInterface;
use Twig\Extension\RuntimeExtensionInterface;

final class FoxPostRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly CachedParcelLockerProvider $provider,
        private readonly FoxpostParcelRepository $parcelRepository,
        private readonly RouterInterface $router,
        private readonly ?string $cityLookupRoute = null,
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

    /**
     * A boltban a Task 14-ben a `codeconjure.foxpost.city_lookup_route`
     * paraméter a mai `app_shop_cities_lookup` route-névre áll. A plugin
     * önmagában erre a route-ra nem támaszkodhat, ezért paraméterből olvas,
     * és ismeretlen/hiányzó route-ra null-t ad — a sablon ekkor a
     * data-attribútumot ki sem teszi.
     */
    public function cityLookupUrl(): ?string
    {
        if ($this->cityLookupRoute === null || $this->cityLookupRoute === '' || $this->cityLookupRoute === 'null') {
            return null;
        }

        try {
            return $this->router->generate($this->cityLookupRoute);
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}
