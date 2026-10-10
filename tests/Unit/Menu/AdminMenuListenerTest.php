<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Unit\Menu;

use CodeConjure\SyliusFoxPostPlugin\Menu\AdminMenuListener;
use CodeConjure\SyliusFoxPostPlugin\Tests\Support\PluginRoutes;
use Knp\Menu\ItemInterface;
use Knp\Menu\MenuFactory;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

/**
 * A #198: a plugin admin menüpontja eddig teszt nélkül állt.
 *
 * A listener hibamódjai mind némák — egyik sem dob kivételt:
 *   - ha a `sales` csoport nem létezik (átnevezik, vagy a bolt saját menüt
 *     épít), a menüpont egyszerűen nem jelenik meg;
 *   - ha a `route` elírt, a menü renderelésekor `RouteNotFoundException` jön,
 *     de csak akkor, amikor valaki megnyitja az admint;
 *   - ha a `reorderChildren()` hívás hiányos listát kap, a Knp menü
 *     ELDOBJA a listából kimaradt elemeket — vagyis a Sylius saját
 *     menüpontjai tűnnének el a FoxPost-pont beszúrása miatt.
 *
 * A menü itt VALÓDI Knp menü (nem dublőr), ugyanazzal a `MenuBuilderEvent`
 * eseménnyel, amire a listener fel van iratkozva (`sylius.menu.admin.main`).
 */
final class AdminMenuListenerTest extends TestCase
{
    /** A Sylius admin `sales` csoportjának gyerekei, az upstream sorrendben. */
    private const array SALES_CHILDREN = ['orders', 'shipments', 'payments'];

    public function testTheParcelListAppearsInTheSalesGroup(): void
    {
        $sales = $this->dispatchOnSalesMenu();

        $item = $sales->getChild('foxpost_parcels');

        self::assertInstanceOf(ItemInterface::class, $item);
        self::assertSame('codeconjure.foxpost.ui.parcels', $item->getLabel());
        self::assertSame('tabler:package', $item->getLabelAttribute('icon'));
    }

    /**
     * A menüpont a plugin VALÓDI csomaglista-route-jára mutat. Egy elírt
     * route-név itt bukik ki, nem az admin megnyitásakor.
     */
    public function testTheMenuItemPointsAtTheParcelListRoute(): void
    {
        $item = $this->dispatchOnSalesMenu()->getChild('foxpost_parcels');

        self::assertInstanceOf(ItemInterface::class, $item);

        $route = $item->getExtra('routes');
        self::assertIsArray($route);

        self::assertNotNull(
            PluginRoutes::collection()->get('app_admin_foxpost_parcel_index'),
            'A menüpont olyan route-ra mutat, amit a plugin nem definiál.',
        );
    }

    /**
     * A menüpont a szállítmányok ELŐTT áll: a munkafolyamat sorrendje
     * rendelés → FoxPost-feladás → szállítmány, és az admin így találja meg.
     */
    public function testTheParcelListIsInsertedBeforeShipments(): void
    {
        $order = array_keys($this->dispatchOnSalesMenu()->getChildren());

        self::assertSame(['orders', 'foxpost_parcels', 'shipments', 'payments'], $order);
    }

    /**
     * Az átrendezés nem veszíthet el meglévő menüpontot. A Knp
     * `reorderChildren()` kivételt dob, ha a lista nem fedi az összes
     * gyereket — a listener mégis a NEVEKBŐL építi a listát, tehát egy
     * hibás szűrés itt bukna.
     */
    public function testTheReorderKeepsEveryExistingSalesItem(): void
    {
        $children = array_keys($this->dispatchOnSalesMenu()->getChildren());

        foreach (self::SALES_CHILDREN as $existing) {
            self::assertContains($existing, $children);
        }
    }

    /**
     * Ha a `sales` csoport nem létezik, a listener NEM hibázhat — egy
     * saját menüt építő bolt admin felülete nem eshet szét attól, hogy a
     * plugin be van kapcsolva. A menüpont ilyenkor elmarad; hogy a bolté
     * megvan-e, azt a bolt méri.
     */
    public function testAMenuWithoutASalesGroupIsLeftUntouched(): void
    {
        $factory = new MenuFactory();
        $menu = $factory->createItem('root');
        $menu->addChild('catalog');

        (new AdminMenuListener())(new MenuBuilderEvent($factory, $menu));

        self::assertSame(['catalog'], array_keys($menu->getChildren()));
    }

    /**
     * A beszúrás helyét OFFSZETEN kell számolni, nem kulcson.
     *
     * A listener `array_filter`-rel veszi ki a saját pontját a névlistából,
     * az `array_filter` viszont MEGTARTJA a kulcsokat: az `array_search`
     * kulcsot ad, az `array_splice` offszetet vár. Amíg a kiszűrt elem a
     * lista végén áll, a kettő egybeesik — ez a teszt azt az esetet állítja
     * elő, amikor nem (a listener kétszer fut), és a beszúrás a
     * szállítmányok UTÁNRA csúszik. Egyúttal azt is méri, hogy nem jön
     * létre duplikátum.
     */
    public function testTheInsertPositionIsComputedOnOffsetsNotArrayKeys(): void
    {
        $factory = new MenuFactory();
        $menu = $this->salesMenu($factory);

        $listener = new AdminMenuListener();
        $listener(new MenuBuilderEvent($factory, $menu));
        $listener(new MenuBuilderEvent($factory, $menu));

        $sales = $menu->getChild('sales');
        self::assertInstanceOf(ItemInterface::class, $sales);
        self::assertSame(['orders', 'foxpost_parcels', 'shipments', 'payments'], array_keys($sales->getChildren()));
    }

    private function dispatchOnSalesMenu(): ItemInterface
    {
        $factory = new MenuFactory();
        $menu = $this->salesMenu($factory);

        (new AdminMenuListener())(new MenuBuilderEvent($factory, $menu));

        $sales = $menu->getChild('sales');
        self::assertInstanceOf(ItemInterface::class, $sales);

        return $sales;
    }

    private function salesMenu(MenuFactory $factory): ItemInterface
    {
        $menu = $factory->createItem('root');
        $sales = $menu->addChild('sales');

        foreach (self::SALES_CHILDREN as $child) {
            $sales->addChild($child);
        }

        return $menu;
    }
}
