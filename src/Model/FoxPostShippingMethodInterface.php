<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Model;

interface FoxPostShippingMethodInterface extends DeliveryKindAwareInterface
{
    public function getFoxpostDefaultSize(): ?string;

    public function setFoxpostDefaultSize(?string $foxpostDefaultSize): void;
}
