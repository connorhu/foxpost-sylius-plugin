<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin;

use CodeConjure\FoxPost\Client;
use CodeConjure\FoxPost\DeliveryKind;
use CodeConjure\FoxPost\Exception\FoxPostApiException;
use CodeConjure\SyliusFoxPostPlugin\Entity\FoxpostParcel;
use CodeConjure\SyliusFoxPostPlugin\Model\FoxPostShipmentInterface;
use CodeConjure\SyliusFoxPostPlugin\Repository\FoxpostParcelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;

final class FoxPostParcelRegistrationService
{
    public function __construct(
        private readonly Client $apiClient,
        private readonly FoxPostParcelPayloadFactory $payloadFactory,
        private readonly FoxpostParcelRepository $parcelRepository,
        private readonly EntityManagerInterface $entityManager,
        #[Target('foxpostParcelStateMachine')]
        private readonly WorkflowInterface $workflow,
    ) {
    }

    /**
     * Registers or re-registers a parcel for the given shipment (update-in-place).
     *
     * @param array<string, mixed> $overrides Optional field overrides from the admin form
     *
     * @throws \LogicException if parcel is already registered and cannot be safely overwritten,
     *                          or if the shipment has no resolvable FoxPost delivery kind
     * @throws \InvalidArgumentException if the built payload fails the core DTO's guards
     *                                    (e.g. a blank required field) — recorded on the parcel
     *                                    as registration_fail before being re-thrown
     * @throws FoxPostApiException on API failure
     */
    public function register(ShipmentInterface&FoxPostShipmentInterface $shipment, array $overrides = []): FoxpostParcel
    {
        $parcel = $this->parcelRepository->findOneByShipment($shipment);

        if ($parcel !== null && $parcel->getInternalStatus()->isRegisteredOrBeyond()) {
            throw new \LogicException(sprintf(
                'A csomag már regisztrálva van (állapot: %s, vonalkód: %s). Törölje le a FoxPost oldalon, majd próbálkozzon újra.',
                $parcel->getInternalStatus()->value,
                $parcel->getBarcode(),
            ));
        }

        /** @var \Sylius\Component\Core\Model\OrderInterface $order */
        $order = $shipment->getOrder();

        // A rekordnak léteznie kell, mielőtt a payload-építés esetleg eldobja
        // a bemenetet: a mag DTO-ja (`CreateParcelRequest`) `\InvalidArgumentException`-t
        // dob egy üres kötelező mezőre (pl. `recipientPhone`) — enélkül nem
        // lenne mire ráírni a hibát. Lásd a lenti catch ágat.
        if ($parcel === null) {
            $kind = DeliveryKind::tryFrom($shipment->getDeliveryKindSlug() ?? '');

            if ($kind === null) {
                // Ugyanaz a hiba és üzenet, amit a PayloadFactory::buildFromShipment()
                // is dobna erre — csak korábban, mert rekordot csak érvényes
                // deliveryKind birtokában hozhatunk létre.
                throw new \LogicException(sprintf(
                    'Shipment #%d has no delivery kind snapshot; cannot build FoxPost payload.',
                    $shipment->getId() ?? 0,
                ));
            }

            $parcel = new FoxpostParcel();
            $parcel->setShipment($shipment);
            $parcel->setOrderNumber((string) $order->getNumber());
            $parcel->setDeliveryKind($kind);
            $parcel->setShippingMethodCode($shipment->getMethod()?->getCode());
            // Ideiglenes érték a nem nullázható mezőkre, hogy a rekord bármikor
            // flush-olható legyen — a sikeres ág lentebb felülírja a payload
            // adataival, a bukó ág (lásd catch) érintetlenül hagyja.
            $parcel->setRecipientName('');
            $parcel->setRecipientPhone('');
            $parcel->setRecipientEmail('');
            $this->entityManager->persist($parcel);
        } else {
            $parcel->incrementRetryCount();
        }

        try {
            $request = $this->payloadFactory->buildFromShipment($shipment, $overrides);
        } catch (\InvalidArgumentException $e) {
            $parcel->setLastApiError($e->getMessage());
            $parcel->setLastApiErrorAt(new \DateTimeImmutable());
            $parcel->setUpdatedAt(new \DateTimeImmutable());

            if ($this->workflow->can($parcel, 're_register')) {
                $this->workflow->apply($parcel, 're_register');
            }

            $this->workflow->apply($parcel, 'queue');
            $this->workflow->apply($parcel, 'registration_fail');
            $this->entityManager->flush();

            throw $e;
        }

        // Snapshot the submitted data
        $parcel->setRecipientName($request->recipientName);
        $parcel->setRecipientPhone($request->recipientPhone);
        $parcel->setRecipientEmail($request->recipientEmail);
        $parcel->setDestinationLockerId($request->destinationLockerId);
        $parcel->setRecipientZip($request->recipientZip);
        $parcel->setRecipientCity($request->recipientCity);
        $parcel->setRecipientAddress($request->recipientAddress);
        $parcel->setRecipientCountry($request->recipientCountry);
        $parcel->setSize($request->size);
        $parcel->setCod($request->cod);
        $parcel->setRefCode($request->refCode);
        $parcel->setComment($request->comment);
        $parcel->setDeliveryNote($request->deliveryNote);
        $parcel->setFragile($request->fragile);
        $parcel->setUpdatedAt(new \DateTimeImmutable());
        $parcel->setLastApiError(null);
        $parcel->setLastApiErrorAt(null);

        // re_register transition resets cancelled/failed back to eligible first
        if ($this->workflow->can($parcel, 're_register')) {
            $this->workflow->apply($parcel, 're_register');
        }

        $this->workflow->apply($parcel, 'queue');
        $this->entityManager->flush();

        try {
            $response = $this->apiClient->createParcel($request->toApiPayload());

            if (!isset($response['valid']) || $response['valid'] !== true) {
                throw new FoxPostApiException(
                    'FoxPost API érvénytelen választ adott: ' . json_encode($response),
                );
            }

            $parcels = $response['parcels'] ?? null;
            $firstParcel = is_array($parcels) ? ($parcels[0] ?? null) : null;
            $barcode = is_array($firstParcel) ? ($firstParcel['barcode'] ?? null) : null;

            if (!is_scalar($barcode) || '' === (string) $barcode) {
                throw new FoxPostApiException('FoxPost API nem adott vissza vonalkódot.');
            }

            $parcel->setBarcode((string) $barcode);
            $parcel->setRegisteredAt(new \DateTimeImmutable());
            $parcel->setUpdatedAt(new \DateTimeImmutable());
            $this->workflow->apply($parcel, 'registration_success');
            $this->entityManager->flush();

            return $parcel;
        } catch (FoxPostApiException $e) {
            $parcel->setLastApiError($e->getMessage());
            $parcel->setLastApiErrorAt(new \DateTimeImmutable());
            $parcel->setUpdatedAt(new \DateTimeImmutable());
            $this->workflow->apply($parcel, 'registration_fail');
            $this->entityManager->flush();

            throw $e;
        }
    }

    public function cancel(FoxpostParcel $parcel): void
    {
        if ($parcel->getBarcode() !== null) {
            try {
                $this->apiClient->deleteParcel($parcel->getBarcode());
            } catch (FoxPostApiException $e) {
                $parcel->setLastApiError($e->getMessage());
                $parcel->setLastApiErrorAt(new \DateTimeImmutable());
                $parcel->setUpdatedAt(new \DateTimeImmutable());
                $this->entityManager->flush();

                throw $e;
            }
        }

        $this->workflow->apply($parcel, 'cancel');
        $parcel->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    public function handOver(FoxpostParcel $parcel): void
    {
        // Advance Sylius Shipment first — if the SM rejects the transition we abort
        // before touching the FoxPost workflow state.
        $shipment = $parcel->getShipment();
        if ($shipment->getState() === 'ready') {
            $shipment->setState('shipped');
        }

        $this->workflow->apply($parcel, 'hand_over');
        $parcel->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }
}
