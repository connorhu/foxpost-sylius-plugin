<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Form\Type;

use CodeConjure\SyliusFoxPostPlugin\CachedParcelLockerProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class FoxPostPickupPointSelectType extends AbstractType
{
    private const string WIDGET_BASE_URL = 'https://cdn.foxpost.hu/apt-finder/v1/app/';

    public function __construct(
        private readonly CachedParcelLockerProvider $lockerProvider,
    ) {
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $formData = $form->getData();
        $pickupPointId = is_scalar($formData) ? (string) $formData : '';

        $pointName = null;
        $pointAddress = null;

        if ($pickupPointId !== '') {
            $pointName = $this->lockerProvider->getDisplayName($pickupPointId);
            $pointAddress = $this->lockerProvider->getDisplayAddress($pickupPointId);
        }

        $view->vars['widget_url'] = $this->buildWidgetUrl($options);
        $view->vars['point_name'] = $pointName;
        $view->vars['point_address'] = $pointAddress;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'discount' => null,
            'desktop_height' => null,
            'tablet_width' => null,
            'tablet_height' => null,
            'mobile_width' => null,
            'mobile_height' => null,
            'error_bubbling' => false,
        ]);

        $resolver->setAllowedTypes('discount', ['null', 'bool', 'int']);
        $resolver->setAllowedTypes('desktop_height', ['null', 'int']);
        $resolver->setAllowedTypes('tablet_width', ['null', 'int']);
        $resolver->setAllowedTypes('tablet_height', ['null', 'int']);
        $resolver->setAllowedTypes('mobile_width', ['null', 'int']);
        $resolver->setAllowedTypes('mobile_height', ['null', 'int']);
    }

    public function getParent(): string
    {
        return HiddenType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'foxpost_pickup_point';
    }

    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $options */
    private function buildWidgetUrl(array $options): string
    {
        $params = [];

        if ($options['discount'] !== null) {
            $params['discount'] = (int) (bool) $options['discount'];
        }

        foreach (['desktop_height', 'tablet_width', 'tablet_height', 'mobile_width', 'mobile_height'] as $key) {
            if (is_numeric($options[$key])) {
                $params[$key] = (int) $options[$key];
            }
        }

        if ($params === []) {
            return self::WIDGET_BASE_URL;
        }

        return self::WIDGET_BASE_URL . '?' . http_build_query($params);
    }
}
