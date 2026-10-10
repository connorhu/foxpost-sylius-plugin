<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: 'sylius.menu.admin.main')]
final class AdminMenuListener
{
    public function __invoke(MenuBuilderEvent $event): void
    {
        $menu = $event->getMenu();

        $sales = $menu->getChild('sales');
        if ($sales === null) {
            return;
        }

        $sales->addChild('foxpost_parcels', ['route' => 'app_admin_foxpost_parcel_index'])
            ->setLabel('codeconjure.foxpost.ui.parcels')
            ->setLabelAttribute('icon', 'tabler:package')
            ->setExtra('routes', [['pattern' => '/^app_admin_foxpost_/']]);

        // Átrendezés: a FoxPost-pont a szállítmányok ELÉ kerül.
        //
        // Az `array_values()` nem kozmetika: az `array_filter` MEGTARTJA a
        // kulcsokat, az `array_search` tehát KULCSOT ad vissza, az
        // `array_splice` viszont OFFSZETET vár. Amíg a kiszűrt elem a lista
        // végén volt, a kettő egybeesett; ha nem (pl. a listener kétszer fut,
        // vagy a bolt maga is felvette a pontot), a beszúrás elcsúszik, és a
        // menüpont a szállítmányok UTÁN jelenik meg. Őr:
        // tests/Unit/Menu/AdminMenuListenerTest.php
        $children = array_keys($sales->getChildren());
        $children = array_values(array_filter($children, static fn (string $k): bool => $k !== 'foxpost_parcels'));
        $pos = array_search('shipments', $children, true);
        if ($pos !== false) {
            array_splice($children, (int) $pos, 0, ['foxpost_parcels']);
        } else {
            $children[] = 'foxpost_parcels';
        }

        $sales->reorderChildren($children);
    }
}
