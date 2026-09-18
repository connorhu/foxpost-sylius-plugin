<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin;

use CodeConjure\FoxPost\Client;
use CodeConjure\FoxPost\Exception\FoxPostApiException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class CachedParcelLockerProvider
{
    private const string CACHE_KEY = 'foxpost_parcel_lockers';

    private const int CACHE_TTL_SECONDS = 86400; // 24 óra

    public function __construct(
        private readonly Client $client,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Az összes csomagautomata listája (24 órán át cache-elve).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAll(): array
    {
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            try {
                return $this->client->getParcelLockers();
            } catch (FoxPostApiException) {
                // Ha az API nem elérhető, ne tároljuk cache-ben (újra próbálkozzunk legközelebb)
                $item->expiresAfter(0);

                return [];
            }
        });
    }

    /**
     * Csomagautomata keresése azonosító alapján.
     *
     * @return array<string, mixed>|null
     */
    public function findById(string $pickupPointId): ?array
    {
        foreach ($this->getAll() as $locker) {
            $id = self::toString($locker['place_id'] ?? $locker['foxpost_id'] ?? $locker['id'] ?? '');
            if ($id === $pickupPointId) {
                return $locker;
            }
        }

        return null;
    }

    /**
     * Csomagautomata megjelenítési neve.
     */
    public function getDisplayName(string $pickupPointId): ?string
    {
        $locker = $this->findById($pickupPointId);
        if ($locker === null) {
            return null;
        }

        return self::toString($locker['name'] ?? $locker['place_name'] ?? $locker['operator_name'] ?? $pickupPointId);
    }

    /**
     * Csomagautomata megjelenítési címe.
     */
    public function getDisplayAddress(string $pickupPointId): ?string
    {
        $locker = $this->findById($pickupPointId);
        if ($locker === null) {
            return null;
        }

        if (!empty($locker['address'])) {
            return self::toString($locker['address']);
        }

        $zip = self::toString($locker['zip'] ?? $locker['postal_code'] ?? '');
        $city = self::toString($locker['city'] ?? '');
        $street = self::toString($locker['street'] ?? $locker['place_address'] ?? '');

        return trim("{$zip} {$city}, {$street}") ?: null;
    }

    private static function toString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Cache törlése (pl. admin frissítés után).
     */
    public function invalidateCache(): void
    {
        $this->cache->delete(self::CACHE_KEY);
    }
}
