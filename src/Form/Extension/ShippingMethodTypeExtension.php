<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Form\Extension;

use Sylius\Bundle\ShippingBundle\Form\Type\ShippingMethodType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Csak a foxpostDefaultSize mezőt adja hozzá. A deliveryKind mező a boltban
 * marad: az a host ShippingDeliveryKindEnum-jára épül, ami szolgáltatófüggetlen
 * — egy másik szállító (Packeta) is ide fog case-t tenni. A plugin Model
 * rétege szándékosan nem ad settert a delivery kindhez (DeliveryKindAwareInterface
 * csak getDeliveryKindSlug()-ot ad), úgyhogy ez a mező nem is költözhetne ide.
 */
final class ShippingMethodTypeExtension extends AbstractTypeExtension
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('foxpostDefaultSize', ChoiceType::class, [
            'label' => 'app.form.shipping_method.foxpost_default_size',
            'required' => false,
            'choices' => [
                'XS' => 'xs',
                'S' => 's',
                'M' => 'm',
                'L' => 'l',
                'XL' => 'xl',
            ],
            'placeholder' => 'app.form.shipping_method.foxpost_default_size_placeholder',
        ]);
    }

    public static function getExtendedTypes(): iterable
    {
        return [ShippingMethodType::class];
    }
}
