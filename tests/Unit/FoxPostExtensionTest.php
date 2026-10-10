<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Unit;

use CodeConjure\SyliusFoxPostPlugin\Model\DeliveryKindAwareInterface;
use CodeConjure\SyliusFoxPostPlugin\Twig\FoxPostExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A sablonokba került illetékességi szabály (#193).
 *
 * A #193-ban két felületi ág költözött a boltból a pluginba, és mindkettő a
 * host enum KÉT KONKRÉT FoxPost case-ére illesztett. A plugin ezt nem teheti:
 * a szállítási módok enumja a hosté (spec 3.3), a plugin slugot kap, és a
 * `DeliveryKind::tryFrom()` dönti el, hogy az övé-e. A `foxpost_handles_delivery`
 * Twig-függvény ennek a szerződésnek a sablonból elérhető alakja — ezért itt a
 * HATÁR a mérés tárgya: a FoxPost slugjaira igen, minden másra nem.
 */
final class FoxPostExtensionTest extends TestCase
{
    #[DataProvider('deliveryKindProvider')]
    public function testOnlyTheOwnDeliveryKindsAreHandled(?string $slug, bool $expected): void
    {
        self::assertSame($expected, FoxPostExtension::handlesDelivery(self::subjectWithSlug($slug)));
    }

    /** @return iterable<string, array{string|null, bool}> */
    public static function deliveryKindProvider(): iterable
    {
        yield 'foxpost házhozszállítás' => ['foxpost_home_delivery', true];
        yield 'foxpost csomagautomata' => ['foxpost_parcel_locker', true];
        // A #182 Packeta ugyanabba a host enumba tesz case-t; a plugin nem illetékes.
        yield 'másik szolgáltató átvételi pontja' => ['packeta_pickup_point', false];
        yield 'futáros mód (nincs delivery kind)' => [null, false];
        yield 'üres slug' => ['', false];
    }

    /**
     * Egy olyan bolt, ami a szállítási entitásaira nem tette rá a plugin
     * interfészét, nem render-hibát kap, hanem csak nem látja a FoxPost
     * blokkokat — ugyanaz a védelem, mint a checkout-sablonok `is defined` ágai.
     */
    #[DataProvider('foreignSubjectProvider')]
    public function testAnythingWithoutThePluginInterfaceIsNotHandled(mixed $subject): void
    {
        self::assertFalse(FoxPostExtension::handlesDelivery($subject));
    }

    /** @return iterable<string, array{mixed}> */
    public static function foreignSubjectProvider(): iterable
    {
        yield 'null' => [null];
        yield 'a határt nem ismerő entitás' => [new \stdClass()];
        yield 'maga a slug, objektum helyett' => ['foxpost_parcel_locker'];
    }

    public function testTheHandlesDeliveryFunctionIsExposedToTwig(): void
    {
        $names = array_map(
            static fn (\Twig\TwigFunction $function): string => $function->getName(),
            (new FoxPostExtension())->getFunctions(),
        );

        self::assertContains('foxpost_handles_delivery', $names);
    }

    private static function subjectWithSlug(?string $slug): DeliveryKindAwareInterface
    {
        return new class($slug) implements DeliveryKindAwareInterface {
            public function __construct(private readonly ?string $slug)
            {
            }

            public function getDeliveryKindSlug(): ?string
            {
                return $this->slug;
            }
        };
    }
}
