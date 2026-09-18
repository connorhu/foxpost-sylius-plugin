<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Model;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

trait FoxPostShippingMethodTrait
{
    #[ORM\Column(name: 'foxpost_default_size', type: Types::STRING, length: 5, nullable: true)]
    private ?string $foxpostDefaultSize = null;

    public function getFoxpostDefaultSize(): ?string
    {
        return $this->foxpostDefaultSize;
    }

    public function setFoxpostDefaultSize(?string $foxpostDefaultSize): void
    {
        $this->foxpostDefaultSize = $foxpostDefaultSize;
    }
}
