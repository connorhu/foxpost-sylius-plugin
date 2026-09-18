<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Model;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

trait FoxPostShipmentTrait
{
    #[ORM\Column(name: 'foxpost_same_as_billing', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $foxpostSameAsBilling = true;

    public function isFoxpostSameAsBilling(): bool
    {
        return $this->foxpostSameAsBilling;
    }

    public function setFoxpostSameAsBilling(bool $foxpostSameAsBilling): void
    {
        $this->foxpostSameAsBilling = $foxpostSameAsBilling;
    }
}
