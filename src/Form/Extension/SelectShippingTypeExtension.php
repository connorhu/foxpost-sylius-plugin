<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Form\Extension;

use CodeConjure\FoxPost\DeliveryKind;
use CodeConjure\SyliusFoxPostPlugin\Model\FoxPostShipmentInterface;
use CodeConjure\SyliusFoxPostPlugin\Model\FoxPostShippingMethodInterface;
use Sylius\Bundle\ShopBundle\Form\Type as ShopTypes;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SelectShippingTypeExtension extends AbstractTypeExtension
{
    public const string FOXPOST_HOME_DELIVERY_TO_INVOICE_ADDRESS_VALIDATION_GROUP = 'foxpost_home_delivery_to_invoice_address';

    public const string FOXPOST_HOME_DELIVERY_VALIDATION_GROUP = 'foxpost_home_delivery';

    public const string FOXPOST_PARCEL_LOCKER_VALIDATION_GROUP = 'foxpost_parcel_locker';

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'validation_groups' => function (FormInterface $form) {
                $data = $form->getData();

                if (!$data instanceof OrderInterface) {
                    return ['Default'];
                }

                foreach ($data->getShipments() as $shipment) {
                    assert($shipment instanceof FoxPostShipmentInterface);
                    $method = $shipment->getMethod();
                    assert($method instanceof ShippingMethodInterface);
                    assert($method instanceof FoxPostShippingMethodInterface);

                    $deliveryKind = DeliveryKind::tryFrom($method->getDeliveryKindSlug() ?? '');

                    if ($deliveryKind === DeliveryKind::HomeDelivery &&
                        $shipment->isFoxpostSameAsBilling() === true) {
                        return [self::FOXPOST_HOME_DELIVERY_TO_INVOICE_ADDRESS_VALIDATION_GROUP];
                    }

                    $validationGroup = match ($deliveryKind) {
                        DeliveryKind::HomeDelivery => ['sylius', self::FOXPOST_HOME_DELIVERY_VALIDATION_GROUP],
                        DeliveryKind::ParcelLocker => ['sylius', self::FOXPOST_PARCEL_LOCKER_VALIDATION_GROUP],
                        default => null,
                    };

                    if ($validationGroup !== null) {
                        return $validationGroup;
                    }
                }

                return ['sylius'];
            },
        ]);
    }

    public static function getExtendedTypes(): iterable
    {
        return [ShopTypes\Checkout\SelectShippingType::class];
    }
}
