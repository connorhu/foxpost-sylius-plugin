<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Controller;

use CodeConjure\FoxPost\Exception\FoxPostApiException;
use CodeConjure\SyliusFoxPostPlugin\Entity\FoxpostParcel;
use CodeConjure\SyliusFoxPostPlugin\FoxPostLabelService;
use CodeConjure\SyliusFoxPostPlugin\Model\FoxPostChannelInterface;
use CodeConjure\SyliusFoxPostPlugin\Repository\FoxpostParcelRepository;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/foxpost/labels', name: 'app_admin_foxpost_label_')]
final class FoxPostLabelController extends AbstractController
{
    public function __construct(
        private readonly FoxPostLabelService $labelService,
        private readonly ChannelContextInterface $channelContext,
        private readonly FoxpostParcelRepository $parcelRepository,
    ) {
    }

    #[Route('/download/{id}', name: 'download_single', methods: ['GET'])]
    public function downloadSingle(FoxpostParcel $parcel): Response
    {
        if ($parcel->getBarcode() === null) {
            $this->addFlash('error', 'A csomagnak nincs vonalkódja.');

            return $this->redirectToRoute('app_admin_foxpost_parcel_index');
        }

        return $this->generatePdfResponse([$parcel]);
    }

    #[Route('/download-bulk', name: 'download_bulk', methods: ['POST'])]
    public function downloadBulk(Request $request): Response
    {
        $ids = $request->request->all('ids');

        if (empty($ids)) {
            $this->addFlash('error', 'Nincsenek kiválasztott csomagok.');

            return $this->redirect($request->headers->get('referer') ?? '/admin');
        }

        $parcels = $this->parcelRepository->findBy(['id' => $ids]);

        return $this->generatePdfResponse($parcels);
    }

    /** @param FoxpostParcel[] $parcels */
    private function generatePdfResponse(array $parcels): Response
    {
        $channel = $this->channelContext->getChannel();
        $pageSize = $channel instanceof FoxPostChannelInterface
            ? ($channel->getFoxpostLabelPageSize() ?? 'A6')
            : 'A6';

        try {
            $result = $this->labelService->generateLabel($parcels, $pageSize);
        } catch (FoxPostApiException $e) {
            $this->addFlash('error', 'Címke generálás sikertelen: ' . $e->getMessage());

            return $this->redirectToRoute('app_admin_foxpost_parcel_index');
        }

        if (!empty($result['skippedIds'])) {
            $this->addFlash('warning', sprintf(
                '%d csomag kihagyva (nincs vonalkód): %s',
                count($result['skippedIds']),
                implode(', ', $result['skippedIds']),
            ));
        }

        $filename = sprintf('foxpost_cimkek_%s.pdf', date('Y-m-d_His'));

        return new Response($result['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }
}
