<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Unit\Controller;

use CodeConjure\FoxPost\Client;
use CodeConjure\FoxPost\Environment;
use CodeConjure\SyliusFoxPostPlugin\Controller\FoxPostLabelController;
use CodeConjure\SyliusFoxPostPlugin\Entity\FoxpostParcel;
use CodeConjure\SyliusFoxPostPlugin\Entity\FoxpostParcelStatus;
use CodeConjure\SyliusFoxPostPlugin\FoxPostLabelService;
use CodeConjure\SyliusFoxPostPlugin\Repository\FoxpostParcelRepository;
use CodeConjure\SyliusFoxPostPlugin\Tests\Support\AdminControllerHarness;
use CodeConjure\SyliusFoxPostPlugin\Tests\Support\FoxPostChannel;
use CodeConjure\SyliusFoxPostPlugin\Tests\Support\ParcelFixture;
use CodeConjure\SyliusFoxPostPlugin\Tests\Support\ParcelWorkflow;
use CodeConjure\SyliusFoxPostPlugin\Tests\Support\PluginRoutes;
use Doctrine\ORM\EntityManagerInterface;
use Http\Mock\Client as MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\Channel;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * A #198: a címke-letöltés két route-ja (`download_single`, `download_bulk`)
 * eddig szintén teszt nélkül állt. A hibamódjai mind némák: a válasz egy
 * bináris PDF, tehát egy elrontott fejléc vagy egy üres törzs a böngészőben
 * sem feltűnő, és a csomag állapotgép-léptetése (`generate_label`) sem
 * látszik a letöltésen.
 *
 * Minden réteg valódi: a `FoxPostLabelService` (`final`, dublőrt nem lehetne
 * róla készíteni), az állapotgép és a mag `Client`-je egy PSR-18 mock HTTP
 * kliensen. A címke lapmérete így azon mérhető, amit a plugin TÉNYLEGESEN
 * kiküld a FoxPost API-nak (a lapméret az endpoint útvonalában utazik).
 */
final class FoxPostLabelControllerTest extends TestCase
{
    private const string PDF = "%PDF-1.4\nfake label\n%%EOF";

    public function testAParcelWithoutABarcodeIsRefusedAndNoLabelIsRequested(): void
    {
        $http = new MockHttpClient();
        $parcel = ParcelFixture::parcel(FoxpostParcelStatus::Eligible, barcode: null);

        $controller = $this->controller($http);
        $session = AdminControllerHarness::wire($controller, AdminControllerHarness::post());

        $response = $controller->downloadSingle($parcel);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(
            PluginRoutes::generator()->generate('app_admin_foxpost_parcel_index'),
            $response->getTargetUrl(),
        );
        self::assertSame(
            ['error' => ['A csomagnak nincs vonalkódja.']],
            AdminControllerHarness::flashes($session),
        );
        self::assertCount(0, $http->getRequests());
    }

