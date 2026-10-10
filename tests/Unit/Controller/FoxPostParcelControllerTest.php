<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Unit\Controller;

use CodeConjure\FoxPost\Client;
use CodeConjure\FoxPost\Environment;
use CodeConjure\SyliusFoxPostPlugin\Controller\FoxPostParcelController;
use CodeConjure\SyliusFoxPostPlugin\EligibilityChecker;
use CodeConjure\SyliusFoxPostPlugin\Entity\FoxpostParcel;
use CodeConjure\SyliusFoxPostPlugin\Entity\FoxpostParcelStatus;
use CodeConjure\SyliusFoxPostPlugin\FoxPostParcelPayloadFactory;
use CodeConjure\SyliusFoxPostPlugin\FoxPostParcelRegistrationService;
use CodeConjure\SyliusFoxPostPlugin\Repository\FoxpostParcelRepository;
use CodeConjure\SyliusFoxPostPlugin\Tests\Support\AdminControllerHarness;
use CodeConjure\SyliusFoxPostPlugin\Tests\Support\FoxPostShipment;
use CodeConjure\SyliusFoxPostPlugin\Tests\Support\ParcelFixture;
use CodeConjure\SyliusFoxPostPlugin\Tests\Support\ParcelWorkflow;
use CodeConjure\SyliusFoxPostPlugin\Tests\Support\PluginRoutes;
use Doctrine\ORM\EntityManagerInterface;
use Http\Mock\Client as MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ShipmentInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * A #198: a csomaglista négy akció-route-ja (`register`, `cancel`,
 * `hand_over`, `bulk_register`) eddig egyetlen teszt nélkül állt — 166 sor,
 * benne az admin űrlap mezőinek átvétele és a hibák felületre juttatása.
 *
 * A controller az `AbstractController`-ből csak két dolgot használ:
 * `addFlash()`-t és `redirectToRoute()`-ot, ezért kernel nélkül futtatható
 * (l. [[AdminControllerHarness]]). Minden alatta lévő réteg VALÓDI: az
 * `EligibilityChecker`, a `FoxPostParcelRegistrationService`, az állapotgép
 * és a mag `Client`-je is — utóbbi egy PSR-18 mock HTTP kliensen, úgyhogy a
 * FoxPost API válasza (és a hiba) a mérés bemenete, nem egy dublőr
 * `willThrow`-ja. A három szolgáltatás `final`, tehát dublőrt nem is
 * lehetne készíteni róluk; itt ez nem kompromisszum, hanem előny: a flash
 * üzenetek a valódi kivételekből jönnek.
 *
 * Az `EntityManagerInterface` és a `FoxpostParcelRepository` marad dublőr —
 * az egyik adatbázist, a másik lekérdezést jelentene.
 */
final class FoxPostParcelControllerTest extends TestCase
{
    private const string VALID_API_RESPONSE = '{"valid":true,"parcels":[{"barcode":"' . ParcelFixture::BARCODE . '"}]}';

    private const string INVALID_API_RESPONSE = '{"valid":false,"error":"PARCEL_REJECTED"}';

    public function testRegisterReportsAMissingShipmentAndTouchesNoApi(): void
    {
        $http = new MockHttpClient();
        $request = AdminControllerHarness::post();

        $controller = new FoxPostParcelController();
        $session = AdminControllerHarness::wire($controller, $request);

        $response = $controller->register(
            $request,
            ParcelFixture::SHIPMENT_ID,
            $this->registrationService($http),
            new EligibilityChecker(),
            $this->entityManager(shipment: null),
        );

        self::assertSame(['error' => ['Szállítmány nem található.']], AdminControllerHarness::flashes($session));
        self::assertSame(AdminControllerHarness::REFERER, $response->getTargetUrl());
        self::assertCount(0, $http->getRequests());
    }

    /**
     * Referer nélkül (pl. egy kézzel küldött POST) a csomaglistára viszünk.
     *
     * Az URL-t itt a plugin saját route-generátora adja — a lényeg nem a
     * karakterlánc, hanem hogy a controller ERRE a route-NÉVRE fordul vissza:
     * egy elírt név `RouteNotFoundException`-t dobna, és az admin
     * üres lapot kapna a hibaüzenet helyett.
     */
    public function testRegisterWithoutARefererFallsBackToTheParcelList(): void
    {
        $request = AdminControllerHarness::post(referer: null);

        $controller = new FoxPostParcelController();
        AdminControllerHarness::wire($controller, $request);

        $response = $controller->register(
            $request,
            ParcelFixture::SHIPMENT_ID,
            $this->registrationService(new MockHttpClient()),
            new EligibilityChecker(),
            $this->entityManager(shipment: null),
        );

        self::assertSame(
            PluginRoutes::generator()->generate('app_admin_foxpost_parcel_index'),
            $response->getTargetUrl(),
        );
    }

