<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin;

use CodeConjure\FoxPost\Client;
use CodeConjure\SyliusFoxPostPlugin\Repository\FoxpostParcelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;

final class FoxPostTrackingSyncService
{
    public function __construct(
        private readonly Client $apiClient,
        private readonly FoxpostParcelRepository $parcelRepository,
        private readonly EntityManagerInterface $entityManager,
        #[Target('foxpostParcelStateMachine')]
        private readonly WorkflowInterface $workflow,
        #[Autowire(service: 'monolog.logger.foxpost')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function syncActive(): void
    {
        $parcels = $this->parcelRepository->findActiveForTracking();

        foreach ($parcels as $parcel) {
            if ($parcel->getBarcode() === null) {
                continue;
            }

            try {
                $data = $this->apiClient->getTracking($parcel->getBarcode());
            } catch (\Throwable $e) {
                $this->logger->warning('FoxPost tracking sync failed', [
                    'barcode' => $parcel->getBarcode(),
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $rawStatus = $data['status'] ?? null;
            if (!is_scalar($rawStatus)) {
                continue;
            }

            $rawStatus = (string) $rawStatus;
            $parcel->setFoxpostStatus($rawStatus);
            $parcel->setUpdatedAt(new \DateTimeImmutable());

            $transition = match (strtoupper($rawStatus)) {
                'TRANSIT', 'IN_TRANSIT' => 'tracking_in_transit',
                'DELIVERED' => 'tracking_delivered',
                'FAILED', 'NOT_DELIVERED' => 'tracking_failed',
                'RETURNED' => 'tracking_returned',
                default => null,
            };

            if ($transition !== null && $this->workflow->can($parcel, $transition)) {
                $this->workflow->apply($parcel, $transition);
            }
        }

        $this->entityManager->flush();

        $this->logger->info('FoxPost tracking sync complete', ['count' => count($parcels)]);
    }
}
