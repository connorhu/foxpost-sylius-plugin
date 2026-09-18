<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin;

use CodeConjure\FoxPost\DeliveryKind;
use CodeConjure\SyliusFoxPostPlugin\Model\FoxPostShipmentInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;

final class EligibilityChecker
{
    public function check(ShipmentInterface&FoxPostShipmentInterface $shipment): EligibilityResult
    {
        $errors = [];
        $warnings = [];

        /** @var OrderInterface $order */
        $order = $shipment->getOrder();

        // Must be a FoxPost delivery kind
        $kind = DeliveryKind::tryFrom($shipment->getDeliveryKindSlug() ?? '');
        if ($kind === null) {
            return EligibilityResult::ineligible([[
                'code' => 'no_delivery_kind',
                'message' => 'A szállítási módhoz nincs FoxPost típus beállítva.',
            ]]);
        }

        // Order must not be cancelled
        if ($order->getState() === OrderInterface::STATE_CANCELLED) {
            return EligibilityResult::ineligible([[
                'code' => 'order_cancelled',
                'message' => 'A rendelés törölve van.',
            ]]);
        }

        // Order must be paid
        if ($order->getPaymentState() !== 'paid') {
            $errors[] = [
                'code' => 'order_not_paid',
                'message' => 'A rendelés még nincs kifizetve.',
            ];
        }

        // APM-specific
        if ($kind === DeliveryKind::ParcelLocker) {
            if (empty($shipment->getPickupPointId())) {
                $errors[] = [
                    'code' => 'missing_locker_id',
                    'message' => 'Hiányzó csomagautomata azonosító.',
                ];
            }
            if (empty($shipment->getPhoneNumber())) {
                $errors[] = [
                    'code' => 'missing_phone',
                    'message' => 'Hiányzó telefonszám.',
                ];
            }
        }

        // HD-specific
        if ($kind === DeliveryKind::HomeDelivery) {
            $address = $shipment->isFoxpostSameAsBilling()
                ? $order->getBillingAddress()
                : $order->getShippingAddress();

            if ($address === null || empty($address->getPostcode())) {
                $errors[] = ['code' => 'missing_zip', 'message' => 'Hiányzó irányítószám.'];
            }
            if ($address === null || empty($address->getCity())) {
                $errors[] = ['code' => 'missing_city', 'message' => 'Hiányzó város.'];
            }
            if ($address === null || empty($address->getStreet())) {
                $errors[] = ['code' => 'missing_address', 'message' => 'Hiányzó utca/házszám.'];
            }
        }

        if (!empty($errors)) {
            return EligibilityResult::ineligible($errors, $warnings);
        }

        return EligibilityResult::eligible($warnings);
    }

    /**
     * @param array<ShipmentInterface&FoxPostShipmentInterface> $shipments
     *
     * @return array<int, EligibilityResult> keyed by shipment id
     */
    public function checkBatch(array $shipments): array
    {
        $results = [];
        foreach ($shipments as $shipment) {
            $results[$shipment->getId()] = $this->check($shipment);
        }

        return $results;
    }
}
