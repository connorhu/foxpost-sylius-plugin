<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Unit;

use CodeConjure\FoxPost\Client;
use CodeConjure\FoxPost\DeliveryKind;
use CodeConjure\FoxPost\Environment;
use CodeConjure\SyliusFoxPostPlugin\Entity\FoxpostParcel;
use CodeConjure\SyliusFoxPostPlugin\Entity\FoxpostParcelStatus;
use CodeConjure\SyliusFoxPostPlugin\FoxPostParcelPayloadFactory;
use CodeConjure\SyliusFoxPostPlugin\FoxPostParcelRegistrationService;
use CodeConjure\SyliusFoxPostPlugin\Model\FoxPostShipmentInterface;
use CodeConjure\SyliusFoxPostPlugin\Model\FoxPostShipmentTrait;
use CodeConjure\SyliusFoxPostPlugin\Repository\FoxpostParcelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Http\Mock\Client as MockClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShipmentInterface;
use Symfony\Component\Workflow\DefinitionBuilder;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * A payload-építés (a mag `CreateParcelRequest`-jének konstruktor-őrei) egy
 * `\InvalidArgumentException`-t dobhat egy üres kötelező mezőre — pl. egy
 * telefonszám nélküli házhozszállítási szállítmányra, amit az
 * `EligibilityChecker` házhozszállítási ága NEM vizsgál (a telefon csak az
 * automatás ágon kötelező), a `PayloadFactory` pedig `?? ''`-re esik vissza.
 *
 * A review findingja: enélkül a fix nélkül ez az \InvalidArgumentException
 * elnyelet len kaphatott volna — nem jönne létre `FoxpostParcel` rekord, az
 * admin nem látná a hibát. A javítás: a rekordnak léteznie kell a
 * payload-építés előtt, és a hibát ugyanúgy `registration_fail`-ként kell
 * rögzíteni, mint egy `FoxPostApiException`-t.
 */
final class FoxPostParcelRegistrationServiceTest extends TestCase
{
    public function testAnInvalidPayloadStillCreatesTheParcelRecordsItAsRegistrationFailedAndRethrows(): void
    {
        $order = $this->order();
        // Házhozszállítás, telefonszám NÉLKÜL — az EligibilityChecker HD ága ezt
        // nem vizsgálja, a payload mégis eldobja a mag DTO-jának őre miatt.
        $shipment = $this->homeDeliveryShipment($order, phoneNumber: null);

        $repository = self::createStub(FoxpostParcelRepository::class);
        $repository->method('findOneByShipment')->willReturn(null);

        $capturedParcel = null;

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (mixed $entity) use (&$capturedParcel): bool {
                $capturedParcel = $entity;

                return $entity instanceof FoxpostParcel;
            }));
        $entityManager->expects(self::once())->method('flush');

        $httpClient = new MockClient();

        $service = new FoxPostParcelRegistrationService(
            $this->apiClient($httpClient),
            new FoxPostParcelPayloadFactory(),
            $repository,
            $entityManager,
            $this->realWorkflow(),
        );

        try {
            $service->register($shipment);
            self::fail('Az \InvalidArgumentException-nek tovább kellett volna terjednie.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('A recipientPhone nem lehet üres.', $e->getMessage());

            self::assertInstanceOf(FoxpostParcel::class, $capturedParcel);
            self::assertSame(FoxpostParcelStatus::RegistrationFailed, $capturedParcel->getInternalStatus());
            self::assertSame($e->getMessage(), $capturedParcel->getLastApiError());
            self::assertNotNull($capturedParcel->getLastApiErrorAt());
        }

        // A hiba a payload-építéskor dől el — az API-hoz el sem jutunk.
        self::assertCount(0, $httpClient->getRequests());
    }

    // -------------------------------------------------------------------------

    private function apiClient(MockClient $httpClient): Client
    {
        $psr17 = new Psr17Factory();

        return new Client($httpClient, $psr17, $psr17, 'felhasznalo', 'jelszo', 'kulcs', Environment::Production);
    }

    /**
     * A `foxpost_parcel` állapotgép VALÓDI példánya, kernel nélkül — a
     * `src/Resources/config/app/foxpost_parcel.yaml`-lal azonos 20 átmenettel
     * (lásd `tests/Workflow/FoxPostParcelWorkflowDefinitionTest`). Nem dublőr:
     * ha a szolgáltatás rossz sorrendben hívná az átmeneteket (pl. kihagyná a
     * `queue`-t a `registration_fail` előtt), ez a teszt egy VALÓDI
     * `Symfony\Component\Workflow\Exception\LogicException`-nel bukna.
     */
    private function realWorkflow(): WorkflowInterface
    {
        $places = array_map(
            static fn (FoxpostParcelStatus $status): string => $status->value,
            FoxpostParcelStatus::cases(),
        );

        $builder = new DefinitionBuilder($places, [
            new Transition('validate_fail', 'eligible', 'validation_failed'),
            new Transition('fix_and_retry', 'validation_failed', 'eligible'),
            new Transition('queue', 'eligible', 'pending_registration'),
            new Transition('registration_success', 'pending_registration', 'registered'),
            new Transition('registration_fail', 'pending_registration', 'registration_failed'),
            new Transition('re_register', ['registration_failed', 'cancelled'], 'eligible'),
            new Transition('generate_label', 'registered', 'label_ready'),
            new Transition('mark_packed', ['registered', 'label_ready'], 'packed'),
            new Transition('hand_over', 'packed', 'handed_over'),
            new Transition('cancel', ['registered', 'label_ready', 'packed'], 'cancelled'),
            new Transition('tracking_in_transit', 'handed_over', 'in_transit'),
            new Transition('tracking_delivered', ['in_transit', 'handed_over'], 'delivered'),
            new Transition('tracking_failed', 'in_transit', 'delivery_failed'),
            new Transition('tracking_returned', ['in_transit', 'delivery_failed'], 'returned'),
        ]);
        $builder->setInitialPlaces('eligible');

        return new StateMachine($builder->build(), new MethodMarkingStore(true, 'internalStatus'));
    }

    private function order(): Order
    {
        $address = new Address();
        $address->setFirstName('Teszt');
        $address->setLastName('Elek');
        $address->setPostcode('1052');
        $address->setCity('Budapest');
        $address->setStreet('Váci utca 1.');

        $order = new Order();
        $order->setNumber('2026/00042');
        $order->setBillingAddress($address);

        return $order;
    }

    private function homeDeliveryShipment(
        OrderInterface $order,
        ?string $phoneNumber,
    ): ShipmentInterface&FoxPostShipmentInterface {
        return new class($order, $phoneNumber) extends Shipment implements FoxPostShipmentInterface {
            use FoxPostShipmentTrait;

            public function __construct(
                private readonly OrderInterface $orderOverride,
                private readonly ?string $phone,
            ) {
                parent::__construct();
            }

            public function getDeliveryKindSlug(): string
            {
                return DeliveryKind::HomeDelivery->value;
            }

            public function getPickupPointId(): ?string
            {
                return null;
            }

            public function getPhoneNumber(): ?string
            {
                return $this->phone;
            }

            public function getOrder(): OrderInterface
            {
                return $this->orderOverride;
            }
        };
    }
}
