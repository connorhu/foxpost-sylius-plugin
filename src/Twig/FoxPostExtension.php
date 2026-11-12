<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Twig;

use CodeConjure\FoxPost\DeliveryKind;
use CodeConjure\SyliusFoxPostPlugin\Model\DeliveryKindAwareInterface;
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
            // Szándékosan statikus, nem a runtime példánymetódusa: a kérdéshez
            // se API-kliens, se adatbázis nem kell, és így a sablonokba került
            // illetékességi szabály (#193) tesztelhető a runtime három
            // szolgáltatása nélkül.
            new TwigFunction(
                'foxpost_handles_delivery',
                [self::class, 'handlesDelivery'],
            ),
            // A szállító-semleges admin slot (#15) kimenetéről dönt: üres-e,
            // ha a Twig Hooks debug-kommentjeit (`kernel.debug` mellett minden
            // hook és hookable köré kerülnek) nem számítjuk tartalomnak.
            new TwigFunction(
                'codeconjure_hook_output_is_blank',
                [self::class, 'isBlankHookOutput'],
            ),
        ];
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
    /**
     * Egy hook kimenete akkor üres, ha a HTML-kommentek levágása után csak
     * whitespace marad. `kernel.debug` mellett a Twig Hooks egy üres hookot is
     * `<!-- BEGIN HOOK … -->` / `<!-- END HOOK … -->` párral ad vissza — enélkül
     * a vizsgálat nélkül dev-ben a Sylius alapgombja eltűnne.
     */
    public static function isBlankHookOutput(string $html): bool
    {
        return '' === trim((string) preg_replace('/<!--.*?-->/s', '', $html));
    }

    public static function handlesDelivery(mixed $subject): bool
    {
        if (!$subject instanceof DeliveryKindAwareInterface) {
            return false;
        }

        $slug = $subject->getDeliveryKindSlug();

        return null !== $slug && null !== DeliveryKind::tryFrom($slug);
    }
}
