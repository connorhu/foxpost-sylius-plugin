<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Twig;

use CodeConjure\FoxPost\DeliveryKind;
use CodeConjure\SyliusCarrierBundle\Model\CarrierShippingMethodInterface;
use CodeConjure\SyliusFoxPostPlugin\Model\DeliveryKindAwareInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class FoxPostExtension extends AbstractExtension
{
    public const string PICKUP_POINT_WIDGET_URL = 'https://cdn.foxpost.hu/apt-finder/v1/app/';

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
            // A FoxPost térképes automata-választója (iframe) — a pénztár
            // widgetje (#15) ezt nyitja meg.
            new TwigFunction(
                'foxpost_pickup_point_widget_url',
                [self::class, 'pickupPointWidgetUrl'],
            ),
            // Szándékosan statikus, nem a runtime példánymetódusa: a kérdéshez
            // se API-kliens, se adatbázis nem kell, és így a sablonokba került
            // illetékességi szabály (#193) tesztelhető a runtime három
            // szolgáltatása nélkül.
            new TwigFunction(
                'foxpost_handles_delivery',
                [self::class, 'handlesDelivery'],
            ),
        ];
    }

    public static function pickupPointWidgetUrl(): string
    {
        return self::PICKUP_POINT_WIDGET_URL;
    }

    /**
     * A plugin illetékes-e a kapott szállítási mód / szállítmány átvételi
     * módjáért. Ez a `DeliveryKindAwareInterface` docblockjában leírt szerződés
     * egyetlen helyen kimondva, sablonból is elérhetően: a host slugot ad, a
     * plugin `DeliveryKind::tryFrom()`-mal fordít, és ami nem az övé (pl. a
     * #182 Packeta slugja), arra false jön vissza.
     *
     * A paraméter szándékosan `mixed`: egy olyan bolt, ami a szállítási
     * entitásaira nem tette rá a plugin interfészét, így nem render-hibát kap,
     * hanem csak nem látja a FoxPost blokkokat — ugyanaz a védelem, amit a
     * checkout-sablonok `is defined` ágai adnak.
     */
    public static function handlesDelivery(mixed $subject): bool
    {
        if (!$subject instanceof DeliveryKindAwareInterface && !$subject instanceof CarrierShippingMethodInterface) {
            return false;
        }

        $slug = $subject->getDeliveryKindSlug();

        return null !== $slug && null !== DeliveryKind::tryFrom($slug);
    }
}
