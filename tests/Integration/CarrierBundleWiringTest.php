<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Integration;

use CodeConjure\SyliusFoxPostPlugin\DependencyInjection\CodeConjureSyliusFoxPostExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\CoreBundle\Form\Type\Checkout\ShipmentType;
use Sylius\Bundle\ShopBundle\Form\Type\Checkout\SelectShippingType;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * A plugin a #15 óta a közös `codeconjure/sylius-carrier-bundle`-ra épül:
 *
 * - a pénztár két mezőjét és a validációs csoportokat a közös csomag adja,
 *   a FoxPost NEM ad hozzá újra (két azonos nevű `add()` közül a második
 *   felülírná az elsőt, egy mező pedig csak egyszer renderelhető);
 * - a FoxPost a közös csomag két hookjába KAPCSOLÓDIK — a Sylius két foglalt
 *   slotját (a pénztár `shipment` ciklusát és az admin `ship` sablonját)
 *   a host a közös csomag sablonjaira állítja, nem a FoxPostéra.
 */
final class CarrierBundleWiringTest extends TestCase
{
    /** @return iterable<string, array{class-string}> */
    public static function checkoutTypes(): iterable
    {
        yield 'a szállítmány-űrlap' => [ShipmentType::class];
        yield 'a 2. lépés űrlapja' => [SelectShippingType::class];
    }

    /** @param class-string $type */
    #[DataProvider('checkoutTypes')]
    public function testThePluginNoLongerExtendsTheCheckoutForms(string $type): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        (new CodeConjureSyliusFoxPostExtension())->load([], $container);

        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass() ?? $id;
            if (!is_a($class, \Symfony\Component\Form\FormTypeExtensionInterface::class, true)) {
                continue;
            }
            $extended = \call_user_func([$class, 'getExtendedTypes']);
            self::assertNotContains($type, iterator_to_array($extended), \sprintf('A `%s` még mindig a `%s`-t terjeszti ki.', $class, $type));
        }
    }

    public function testThePickupPointWidgetJoinsTheCarrierFieldsHook(): void
    {
        $hook = $this->hooks()['sylius_shop.checkout.select_shipping.content.form.shipments.shipment.carrier_fields'] ?? null;
        self::assertIsArray($hook);
        self::assertSame(
            ['template' => '@CodeConjureSyliusFoxPostPlugin/shop/checkout/select_shipping/pickup_point_widget.html.twig'],
            $hook['foxpost'] ?? null,
        );
    }

    public function testTheAdminActionJoinsTheCarrierShipHook(): void
    {
        $hook = $this->hooks()['codeconjure_carrier.admin.order.shipment.ship'] ?? null;
        self::assertIsArray($hook);
        self::assertSame(
            ['template' => '@CodeConjureSyliusFoxPostPlugin/admin/shipment/ship.html.twig'],
            $hook['foxpost'] ?? null,
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function retiredHookables(): iterable
    {
        yield 'a pénztár szállítmány-ciklusa (a közös csomagé)' => ['sylius_shop.checkout.select_shipping.content.form.shipments', 'shipment'];
        yield 'a FoxPost saját mezőpanelje (a közös csomag `carrier_fields`-e váltja)' => ['sylius_shop.checkout.select_shipping.content.form.shipments.shipment', 'foxpost_fields'];
        yield 'az admin `ship` slot (a közös csomagé)' => ['sylius_admin.order.show.content.sections.shipments.item.actions', 'ship'];
    }

    #[DataProvider('retiredHookables')]
    public function testTheSlotsTheCarrierBundleOwnsAreNotClaimedAnyMore(string $hook, string $hookable): void
    {
        $hookables = $this->hooks()[$hook] ?? [];
        self::assertIsArray($hookables);
        self::assertArrayNotHasKey($hookable, $hookables);
    }

    public function testThePluginNoLongerRegistersAFormTheme(): void
    {
        $container = new ContainerBuilder();
        (new CodeConjureSyliusFoxPostExtension())->prepend($container);

        // A `foxpost_pickup_point` mezőtípus és a form-témája megszűnt: a
        // mező a közös csomag sima rejtett mezője.
        self::assertSame([], $container->getExtensionConfig('twig'));
    }

    /** @return array<mixed> */
    private function hooks(): array
    {
        $container = new ContainerBuilder();
        (new CodeConjureSyliusFoxPostExtension())->prepend($container);

        $merged = [];
        foreach ($container->getExtensionConfig('sylius_twig_hooks') as $config) {
            $hooks = $config['hooks'] ?? [];
            self::assertIsArray($hooks);
            foreach ($hooks as $hook => $hookables) {
                self::assertIsString($hook);
                self::assertIsArray($hookables);
                $merged[$hook] = array_merge($merged[$hook] ?? [], $hookables);
            }
        }

        return $merged;
    }
}