    /**
     * Minden alkalmassági hiba külön flash-ként jelenik meg — nem csak az
     * első. Az admin így egy körben látja, mit kell pótolnia.
     */
    public function testRegisterSurfacesEveryEligibilityErrorAndTouchesNoApi(): void
    {
        $http = new MockHttpClient();
        $request = AdminControllerHarness::post();

        // Automatás szállítmány átvételi pont NÉLKÜL: két hiba egyszerre
        // (nincs automata, és a rendeléshez nincs szállítási cím).
        $shipment = new FoxPostShipment(pickupPointId: null, phoneNumber: null);

        $controller = new FoxPostParcelController();
        $session = AdminControllerHarness::wire($controller, $request);

        $controller->register(
            $request,
            ParcelFixture::SHIPMENT_ID,
            $this->registrationService($http),
            new EligibilityChecker(),
            $this->entityManager($shipment),
        );

        $flashes = AdminControllerHarness::flashes($session);

        self::assertArrayHasKey('error', $flashes);
        self::assertGreaterThan(1, \count($flashes['error']), 'Csak az első alkalmassági hiba jelent meg.');
        self::assertCount(0, $http->getRequests(), 'Alkalmatlan szállítmányra nem mehet API-hívás.');
    }

    public function testASuccessfulRegistrationConfirmsWithTheBarcodeTheApiReturned(): void
    {
        $http = new MockHttpClient();
        $http->addResponse(new Response(200, ['Content-Type' => 'application/json'], self::VALID_API_RESPONSE));

        $request = AdminControllerHarness::post();

        $controller = new FoxPostParcelController();
        $session = AdminControllerHarness::wire($controller, $request);

        $controller->register(
            $request,
            ParcelFixture::SHIPMENT_ID,
            $this->registrationService($http),
            new EligibilityChecker(),
            $this->entityManager(new FoxPostShipment()),
        );

        $flashes = AdminControllerHarness::flashes($session);

        self::assertArrayHasKey('success', $flashes, 'Nincs visszajelzés a sikeres feladásról: ' . json_encode($flashes));
        self::assertStringContainsString(ParcelFixture::BARCODE, $flashes['success'][0]);
    }

    /**
     * Az admin szállítási paneljének mezői (`recipientName`, `comment`, …)
     * felülírják a szállítmányból származó adatot — az ÜRESEN hagyott mezők
     * viszont nem: a controller `array_filter`-je a null és az üres sztringet
     * kiszűri, különben egy üresen hagyott mező letörölné a valódi értéket.
     */
    public function testTheAdminFormOverridesReachTheParcelAndBlankFieldsAreIgnored(): void
    {
        $http = new MockHttpClient();
        $http->addResponse(new Response(200, ['Content-Type' => 'application/json'], self::VALID_API_RESPONSE));

        $request = AdminControllerHarness::post([
            'recipientName' => 'Felülírt Név',
            'comment' => 'Törékeny',
            'deliveryNote' => '',
        ]);

        $parcel = null;

        $controller = new FoxPostParcelController();
        AdminControllerHarness::wire($controller, $request);

        $controller->register(
            $request,
            ParcelFixture::SHIPMENT_ID,
            $this->registrationService($http, persisted: $parcel),
            new EligibilityChecker(),
            $this->entityManager(new FoxPostShipment()),
        );

        self::assertInstanceOf(FoxpostParcel::class, $parcel);
        self::assertSame('Felülírt Név', $parcel->getRecipientName());
        self::assertSame('Törékeny', $parcel->getComment());
        self::assertNull($parcel->getDeliveryNote(), 'Az üresen hagyott mező felülírásként ment át.');
    }