    public function testASingleLabelComesBackAsADownloadablePdf(): void
    {
        $http = $this->labelResponse();
        $parcel = ParcelFixture::parcel(FoxpostParcelStatus::Registered);

        $controller = $this->controller($http);
        AdminControllerHarness::wire($controller, AdminControllerHarness::post());

        $response = $controller->downloadSingle($parcel);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::PDF, $response->getContent());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));

        $disposition = $response->headers->get('Content-Disposition');
        self::assertIsString($disposition);
        self::assertMatchesRegularExpression(
            '/^attachment; filename="foxpost_cimkek_\d{4}-\d{2}-\d{2}_\d{6}\.pdf"$/',
            $disposition,
        );
    }

    /**
     * A címkézés a csomagot `label_ready` állapotba léptetni — ez az, amitől
     * az admin listán eltűnik a „címke még nincs" állapot, és amitől a
     * `labelGeneratedAt` oszlop kitöltődik.
     */
    public function testGeneratingALabelAdvancesTheParcelAndStampsTheTime(): void
    {
        $parcel = ParcelFixture::parcel(FoxpostParcelStatus::Registered);

        $controller = $this->controller($this->labelResponse());
        AdminControllerHarness::wire($controller, AdminControllerHarness::post());

        $controller->downloadSingle($parcel);

        self::assertSame(FoxpostParcelStatus::LabelReady, $parcel->getInternalStatus());
        self::assertNotSame(
            ParcelFixture::LABEL_GENERATED_AT,
            $parcel->getLabelGeneratedAt()?->format('Y-m-d H:i:s'),
            'A `labelGeneratedAt` nem frissült a mostani címkézésre.',
        );
    }

    /**
     * A lapméret a CSATORNÁÉ: az admin csatorna-űrlapján állítható
     * (`foxpost_label_page_size`), és a FoxPost API endpointjának útvonalában
     * utazik. Ha a csatorna nem mondja meg, A6 a visszaesés — ez a FoxPost
     * címkenyomtatók alapmérete.
     */
    public function testTheLabelPageSizeComesFromTheChannel(): void
    {
        $http = $this->labelResponse();

        $controller = $this->controller($http, FoxPostChannel::withLabelPageSize('A5'));
        AdminControllerHarness::wire($controller, AdminControllerHarness::post());

        $controller->downloadSingle(ParcelFixture::parcel(FoxpostParcelStatus::Registered));

        self::assertStringContainsString('A5', (string) $http->getLastRequest()->getUri());
    }

    public function testTheLabelPageSizeFallsBackToA6WhenTheChannelDoesNotCarryOne(): void
    {
        $http = $this->labelResponse();

        // Egy olyan bolt csatornája, ami a plugin trait-jét nem építette be —
        // a plugin ilyenkor sem hibázhat, csak az alapméretet használja.
        $controller = $this->controller($http, new Channel());
        AdminControllerHarness::wire($controller, AdminControllerHarness::post());

        $controller->downloadSingle(ParcelFixture::parcel(FoxpostParcelStatus::Registered));

        self::assertStringContainsString('A6', (string) $http->getLastRequest()->getUri());
    }

    public function testAnApiFailureIsShownAsAFlashInsteadOfABrokenDownload(): void
    {
        $http = new MockHttpClient();
        $http->addResponse(new Response(500, [], 'FoxPost belső hiba'));

        $controller = $this->controller($http);
        $session = AdminControllerHarness::wire($controller, AdminControllerHarness::post());

        $response = $controller->downloadSingle(ParcelFixture::parcel(FoxpostParcelStatus::Registered));

        self::assertInstanceOf(RedirectResponse::class, $response);

        $flashes = AdminControllerHarness::flashes($session);
        self::assertArrayHasKey('error', $flashes);
        self::assertStringStartsWith('Címke generálás sikertelen: ', $flashes['error'][0]);
    }

    public function testABulkDownloadWithoutASelectionGoesBackWhereItCameFrom(): void
    {
        $http = new MockHttpClient();

        $controller = $this->controller($http);
        $session = AdminControllerHarness::wire($controller, $request = AdminControllerHarness::post());

        $response = $controller->downloadBulk($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(AdminControllerHarness::REFERER, $response->getTargetUrl());
        self::assertSame(
            ['error' => ['Nincsenek kiválasztott csomagok.']],
            AdminControllerHarness::flashes($session),
        );
        self::assertCount(0, $http->getRequests());
    }

    /**
     * A tömeges letöltés a bejelölt sorok azonosítóit kapja (l. a
     * `_grid_bulk_label_action.html.twig`-et), és EGY PDF-et ad vissza az
     * összes címkével — a vonalkód nélküli csomagokat kihagyja, de erről
     * figyelmeztet, különben az admin azt hinné, mindet kinyomtatta.
     */
    public function testABulkDownloadWarnsAboutTheParcelsItHadToSkip(): void
    {
        $http = $this->labelResponse();

        $withBarcode = ParcelFixture::parcel(FoxpostParcelStatus::Registered, id: 11);
        $withoutBarcode = ParcelFixture::parcel(FoxpostParcelStatus::Eligible, barcode: null, id: 12);

        $request = Request::create('/admin/foxpost/labels/download-bulk', 'POST', ['ids' => ['11', '12']]);
        $request->headers->set('referer', AdminControllerHarness::REFERER);

        $controller = $this->controller($http, parcels: [$withBarcode, $withoutBarcode]);
        $session = AdminControllerHarness::wire($controller, $request);

        $response = $controller->downloadBulk($request);

        self::assertSame(self::PDF, $response->getContent());

        $flashes = AdminControllerHarness::flashes($session);
        self::assertArrayHasKey('warning', $flashes);
        self::assertSame('1 csomag kihagyva (nincs vonalkód): 12', $flashes['warning'][0]);
    }

    // -------------------------------------------------------------------------

    /** @param list<FoxpostParcel> $parcels */
    private function controller(
        MockHttpClient $http,
        ?Channel $channel = null,
        array $parcels = [],
    ): FoxPostLabelController {
        $psr17 = new Psr17Factory();

        $labelService = new FoxPostLabelService(
            new Client($http, $psr17, $psr17, 'felhasznalo', 'jelszo', 'kulcs', Environment::Production),
            self::createStub(EntityManagerInterface::class),
            ParcelWorkflow::stateMachine(),
        );

        $channelContext = self::createStub(ChannelContextInterface::class);
        $channelContext->method('getChannel')->willReturn($channel ?? FoxPostChannel::withLabelPageSize(null));

        $repository = self::createStub(FoxpostParcelRepository::class);
        $repository->method('findBy')->willReturn($parcels);

        return new FoxPostLabelController($labelService, $channelContext, $repository);
    }

    private function labelResponse(): MockHttpClient
    {
        $http = new MockHttpClient();
        $http->addResponse(new Response(200, ['Content-Type' => 'application/pdf'], self::PDF));

        return $http;
    }
}
