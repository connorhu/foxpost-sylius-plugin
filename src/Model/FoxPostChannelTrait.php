<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Model;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

trait FoxPostChannelTrait
{
    #[ORM\Column(name: 'foxpost_label_page_size', type: Types::STRING, length: 10, nullable: true)]
    private ?string $foxpostLabelPageSize = null;

    public function getFoxpostLabelPageSize(): ?string
    {
        return $this->foxpostLabelPageSize;
    }

    public function setFoxpostLabelPageSize(?string $foxpostLabelPageSize): void
    {
        $this->foxpostLabelPageSize = $foxpostLabelPageSize;
    }
}
