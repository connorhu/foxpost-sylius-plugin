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
            ->setLabel('app.ui.foxpost.parcels')
            ->setLabelAttribute('icon', 'tabler:package')
            ->setExtra('routes', [['pattern' => '/^app_admin_foxpost_/']]);

        // Reorder: insert foxpost_parcels before shipments
        $children = array_keys($sales->getChildren());
        $children = array_filter($children, static fn (string $k): bool => $k !== 'foxpost_parcels');
        $pos = array_search('shipments', $children, true);
        if ($pos !== false) {
            array_splice($children, (int) $pos, 0, ['foxpost_parcels']);
        } else {
            $children[] = 'foxpost_parcels';
        }

        $sales->reorderChildren($children);
    }
}
