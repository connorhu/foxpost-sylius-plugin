<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Unit;

use CodeConjure\FoxPost\Client;
use CodeConjure\FoxPost\Environment;
use CodeConjure\SyliusFoxPostPlugin\Entity\FoxpostParcel;
use CodeConjure\SyliusFoxPostPlugin\Entity\FoxpostParcelStatus;
use CodeConjure\SyliusFoxPostPlugin\FoxPostTrackingSyncService;
use CodeConjure\SyliusFoxPostPlugin\FoxPostTrackingSyncSummary;
use CodeConjure\SyliusFoxPostPlugin\Repository\FoxpostParcelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * A FoxPost nyomkövetés-szinkron — a FoxPost nyers státuszának leképezése
 * állapotgép-átmenetre.
 *
 * A legmagasabb kockázatú darab: tiszta logika, de rossz leképezésnél a
 * rendelés állapota HAZUDIK a vevőnek (pl. „kiszállítva", ami valójában
 * visszament a feladóhoz).
 *
 * Adatbázist nem érint: a repository dublőr, a HTTP hívás `Http\Mock\Client`.
 * A mag `CodeConjure\FoxPost\Client`-je viszont VALÓDI — a hibakezelése így a
 * mérés része.
 *
 * A boltból átvett referencia-készlet (#170), a #116 kiszervezés részeként.
 */
final class FoxPostTrackingSyncServiceTest extends TestCase
{
    // -------------------------------------------------------------------------
    // A státusz-leképezés
    // -------------------------------------------------------------------------

    /**
     * Mind a hat felismert nyers státusz, és mindegyik kis-nagybetűs alakban is:
     * a `strtoupper()` miatt a FoxPost bármelyik írásmódja jó.
     */
    #[DataProvider('recognizedStatusProvider')]
    public function testARecognizedStatusAppliesTheMatchingTransition(
        string $rawStatus,
        string $expectedTransition,
    ): void {
        $parcel = $this->parcel('CSOMAG1');

        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::once())
            ->method('can')
            ->with(self::identicalTo($parcel), $expectedTransition)
            ->willReturn(true);
        $workflow->expects(self::once())
            ->method('apply')
            ->with(self::identicalTo($parcel), $expectedTransition);

        $this->sync([$parcel], [$this->trackingResponse($rawStatus)], workflow: $workflow);

        self::assertSame($rawStatus, $parcel->getFoxpostStatus());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function recognizedStatusProvider(): iterable
    {
        yield 'TRANSIT' => ['TRANSIT', 'tracking_in_transit'];
        yield 'IN_TRANSIT' => ['IN_TRANSIT', 'tracking_in_transit'];
        yield 'DELIVERED' => ['DELIVERED', 'tracking_delivered'];
        yield 'FAILED' => ['FAILED', 'tracking_failed'];
        yield 'NOT_DELIVERED' => ['NOT_DELIVERED', 'tracking_failed'];
        yield 'RETURNED' => ['RETURNED', 'tracking_returned'];

        yield 'kisbetűs transit' => ['transit', 'tracking_in_transit'];
        yield 'kisbetűs in_transit' => ['in_transit', 'tracking_in_transit'];
        yield 'kisbetűs delivered' => ['delivered', 'tracking_delivered'];
        yield 'kisbetűs failed' => ['failed', 'tracking_failed'];
        yield 'kisbetűs not_delivered' => ['not_delivered', 'tracking_failed'];
        yield 'kisbetűs returned' => ['returned', 'tracking_returned'];
        yield 'vegyes írásmód' => ['DeLiVeReD', 'tracking_delivered'];
    }

    /**
     * Ismeretlen státuszra NEM dobunk és nem lépünk: a FoxPost bármikor
     * bevezethet új státuszt, attól a szinkron nem állhat meg.
     */
    #[DataProvider('unknownStatusProvider')]
    public function testAnUnknownStatusIsRecordedButMovesNothing(string $rawStatus): void
    {
        $parcel = $this->parcel('CSOMAG1');

        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::never())->method('can');
        $workflow->expects(self::never())->method('apply');

        $this->sync([$parcel], [$this->trackingResponse($rawStatus)], workflow: $workflow);

        self::assertSame($rawStatus, $parcel->getFoxpostStatus());
        self::assertSame(FoxpostParcelStatus::HandedOver, $parcel->getInternalStatus());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownStatusProvider(): iterable
    {
        yield 'feladva' => ['CREATED'];
        yield 'automatában' => ['AT_APM'];
        yield 'ismeretlen' => ['VALAMI_UJ_STATUSZ'];
        yield 'üres string' => [''];
        yield 'szóközzel tagolt' => ['IN TRANSIT'];
        yield 'kötőjeles' => ['IN-TRANSIT'];
    }

    /**
     * A leképezés SZIGORÚ: nem előtag-, nem részstring-egyezés.
     */
    public function testTheMappingDoesNotMatchOnSubstrings(): void
    {
        $parcel = $this->parcel('CSOMAG1');

        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::never())->method('apply');

        $this->sync([$parcel], [$this->trackingResponse('DELIVERED_TO_NEIGHBOUR')], workflow: $workflow);

        self::assertSame('DELIVERED_TO_NEIGHBOUR', $parcel->getFoxpostStatus());
    }

    // -------------------------------------------------------------------------
    // A can() hamis ága
    // -------------------------------------------------------------------------

    /**
     * Ha az állapotgép szerint az átmenet nem megengedett (pl. egy már
     * kiszállított csomagra jön újra a DELIVERED), akkor NEM lépünk — de a
     * FoxPost nyers státuszát akkor is eltesszük.
     */
    public function testNoTransitionHappensWhenTheStateMachineRefusesIt(): void
    {
        $parcel = $this->parcel('CSOMAG1');

        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::once())->method('can')->willReturn(false);
        $workflow->expects(self::never())->method('apply');

        $this->sync([$parcel], [$this->trackingResponse('DELIVERED')], workflow: $workflow);

        self::assertSame('DELIVERED', $parcel->getFoxpostStatus());
        self::assertSame(FoxpostParcelStatus::HandedOver, $parcel->getInternalStatus());
    }

    // -------------------------------------------------------------------------
    // A nyers válasz megszűrése
    // -------------------------------------------------------------------------

    /**
     * Nem skalár (vagy hiányzó) státuszra a csomagot ÉRINTETLENÜL hagyjuk —
     * még az `updatedAt` sem mozdul.
     */
    #[DataProvider('unusableResponseProvider')]
    public function testAnUnusableStatusLeavesTheParcelUntouched(string $json): void
    {
        $parcel = $this->parcel('CSOMAG1');
        $parcel->setUpdatedAt(new \DateTimeImmutable('2020-01-01 00:00:00'));

        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::never())->method('apply');

        $this->sync([$parcel], [new Response(200, [], $json)], workflow: $workflow);

        self::assertNull($parcel->getFoxpostStatus());
        self::assertSame('2020-01-01 00:00:00', $parcel->getUpdatedAt()->format('Y-m-d H:i:s'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableResponseProvider(): iterable
    {
        yield 'nincs status kulcs' => ['{"barcode":"CSOMAG1"}'];
        yield 'status null' => ['{"status":null}'];
        yield 'status tömb' => ['{"status":["DELIVERED"]}'];
        yield 'status objektum' => ['{"status":{"name":"DELIVERED"}}'];
        yield 'üres válasz' => ['{}'];
    }

    /**
     * A skalár, de nem string státusz stringgé alakul — így kerül az adatbázis
     * `foxpost_status` mezőjébe.
     */
    #[DataProvider('scalarStatusProvider')]
    public function testAScalarStatusIsCastToString(string $json, string $expected): void
    {
        $parcel = $this->parcel('CSOMAG1');

        $this->sync([$parcel], [new Response(200, [], $json)]);

        self::assertSame($expected, $parcel->getFoxpostStatus());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function scalarStatusProvider(): iterable
    {
        yield 'szám' => ['{"status":42}', '42'];
        yield 'igaz' => ['{"status":true}', '1'];
        yield 'hamis' => ['{"status":false}', ''];
        yield 'tizedes' => ['{"status":1.5}', '1.5'];
    }

    public function testASuccessfulSyncRefreshesTheUpdatedAtTimestamp(): void
    {
        $parcel = $this->parcel('CSOMAG1');
        $parcel->setUpdatedAt(new \DateTimeImmutable('2020-01-01 00:00:00'));

        $this->sync([$parcel], [$this->trackingResponse('CREATED')]);

        self::assertGreaterThan(new \DateTimeImmutable('2020-01-02'), $parcel->getUpdatedAt());
    }

    // -------------------------------------------------------------------------
    // Vonalkód nélküli csomag
    // -------------------------------------------------------------------------

    public function testAParcelWithoutABarcodeIsSkippedWithoutAnyApiCall(): void
    {
        $parcel = $this->parcel(null);

        $httpClient = new MockClient();
        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->expects(self::never())->method('apply');

        $this->syncWithClient([$parcel], $httpClient, workflow: $workflow);

        self::assertCount(0, $httpClient->getRequests());
        self::assertNull($parcel->getFoxpostStatus());
    }

    public function testTheBarcodeOfEachParcelIsAskedFromTheApi(): void
    {
        $this->sync(
            [$this->parcel('CSOMAG1'), $this->parcel('CSOMAG2')],
            [$this->trackingResponse('DELIVERED'), $this->trackingResponse('RETURNED')],
            httpClient: $httpClient = new MockClient(),
        );

        $requests = $httpClient->getRequests();

        self::assertSame('https://webapi.foxpost.hu/api/tracking/CSOMAG1', (string) $requests[0]->getUri());
        self::assertSame('https://webapi.foxpost.hu/api/tracking/CSOMAG2', (string) $requests[1]->getUri());
    }

    // -------------------------------------------------------------------------
    // Hibatűrés
    // -------------------------------------------------------------------------

    /**
     * Egy csomag API-hibája nem állíthatja meg a kört: figyelmeztetést naplózunk,
     * és megyünk tovább a következőre.
     */
    public function testAFailingApiCallIsLoggedAndTheSyncContinues(): void
    {
        $failing = $this->parcel('CSOMAG1');
        $healthy = $this->parcel('CSOMAG2');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'FoxPost tracking sync failed',
                self::callback(static function (array $context): bool {
                    self::assertSame('CSOMAG1', $context['barcode']);
                    self::assertIsString($context['error']);
                    self::assertStringContainsString('HTTP 500', $context['error']);

                    return true;
                }),
            );

        $this->sync(
            [$failing, $healthy],
            [new Response(500, [], 'felrobbant'), $this->trackingResponse('DELIVERED')],
            logger: $logger,
        );

        self::assertNull($failing->getFoxpostStatus());
        self::assertSame('DELIVERED', $healthy->getFoxpostStatus());
    }

    /**
     * Az érvénytelen JSON ugyanígy csak egy figyelmeztetés — a mag `Client`
     * kivételét a szinkron elnyeli.
     */
    public function testAnInvalidJsonResponseIsLoggedAndTheSyncContinues(): void
    {
        $broken = $this->parcel('CSOMAG1');
        $healthy = $this->parcel('CSOMAG2');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $this->sync(
            [$broken, $healthy],
            [new Response(200, [], '<html>hupsz</html>'), $this->trackingResponse('DELIVERED')],
            logger: $logger,
        );

        self::assertNull($broken->getFoxpostStatus());
        self::assertSame('DELIVERED', $healthy->getFoxpostStatus());
    }

    // -------------------------------------------------------------------------
    // Mentés és naplózás
    // -------------------------------------------------------------------------

    public function testTheChangesAreFlushedExactlyOnceForTheWholeRun(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $this->sync(
            [$this->parcel('CSOMAG1'), $this->parcel('CSOMAG2')],
            [$this->trackingResponse('DELIVERED'), $this->trackingResponse('RETURNED')],
            entityManager: $entityManager,
        );
    }

    public function testAnEmptyRunStillFlushesAndLogs(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $logger->expects(self::once())
            ->method('info')
            ->with('FoxPost tracking sync complete', [
                'found' => 0,
                'synced' => 0,
                'transitions' => 0,
                'skipped' => 0,
                'failed' => 0,
            ]);

        $this->sync([], [], entityManager: $entityManager, logger: $logger);
    }

    /**
     * A #173 javítása. KORÁBBAN ez a teszt a mai (hibás) viselkedést
     * rögzítette: a záró napló a repository találatszámát írta
     * (`['count' => 3]`), tehát a vonalkód nélküli és a hibára futott csomag
     * is „feldolgozottnak" számított. A napi cron egyetlen összegző sorából
     * így nem lehetett megmondani, működött-e a szinkron.
     *
     * Ugyanaz a három csomag, mint a #170-es referencia-tesztben: egy
     * vonalkód nélküli (kihagyva, API-hívás sem történik), egy 404-re futó
     * (hiba) és egy sikeres (`DELIVERED`, ami átmenetet is végez).
     */
    public function testTheClosingLogDistinguishesWhatActuallyHappened(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('FoxPost tracking sync complete', [
                'found' => 3,
                'synced' => 1,
                'transitions' => 1,
                'skipped' => 1,
                'failed' => 1,
            ]);

        $this->sync(
            [$this->parcel(null), $this->parcel('CSOMAG1'), $this->parcel('CSOMAG2')],
            [new Response(404, [], 'nincs ilyen'), $this->trackingResponse('DELIVERED')],
            logger: $logger,
        );
    }

    /**
     * A #173 kifejezett elfogadási feltétele: a TELJESEN sikertelen futás
     * `synced: 0`-t jelent. Korábban ez a futás `count: 3`-at írt, vagyis
     * pontosan ugyanazt, mint egy teljesen sikeres.
     */
    public function testACompletelyFailingRunReportsNothingSynced(): void
    {
        $summary = $this->sync(
            [$this->parcel('CSOMAG1'), $this->parcel('CSOMAG2'), $this->parcel('CSOMAG3')],
            [new Response(500, [], 'hiba'), new Response(500, [], 'hiba'), new Response(500, [], 'hiba')],
        );

        self::assertSame(
            ['found' => 3, 'synced' => 0, 'transitions' => 0, 'skipped' => 0, 'failed' => 3],
            $summary->toLogContext(),
        );
    }

    /**
     * A használhatatlan válasz (nincs `status`, vagy nem skalár) nem hiba és
     * nem is szinkron: a csomag érintetlen marad, még az `updatedAt` sem
     * mozdul — l. `testAnUnusableStatusLeavesTheParcelUntouched`. A
     * számlálóban ezért a `skipped` a helye, nem a `synced`-é.
     */
    public function testAnUnusableResponseCountsAsSkippedNotSynced(): void
    {
        $summary = $this->sync(
            [$this->parcel('CSOMAG1')],
            [new Response(200, [], '{"nincs":"statusz"}')],
        );

        self::assertSame(
            ['found' => 1, 'synced' => 0, 'transitions' => 0, 'skipped' => 1, 'failed' => 0],
            $summary->toLogContext(),
        );
    }

    /**
     * A `transitions` a szinkron VALÓDI kimenete: a nyers státusz eltevése
     * önmagában nem mozdítja a rendelést. Egy fel nem ismert státusz tehát
     * `synced`, de nem `transitions` — ez a kettő szándékosan külön szám.
     */
    public function testARecordedStatusWithoutATransitionCountsAsSyncedOnly(): void
    {
        $summary = $this->sync(
            [$this->parcel('CSOMAG1')],
            [$this->trackingResponse('VALAMI_UJ_STATUSZ')],
        );

        self::assertSame(
            ['found' => 1, 'synced' => 1, 'transitions' => 0, 'skipped' => 0, 'failed' => 0],
            $summary->toLogContext(),
        );
    }

    /**
     * A számlálók szerződése: minden csomag pontosan egy kategóriába esik a
     * `synced`/`skipped`/`failed` közül, tehát az összegük a `found`. Enélkül
     * a záró sorból nem lehet megmondani, maradt-e csomag, amiről semmit nem
     * tudunk.
     */
    public function testEveryParcelIsAccountedForExactlyOnce(): void
    {
        $summary = $this->sync(
            [
                $this->parcel(null),
                $this->parcel('CSOMAG1'),
                $this->parcel('CSOMAG2'),
                $this->parcel('CSOMAG3'),
                $this->parcel('CSOMAG4'),
            ],
            [
                new Response(500, [], 'hiba'),
                new Response(200, [], '{"status":{"nem":"skalar"}}'),
                $this->trackingResponse('DELIVERED'),
                $this->trackingResponse('VALAMI_UJ_STATUSZ'),
            ],
        );

        self::assertSame(5, $summary->found);
        self::assertSame(
            $summary->found,
            $summary->synced + $summary->skipped + $summary->failed,
            'Van olyan csomag, ami egyik kategóriába sem esik (vagy kettőbe esik).',
        );
        self::assertLessThanOrEqual(
            $summary->synced,
            $summary->transitions,
            'Átmenet csak szinkronizált csomagon történhet.',
        );
    }

    /**
     * A naplóba és a visszatérési értékbe UGYANAZ megy — a konzol kimenete
     * (`FoxPostTrackingSyncCommand`) így nem tud elcsúszni a naplótól.
     */
    public function testTheCallerGetsTheSameCountersTheLogGets(): void
    {
        $logged = null;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->willReturnCallback(static function (string $message, array $context) use (&$logged): void {
                $logged = $context;
            });

        $summary = $this->sync(
            [$this->parcel('CSOMAG1'), $this->parcel(null)],
            [$this->trackingResponse('DELIVERED')],
            logger: $logger,
        );

        self::assertSame($summary->toLogContext(), $logged);
    }

    // -------------------------------------------------------------------------

    private function parcel(?string $barcode): FoxpostParcel
    {
        $parcel = new FoxpostParcel();
        $parcel->setBarcode($barcode);
        $parcel->setInternalStatus(FoxpostParcelStatus::HandedOver);

        return $parcel;
    }

    private function trackingResponse(string $rawStatus): ResponseInterface
    {
        return new Response(200, [], json_encode(['status' => $rawStatus], \JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<FoxpostParcel> $parcels
     * @param list<ResponseInterface> $responses
     */
    private function sync(
        array $parcels,
        array $responses,
        ?WorkflowInterface $workflow = null,
        ?EntityManagerInterface $entityManager = null,
        ?LoggerInterface $logger = null,
        ?MockClient $httpClient = null,
    ): FoxPostTrackingSyncSummary {
        $httpClient ??= new MockClient();
        foreach ($responses as $response) {
            $httpClient->addResponse($response);
        }

        return $this->syncWithClient($parcels, $httpClient, $workflow, $entityManager, $logger);
    }

    /**
     * @param list<FoxpostParcel> $parcels
     */
    private function syncWithClient(
        array $parcels,
        MockClient $httpClient,
        ?WorkflowInterface $workflow = null,
        ?EntityManagerInterface $entityManager = null,
        ?LoggerInterface $logger = null,
    ): FoxPostTrackingSyncSummary {
        $repository = self::createStub(FoxpostParcelRepository::class);
        $repository->method('findActiveForTracking')->willReturn($parcels);

        if ($workflow === null) {
            $permissiveWorkflow = self::createStub(WorkflowInterface::class);
            $permissiveWorkflow->method('can')->willReturn(true);
            $workflow = $permissiveWorkflow;
        }

        $psr17 = new Psr17Factory();
        $apiClient = new Client(
            $httpClient,
            $psr17,
            $psr17,
            'felhasznalo',
            'jelszo',
            'kulcs',
            Environment::Production,
        );

        $service = new FoxPostTrackingSyncService(
            $apiClient,
            $repository,
            $entityManager ?? self::createStub(EntityManagerInterface::class),
            $workflow,
            $logger ?? self::createStub(LoggerInterface::class),
        );

        return $service->syncActive();
    }
}
