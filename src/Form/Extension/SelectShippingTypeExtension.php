<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Form\Extension;

use CodeConjure\FoxPost\DeliveryKind;
use CodeConjure\SyliusFoxPostPlugin\Model\FoxPostShipmentInterface;
use CodeConjure\SyliusFoxPostPlugin\Model\FoxPostShippingMethodInterface;
use Sylius\Bundle\ShopBundle\Form\Type as ShopTypes;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SelectShippingTypeExtension extends AbstractTypeExtension
{
    public const string RECIPIENT_PHONE_VALIDATION_GROUP = 'recipient_phone_required';

    public const string FOXPOST_PARCEL_LOCKER_VALIDATION_GROUP = 'foxpost_parcel_locker';

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'validation_groups' => function (FormInterface $form) {
                $data = $form->getData();

                if (!$data instanceof OrderInterface) {
                    return ['Default'];
                }

                $groups = ['sylius'];

                foreach ($data->getShipments() as $shipment) {
                    assert($shipment instanceof FoxPostShipmentInterface);
                    $method = $shipment->getMethod();

                    // A címzett-telefon a MÓD flagjéből jön: a futár is kéri,
                    // pedig neki nincs FoxPost deliveryKindja; a személyes
                    // átvétel pedig nem kéri, pedig szintén nincs.
                    if ($method instanceof FoxPostShippingMethodInterface && $method->requiresRecipientPhone()) {
                        $groups[] = self::RECIPIENT_PHONE_VALIDATION_GROUP;
                    }

                    // Az átvételi pont kötelezősége KÜLÖN szabály — ma
                    // véletlenül esik egybe a telefonéval.
                    if ($method instanceof FoxPostShippingMethodInterface
                        && DeliveryKind::tryFrom($method->getDeliveryKindSlug() ?? '') === DeliveryKind::ParcelLocker) {
                        $groups[] = self::FOXPOST_PARCEL_LOCKER_VALIDATION_GROUP;
                    }
                }

                return array_values(array_unique($groups));
            },
        ]);
    }

    public static function getExtendedTypes(): iterable
    {
        return [ShopTypes\Checkout\SelectShippingType::class];
    }
}