    public function testAnAlreadyRegisteredParcelIsRefusedWithTheServicesOwnMessage(): void
    {
        $http = new MockHttpClient();
        $request = AdminControllerHarness::post();

        $controller = new FoxPostParcelController();
        $session = AdminControllerHarness::wire($controller, $request);

        $controller->register(
            $request,
            ParcelFixture::SHIPMENT_ID,
            $this->registrationService($http, ParcelFixture::parcel(FoxpostParcelStatus::Registered)),
            new EligibilityChecker(),
            $this->entityManager(new FoxPostShipment()),
        );

        $flashes = AdminControllerHarness::flashes($session);

        self::assertArrayHasKey('error', $flashes);
        self::assertStringContainsString('már regisztrálva van', $flashes['error'][0]);
        self::assertCount(0, $http->getRequests(), 'A már feladott csomagra nem mehet újabb API-hívás.');
    }

    public function testAnApiFailureIsShownWithTheApiErrorPrefix(): void
    {
        $http = new MockHttpClient();
        $http->addResponse(new Response(200, ['Content-Type' => 'application/json'], self::INVALID_API_RESPONSE));

        $request = AdminControllerHarness::post();

        $controller = new FoxPostParcelController();
        $session = AdminControllerHarness::wire($controller, $request);

        $controller->register(
            $request,
            ParcelFixture::SHIPMENT_ID,
            $this->registrationService($http),
            new EligibilityChecker(),
            $this->entityManager(new FoxPostShipment()),
        );

        $flashes = AdminControllerHarness::flashes($session);

        self::assertArrayHasKey('error', $flashes);
        self::assertStringStartsWith('FoxPost API hiba: ', $flashes['error'][0]);
    }

    public function testCancelDeletesTheParcelAtFoxPostAndConfirmsIt(): void
    {
        $http = new MockHttpClient();
        $http->addResponse(new Response(200, ['Content-Type' => 'application/json'], '{"valid":true}'));

        $request = AdminControllerHarness::post();
        $parcel = ParcelFixture::parcel(FoxpostParcelStatus::Registered);

        $controller = new FoxPostParcelController();
        $session = AdminControllerHarness::wire($controller, $request);

        $response = $controller->cancel($request, $parcel, $this->registrationService($http));

        self::assertSame(['success' => ['FoxPost csomag sikeresen törölve.']], AdminControllerHarness::flashes($session));
        self::assertSame(FoxpostParcelStatus::Cancelled, $parcel->getInternalStatus());
        self::assertSame(AdminControllerHarness::REFERER, $response->getTargetUrl());
        self::assertCount(1, $http->getRequests(), 'A sztornó nem jutott el a FoxPost API-ig.');
    }

    public function testAFailedCancellationLeavesTheParcelStateUntouched(): void
    {
        $http = new MockHttpClient();
        $http->addResponse(new Response(500, [], 'FoxPost belső hiba'));

        $request = AdminControllerHarness::post();
        $parcel = ParcelFixture::parcel(FoxpostParcelStatus::Registered);

        $controller = new FoxPostParcelController();
        $session = AdminControllerHarness::wire($controller, $request);

        $controller->cancel($request, $parcel, $this->registrationService($http));

        $flashes = AdminControllerHarness::flashes($session);

        self::assertArrayHasKey('error', $flashes);
        self::assertStringStartsWith('FoxPost API hiba törléskor: ', $flashes['error'][0]);
        self::assertSame(
            FoxpostParcelStatus::Registered,
            $parcel->getInternalStatus(),
            'Egy sikertelen API-törlés után a csomag nem léphet `cancelled` állapotba.',
        );
    }

    public function testHandOverAdvancesTheParcelAndConfirmsIt(): void
    {
        $request = AdminControllerHarness::post();
        $parcel = ParcelFixture::parcel(FoxpostParcelStatus::Packed);

        $controller = new FoxPostParcelController();
        $session = AdminControllerHarness::wire($controller, $request);

        $controller->handOver($request, $parcel, $this->registrationService(new MockHttpClient()));

        self::assertArrayHasKey('success', AdminControllerHarness::flashes($session));
        self::assertSame(FoxpostParcelStatus::HandedOver, $parcel->getInternalStatus());
    }

