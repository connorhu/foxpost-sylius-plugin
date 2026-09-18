<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class FoxPostExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter(
                'foxpost_pickup_point',
                [FoxPostRuntime::class, 'pickupPointInfo'],
            ),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'foxpost_pickup_point',
                [FoxPostRuntime::class, 'pickupPointInfo'],
            ),
            new TwigFunction(
                'foxpost_parcel_for_shipment',
                [FoxPostRuntime::class, 'foxpostParcelForShipment'],
            ),
            new TwigFunction(
                'foxpost_city_lookup_url',
                [FoxPostRuntime::class, 'cityLookupUrl'],
            ),
        ];
    }
}
