<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Controller;

use CodeConjure\FoxPost\Exception\FoxPostApiException;
use CodeConjure\SyliusFoxPostPlugin\EligibilityChecker;
use CodeConjure\SyliusFoxPostPlugin\Entity\FoxpostParcel;
use CodeConjure\SyliusFoxPostPlugin\FoxPostParcelRegistrationService;
use CodeConjure\SyliusFoxPostPlugin\Model\FoxPostShipmentInterface;
use CodeConjure\SyliusFoxPostPlugin\Repository\FoxpostParcelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/*
 * Az útvonal SZÁNDÉKOSAN nem tartalmazza az `/admin` előtagot (#209): azt a
 * `src/Resources/config/routes.yaml` attribute-importja adja
 * `/%sylius_admin.path_name%`-ként, ugyanabból a konténer-paraméterből, amire
 * a Sylius admin tűzfala és a `ROLE_ADMINISTRATION_ACCESS` szabály is épül
 * (`sylius.security.admin_regex`). Bedrótozott `/admin`-nal egy nem
 * alapértelmezett admin-útvonalú boltban ezek a műveletek a tűzfalon KÍVÜL
 * maradnának — mérve: a csomaglista `/backend/foxpost-parcels`-re ment, ez a
 * hat route pedig `/admin/foxpost/...`-on állt.
 */
#[Route('/foxpost/parcels', name: 'app_admin_foxpost_parcel_')]
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

    /**
     * Tömeges feladás a csomaglista kijelöléséből.
     *
     * Az `ids` CSOMAG-azonosítókat tartalmaz, nem szállítmány-azonosítókat
     * (#208). Ez nem választás kérdése: a Sylius admin tömeges mechanizmusa a
     * kijelölt SOROK azonosítóit küldi (`shared/helper/table.html.twig` →
     * `value="{{ row.id }}"`, `bulk-delete.js` → `ids[]`), ennek a gridnek a
     * sorai pedig `FoxpostParcel`-ek — ugyanezt olvassa a tömeges
     * címke-letöltés is. A 0.1.x sor itt szállítmány-azonosítót várt, ezért
     * nem is volt hozzá grid-akció: egy kijelölt #11-es CSOMAG a #11-es
     * SZÁLLÍTMÁNYRA adott volna fel, azaz egy másik rendelésre, pénzbe kerülő
     * API-hívással.
     *
     * A feladás maga továbbra is a szállítmányból építi a payloadot — a
     * csomagsor csak a bemenete: `$parcel->getShipment()`. A művelet jelentése
     * ezzel pontosan az, ami a sor szintű feladás-gombé
     * (`_grid_register_action.html.twig`, `data.shipment.id`): a MÉG NEM
     * feladott csomagok feladása, tipikusan egy elbukott kör újrapróbálása.
     * Egy már feladott csomag ezért nem hiba, hanem KIHAGYOTT: API-hívás nélkül
     * lép tovább, különben a számláló egy ártalmatlan „jelöld ki mindet"-re is
     * hibát jelentene.
     */
    #[Route('/bulk-register', name: 'bulk_register', methods: ['POST'])]
    public function bulkRegister(
        Request $request,
        FoxPostParcelRegistrationService $registrationService,
        EligibilityChecker $eligibilityChecker,
        FoxpostParcelRepository $parcelRepository,
    ): RedirectResponse {
        $parcelIds = $request->request->all('ids');

        if (empty($parcelIds)) {
            $this->addFlash('error', 'Nincsenek kiválasztott csomagok.');

            return $this->redirectToReferer($request);
        }

        $success = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($parcelIds as $parcelId) {
            if (!is_scalar($parcelId)) {
                ++$failed;

                continue;
            }

            $parcel = $parcelRepository->find((int) $parcelId);
            if (!$parcel instanceof FoxpostParcel) {
                ++$failed;

                continue;
            }

            // A már feladott csomag kihagyása itt, a szolgáltatás előtt dől el:
            // a `register()` ugyanerre `\LogicException`-t dobna, az viszont a
            // hibaszámlálóba esne.
            if ($parcel->getInternalStatus()->isRegisteredOrBeyond()) {
                ++$skipped;

                continue;
            }

            $shipment = $parcel->getShipment();
            if (!$shipment instanceof FoxPostShipmentInterface) {
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
            'Tömeges feladás kész: %d sikeres, %d kihagyva (már feladva), %d hibás.',
            $success,
            $skipped,
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
