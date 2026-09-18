<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin;

use CodeConjure\FoxPost\Client;
use CodeConjure\SyliusFoxPostPlugin\Entity\FoxpostParcel;
use CodeConjure\SyliusFoxPostPlugin\Entity\FoxpostParcelStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;

final class FoxPostLabelService
{
    public function __construct(
        private readonly Client $apiClient,
        private readonly EntityManagerInterface $entityManager,
        #[Target('foxpostParcelStateMachine')]
        private readonly WorkflowInterface $workflow,
    ) {
    }

    /**
     * Generates a label PDF for the given parcels.
     * Parcels without barcodes are skipped.
     *
     * @param FoxpostParcel[] $parcels
     * @param string $pageSize A5|A6|A7|_85X85
     *
     * @return array{pdf: string, skippedIds: int[]}
     */
    public function generateLabel(array $parcels, string $pageSize): array
    {
        $barcodes = [];
        $skippedIds = [];

        foreach ($parcels as $parcel) {
            if ($parcel->getBarcode() === null) {
                $skippedId = $parcel->getId();

                if ($skippedId !== null) {
                    $skippedIds[] = $skippedId;
                }

                continue;
            }
            $barcodes[] = $parcel->getBarcode();
        }

        $pdf = $this->apiClient->getLabel($barcodes, $pageSize);

        $now = new \DateTimeImmutable();
        foreach ($parcels as $parcel) {
            if ($parcel->getBarcode() === null) {
                continue;
            }
            $parcel->setLabelGeneratedAt($now);
            $parcel->setUpdatedAt($now);

            if ($parcel->getInternalStatus() === FoxpostParcelStatus::Registered &&
                $this->workflow->can($parcel, 'generate_label')
            ) {
                $this->workflow->apply($parcel, 'generate_label');
            }
        }

        $this->entityManager->flush();

        return ['pdf' => $pdf, 'skippedIds' => $skippedIds];
    }
}
