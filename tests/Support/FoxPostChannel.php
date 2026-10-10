<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Support;

use CodeConjure\SyliusFoxPostPlugin\Model\FoxPostChannelInterface;
use CodeConjure\SyliusFoxPostPlugin\Model\FoxPostChannelTrait;
use Sylius\Component\Core\Model\Channel;

/**
 * Egy csatorna, amilyet a bekötött bolt ad: a Sylius `Channel`-je PLUSZ a
 * plugin csatorna-szerződése. A címke lapmérete csatornánként állítható (az
 * admin csatorna-űrlapon), és ez az egyetlen hely, ahol a plugin a hostétól
 * eltérő lapméretet tud kiolvasni.
 */
final class FoxPostChannel extends Channel implements FoxPostChannelInterface
{
    use FoxPostChannelTrait;

    public static function withLabelPageSize(?string $pageSize): self
    {
        $channel = new self();
        $channel->setFoxpostLabelPageSize($pageSize);

        return $channel;
    }
}
