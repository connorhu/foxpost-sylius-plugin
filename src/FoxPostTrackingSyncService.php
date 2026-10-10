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

    /**
     * A visszatérési érték a #173 miatt van: a napi cron egyetlen összegző
     * kimenete a záró naplósor volt, és az a repository találatszámát írta —
     * egy teljesen sikertelen futás ugyanazt a számot jelentette, mint egy
     * teljesen sikeres. A hívó (ma a `FoxPostTrackingSyncCommand`) így ki
     * tudja írni a konzolra is, amit a napló megkap.
     */
    public function syncActive(): FoxPostTrackingSyncSummary
    {
        $parcels = $this->parcelRepository->findActiveForTracking();

        $synced = 0;
        $transitions = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($parcels as $parcel) {
            if ($parcel->getBarcode() === null) {
                ++$skipped;

                continue;
            }

            try {
                $data = $this->apiClient->getTracking($parcel->getBarcode());
            } catch (\Throwable $e) {
                ++$failed;

                $this->logger->warning('FoxPost tracking sync failed', [
                    'barcode' => $parcel->getBarcode(),
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $rawStatus = $data['status'] ?? null;
            if (!is_scalar($rawStatus)) {
                ++$skipped;

                continue;
            }

            ++$synced;

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
                ++$transitions;
            }
        }

        $this->entityManager->flush();

        $summary = new FoxPostTrackingSyncSummary(
            found: \count($parcels),
            synced: $synced,
            transitions: $transitions,
            skipped: $skipped,
            failed: $failed,
        );

        $this->logger->info('FoxPost tracking sync complete', $summary->toLogContext());

        return $summary;
    }
}
