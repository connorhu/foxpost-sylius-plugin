<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin;

/**
 * Egy nyomkövetés-szinkron futás eredménye.
 *
 * A #173 miatt létezik: a záró napló korábban EGY számot írt
 * (`['count' => count($parcels)]`), a repository találatszámát. Egy
 * teljesen sikertelen futás — minden API-hívás elbukik — ugyanazt a
 * `count: 42`-t jelentette, mint egy teljesen sikeres, tehát a napi cron
 * egyetlen összegző soráról utólag nem lehetett megmondani, működött-e a
 * szinkron.
 *
 * A `found` ezek után is hasznos (a lekérdezés találatszáma), csak nem
 * ugyanaz, mint a szinkron eredménye.
 *
 * A számlálók szerződése:
 *
 *  - `found` — amit a repository adott;
 *  - `synced` — sikeres API-hívás ÉS eltett nyers státusz;
 *  - `transitions` — ahány csomag tényleg állapotot váltott (ez a szinkron
 *    valódi kimenete: a nyers státusz eltevése önmagában nem mozdítja a
 *    rendelést);
 *  - `skipped` — vonalkód nélkül, vagy használhatatlan válasz (ilyenkor a
 *    csomag érintetlen marad, az `updatedAt` sem mozdul);
 *  - `failed` — az API-hívás kivételt dobott.
 *
 * Egy futásban minden csomag pontosan egy kategóriába esik a három közül
 * (`synced`, `skipped`, `failed`), tehát
 * `found === synced + skipped + failed`. A `transitions` ezekre
 * ortogonális: a `synced` részhalmaza.
 */
final readonly class FoxPostTrackingSyncSummary
{
    public function __construct(
        public int $found = 0,
        public int $synced = 0,
        public int $transitions = 0,
        public int $skipped = 0,
        public int $failed = 0,
    ) {
    }

    /**
     * A záró napló kontextusa. A konzol és a napló UGYANEBBŐL az objektumból
     * dolgozik, hogy a kettő ne csúszhasson el egymástól.
     *
     * @return array{found: int, synced: int, transitions: int, skipped: int, failed: int}
     */
    public function toLogContext(): array
    {
        return [
            'found' => $this->found,
            'synced' => $this->synced,
            'transitions' => $this->transitions,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
        ];
    }
}
