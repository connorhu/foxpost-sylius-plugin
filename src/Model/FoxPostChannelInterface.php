<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Model;

interface FoxPostChannelInterface
{
    public function getFoxpostLabelPageSize(): ?string;

    public function setFoxpostLabelPageSize(?string $foxpostLabelPageSize): void;
}
