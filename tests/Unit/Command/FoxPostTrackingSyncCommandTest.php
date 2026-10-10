<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Unit\Command;

use CodeConjure\FoxPost\Client;
use CodeConjure\FoxPost\Environment;
use CodeConjure\SyliusFoxPostPlugin\Command\FoxPostTrackingSyncCommand;
use CodeConjure\SyliusFoxPostPlugin\Entity\FoxpostParcel;
use CodeConjure\SyliusFoxPostPlugin\Entity\FoxpostParcelStatus;
use CodeConjure\SyliusFoxPostPlugin\FoxPostTrackingSyncService;
use CodeConjure\SyliusFoxPostPlugin\Repository\FoxpostParcelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * A napi cron felülete (#173).
 *
 * A parancs kimenete korábban EGY sor volt — `FoxPost tracking sync
 * complete.` —, és pontosan ugyanígy szólt akkor is, ha minden API-hívás
 * elbukott. A számlálók a záró naplósorban voltak (ott is hibásan, l. #173),
 * a konzolon viszont semmi: aki kézzel futtatta a szinkront, nem látott
 * belőle semmit.
 *
 * A `FoxPostTrackingSyncService` `final`, tehát nem dublőrözhető — itt ez
 * előny: a parancs a VALÓDI szolgáltatást hajtja, a FoxPost API helyén
 * PSR-18 mock HTTP klienssel, úgyhogy a kiírt számok ugyanazon a kódúton
 * keletkeznek, mint éles futásban.
 */
final class FoxPostTrackingSyncCommandTest extends TestCase
{
    public function testTheOutputCarriesEveryCounterOfTheRun(): void
    {
        $tester = $this->runCommand(
            [$this->parcel(null), $this->parcel('CSOMAG1'), $this->parcel('CSOMAG2')],
            [new Response(500, [], 'hiba'), $this->trackingResponse('DELIVERED')],
        );

        $output = $tester->getDisplay();

        self::assertStringContainsString('FoxPost tracking sync complete.', $output);
        self::assertStringContainsString('found:      3', $output);
        self::assertStringContainsString('synced:     1', $output);
        self::assertStringContainsString('transitions: 1', $output);
        self::assertStringContainsString('skipped:    1', $output);
        self::assertStringContainsString('failed:     1', $output);
    }

    /**
     * A különbség, amiért a #173 létezik: a teljesen sikertelen futás
     * kimenete ma MÁS, mint a sikeresé. (A kilépési kód szándékosan nem
     * változott: az a cron szerződése, és egy részlegesen hibás futás eddig
     * sem jelzett hibát. Ha ez kell, az külön döntés.)
     */
    public function testACompletelyFailingRunIsVisibleInTheOutput(): void
    {
        $tester = $this->runCommand(
            [$this->parcel('CSOMAG1'), $this->parcel('CSOMAG2')],
            [new Response(500, [], 'hiba'), new Response(500, [], 'hiba')],
        );

        self::assertStringContainsString('synced:     0', $tester->getDisplay());
        self::assertStringContainsString('failed:     2', $tester->getDisplay());
        self::assertSame(0, $tester->getStatusCode());
    }

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
    private function runCommand(array $parcels, array $responses): CommandTester
    {
        $httpClient = new MockClient();
        foreach ($responses as $response) {
            $httpClient->addResponse($response);
        }

        $repository = self::createStub(FoxpostParcelRepository::class);
        $repository->method('findActiveForTracking')->willReturn($parcels);

        $workflow = self::createStub(WorkflowInterface::class);
        $workflow->method('can')->willReturn(true);

        $psr17 = new Psr17Factory();

        $service = new FoxPostTrackingSyncService(
            new Client($httpClient, $psr17, $psr17, 'felhasznalo', 'jelszo', 'kulcs', Environment::Production),
            $repository,
            self::createStub(EntityManagerInterface::class),
            $workflow,
            self::createStub(LoggerInterface::class),
        );

        $tester = new CommandTester(new FoxPostTrackingSyncCommand($service));
        $tester->execute([]);

        return $tester;
    }
}
