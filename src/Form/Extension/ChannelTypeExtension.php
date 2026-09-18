<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Form\Extension;

use Sylius\Bundle\ChannelBundle\Form\Type\ChannelType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;

final class ChannelTypeExtension extends AbstractTypeExtension
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('foxpostLabelPageSize', ChoiceType::class, [
            'label' => 'app.form.channel.foxpost_label_page_size',
            'required' => false,
            'choices' => [
                'A5' => 'A5',
                'A6' => 'A6',
                'A7' => 'A7',
                '85×85' => '_85X85',
            ],
            'placeholder' => 'app.form.channel.foxpost_label_page_size_placeholder',
        ]);
    }

    public static function getExtendedTypes(): iterable
    {
        return [ChannelType::class];
    }
}
