<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Controller;

use CodeConjure\FoxPost\Exception\FoxPostApiException;
use CodeConjure\SyliusFoxPostPlugin\EligibilityChecker;
use CodeConjure\SyliusFoxPostPlugin\Entity\FoxpostParcel;
use CodeConjure\SyliusFoxPostPlugin\FoxPostParcelRegistrationService;
use CodeConjure\SyliusFoxPostPlugin\Model\FoxPostShipmentInterface;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/foxpost/parcels', name: 'app_admin_foxpost_parcel_')]
final class FoxPostParcelController extends AbstractController
{
    #[Route('/register/{shipmentId}', name: 'register', methods: ['POST'])]
    public function register(
        Request $request,
        int $shipmentId,
        FoxPostParcelRegistrationService $registrationService,
        EligibilityChecker $eligibilityChecker,
        EntityManagerInterface $em,
    ): RedirectResponse {
        $shipment = $em->find(ShipmentInterface::class, $shipmentId);

        if (!$shipment instanceof ShipmentInterface || !$shipment instanceof FoxPostShipmentInterface) {
            $this->addFlash('error', 'Szállítmány nem található.');

            return $this->redirectToReferer($request);
        }

        $eligibility = $eligibilityChecker->check($shipment);
        if (!$eligibility->eligible) {
            foreach ($eligibility->errors as $error) {
                $this->addFlash('error', $error['message']);
            }

            return $this->redirectToReferer($request);
        }

        $overrides = array_filter([
            'recipientName' => $request->request->get('recipientName'),
            'recipientPhone' => $request->request->get('recipientPhone'),
            'recipientEmail' => $request->request->get('recipientEmail'),
            'destinationLockerId' => $request->request->get('destinationLockerId'),
            'recipientZip' => $request->request->get('recipientZip'),
            'recipientCity' => $request->request->get('recipientCity'),
            'recipientAddress' => $request->request->get('recipientAddress'),
            'size' => $request->request->get('size'),
            'cod' => $request->request->get('cod'),
            'comment' => $request->request->get('comment'),
            'deliveryNote' => $request->request->get('deliveryNote'),
            'fragile' => $request->request->get('fragile'),
        ], fn (mixed $v): bool => $v !== null && $v !== '');

        try {
            $parcel = $registrationService->register($shipment, $overrides);
            $this->addFlash('success', sprintf(
                'FoxPost csomag sikeresen feladva. Vonalkód: %s',
                $parcel->getBarcode(),
            ));
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (FoxPostApiException $e) {
            $this->addFlash('error', 'FoxPost API hiba: ' . $e->getMessage());
        }

        return $this->redirectToReferer($request);
    }

    #[Route('/{id}/cancel', name: 'cancel', methods: ['POST'])]
    public function cancel(
        Request $request,
        FoxpostParcel $parcel,
        FoxPostParcelRegistrationService $registrationService,
    ): RedirectResponse {
        try {
            $registrationService->cancel($parcel);
            $this->addFlash('success', 'FoxPost csomag sikeresen törölve.');
        } catch (FoxPostApiException $e) {
            $this->addFlash('error', 'FoxPost API hiba törléskor: ' . $e->getMessage());
        }

        return $this->redirectToReferer($request);
    }

    #[Route('/{id}/hand-over', name: 'hand_over', methods: ['POST'])]
    public function handOver(
        Request $request,
        FoxpostParcel $parcel,
        FoxPostParcelRegistrationService $registrationService,
    ): RedirectResponse {
        try {
            $registrationService->handOver($parcel);
            $this->addFlash('success', 'Csomag átadva. Szállítmány állapota: szállítás alatt.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Hiba az átadáskor: ' . $e->getMessage());
        }

        return $this->redirectToReferer($request);
    }

    #[Route('/bulk-register', name: 'bulk_register', methods: ['POST'])]
    public function bulkRegister(
        Request $request,
        FoxPostParcelRegistrationService $registrationService,
        EligibilityChecker $eligibilityChecker,
        EntityManagerInterface $em,
    ): RedirectResponse {
        $shipmentIds = $request->request->all('ids');

        if (empty($shipmentIds)) {
            $this->addFlash('error', 'Nincsenek kiválasztott szállítmányok.');

            return $this->redirectToReferer($request);
        }

        $success = 0;
        $failed = 0;

        foreach ($shipmentIds as $shipmentId) {
            if (!is_scalar($shipmentId)) {
                ++$failed;

                continue;
            }

            $shipment = $em->find(ShipmentInterface::class, (int) $shipmentId);
            if (!$shipment instanceof ShipmentInterface || !$shipment instanceof FoxPostShipmentInterface) {
                ++$failed;

                continue;
            }

            $eligibility = $eligibilityChecker->check($shipment);
            if (!$eligibility->eligible) {
                ++$failed;

                continue;
            }

            try {
                $registrationService->register($shipment);
                ++$success;
            } catch (\Exception) {
                ++$failed;
            }
        }

        $this->addFlash('success', sprintf(
            'Tömeges feladás kész: %d sikeres, %d hibás.',
            $success,
            $failed,
        ));

        return $this->redirectToReferer($request);
    }

    private function redirectToReferer(Request $request): RedirectResponse
    {
        $referer = $request->headers->get('referer');
        if ($referer === null) {
            return $this->redirectToRoute('app_admin_foxpost_parcel_index');
        }

        return new RedirectResponse($referer);
    }
}