    /**
     * Átadni csak csomagolt csomagot lehet (`hand_over: packed → handed_over`).
     * Egy másik állapotban az állapotgép kivételt dob — a controllernek ezt
     * flash-ként kell megjelenítenie, nem 500-as hibalapként.
     */
    public function testHandOverFromAWrongStateBecomesAnErrorFlashNotACrash(): void
    {
        $request = AdminControllerHarness::post();
        $parcel = ParcelFixture::parcel(FoxpostParcelStatus::Delivered);

        $controller = new FoxPostParcelController();
        $session = AdminControllerHarness::wire($controller, $request);

        $controller->handOver($request, $parcel, $this->registrationService(new MockHttpClient()));

        $flashes = AdminControllerHarness::flashes($session);

        self::assertArrayHasKey('error', $flashes);
        self::assertStringStartsWith('Hiba az átadáskor: ', $flashes['error'][0]);
        self::assertSame(FoxpostParcelStatus::Delivered, $parcel->getInternalStatus());
    }

    public function testBulkRegisterReportsAnEmptySelection(): void
    {
        $http = new MockHttpClient();
        $request = AdminControllerHarness::post();

        $controller = new FoxPostParcelController();
        $session = AdminControllerHarness::wire($controller, $request);

        $controller->bulkRegister(
            $request,
            $this->registrationService($http),
            new EligibilityChecker(),
            $this->entityManager(null),
        );

        self::assertSame(
            ['error' => ['Nincsenek kiválasztott szállítmányok.']],
            AdminControllerHarness::flashes($session),
        );
        self::assertCount(0, $http->getRequests());
    }

    /**
     * A tömeges feladás összegző flash-e a SIKERES és a HIBÁS darabszámot is
     * kiírja, és egy ismeretlen azonosító nem állítja le a kört.
     *
     * A `bulk_register` route SZÁLLÍTMÁNY-azonosítókat vár — ezért nincs ma
     * grid-akció hozzá (l. a grid.yaml megjegyzését): a Sylius tömeges
     * kijelölése a SOROK, azaz a csomagok azonosítóit küldené.
     */
    public function testBulkRegisterCountsBothSuccessesAndFailures(): void
    {
        $http = new MockHttpClient();
        $http->addResponse(new Response(200, ['Content-Type' => 'application/json'], self::VALID_API_RESPONSE));

        $request = Request::create('/admin/foxpost/parcels/bulk-register', 'POST', [
            'ids' => [(string) ParcelFixture::SHIPMENT_ID, '9999', ['tömb']],
        ]);
        $request->headers->set('referer', AdminControllerHarness::REFERER);

        $shipment = new FoxPostShipment();

        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('find')->willReturnCallback(
            static fn (string $class, mixed $id): ?ShipmentInterface => \is_scalar($id) && (int) $id === ParcelFixture::SHIPMENT_ID
                ? $shipment
                : null,
        );

        $controller = new FoxPostParcelController();
        $session = AdminControllerHarness::wire($controller, $request);

        $controller->bulkRegister(
            $request,
            $this->registrationService($http),
            new EligibilityChecker(),
            $entityManager,
        );

        $flashes = AdminControllerHarness::flashes($session);

        self::assertArrayHasKey('success', $flashes);
        self::assertSame('Tömeges feladás kész: 1 sikeres, 2 hibás.', $flashes['success'][0]);
    }

    // -------------------------------------------------------------------------

    /**
     * @param FoxpostParcel|null $existing  a szállítmányhoz már tartozó rekord
     *                                      (újrafeladás ága), ha van
     * @param FoxpostParcel|null $persisted a szolgáltatás `persist()`-jére
     *                                      átadott rekord — a tesztek ezen
     *                                      látják, mi került a csomagra
     */
    private function registrationService(
        MockHttpClient $http,
        ?FoxpostParcel $existing = null,
        ?FoxpostParcel &$persisted = null,
    ): FoxPostParcelRegistrationService {
        $repository = self::createStub(FoxpostParcelRepository::class);
        $repository->method('findOneByShipment')->willReturn($existing);

        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persisted): void {
                if ($entity instanceof FoxpostParcel) {
                    $persisted = $entity;
                }
            },
        );

        $psr17 = new Psr17Factory();

        return new FoxPostParcelRegistrationService(
            new Client($http, $psr17, $psr17, 'felhasznalo', 'jelszo', 'kulcs', Environment::Production),
            new FoxPostParcelPayloadFactory(),
            $repository,
            $entityManager,
            ParcelWorkflow::stateMachine(),
        );
    }

    /**
     * A controller az EntityManagert CSAK a szállítmány megkeresésére
     * használja (`find()`); a csomagrekordot a szolgáltatás saját
     * EntityManagere írja.
     */
    private function entityManager(?ShipmentInterface $shipment): EntityManagerInterface
    {
        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn($shipment);

        return $entityManager;
    }
}
