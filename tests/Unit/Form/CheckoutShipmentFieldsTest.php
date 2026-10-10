<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Unit\Form;

use CodeConjure\FoxPost\Client;
use CodeConjure\FoxPost\Environment;
use CodeConjure\SyliusFoxPostPlugin\CachedParcelLockerProvider;
use CodeConjure\SyliusFoxPostPlugin\Form\Extension\CheckoutShipmentTypeExtension;
use CodeConjure\SyliusFoxPostPlugin\Form\Extension\SelectShippingTypeExtension;
use CodeConjure\SyliusFoxPostPlugin\Form\Type\FoxPostPickupPointSelectType;
use CodeConjure\SyliusFoxPostPlugin\Model\FoxPostShipmentInterface;
use CodeConjure\SyliusFoxPostPlugin\Tests\Support\FoxPostShipment;
use Http\Mock\Client as MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\CoreBundle\Form\Type\Checkout\ShipmentType;
use Sylius\Bundle\ShippingBundle\Form\Type\ShippingMethodChoiceType;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Registry\ServiceRegistryInterface;
use Sylius\Component\Shipping\Resolver\ShippingMethodsResolverInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormFactoryBuilderInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormTypeExtensionInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validation;

/**
 * A #199: a pénztár 2. lépésének két FoxPost-mezőjét a PLUGIN adja.
 *
 * A #116 kiszervezéskor ez a két mező tulajdonosi döntéssel a boltban maradt,
 * és ez volt a kiszervezés egyetlen félig teljesült célja: egy másik Sylius
 * bolt az admin oldalt és a feladási logikát megkapta, a vevő által LÁTOTT
 * mezőkészletet nem — a plugin README-je kimondta, hogy a mezőket „a hostnak
 * kell létrehoznia", pontosan `phoneNumber` és `pickupPointId` néven.
 *
 * A teszt két, egymástól független dolgot mér:
 *
 *  1. **a mezők léteznek a plugin önmagában** — valódi Sylius `ShipmentType`,
 *     valódi Symfony `FormFactory`, valódi validátor-kiterjesztés; a
 *     constraintek a mező tényleges konfigurációjából jönnek, nem a
 *     forráskód olvasásából;
 *  2. **a mezők FELÜLÍRHATÓK** — ez a prioritás kérdése, és két külön
 *     mechanizmuson áll: a konténer SORRENDJÉN (`FormPass`) és azon, hogy
 *     `ResolvedFormType::buildForm()` a sorrend szerint hívja a
 *     kiterjesztéseket, tehát a KÉSŐBBI `->add()` nyer. Mindkettőt a valódi
 *     Symfony kóddal mérjük, mert a kettő együtt adja a szerződést — és ha
 *     a prioritás elvész, a plugin csendben lecserélné a host dúsabb
 *     mezőjét a sajátjára.
 */
final class CheckoutShipmentFieldsTest extends TestCase
{
    private const string SERVICE_ID = CheckoutShipmentTypeExtension::class;

    private const string SERVICES_FILE = 'src/Resources/config/services.xml';

    /**
     * Mező → a plugin alapértelmezett típusa és a `NotBlank`-je validációs
     * csoportja. A csoportokat a `SelectShippingTypeExtension` dönti el a
     * választott MÓD alapján (#99) — a két konstans a kapcsolat.
     *
     * @var array<string, array{type: class-string, group: string}>
     */
    private const array FIELDS = [
        'phoneNumber' => [
            'type' => TelType::class,
            'group' => SelectShippingTypeExtension::RECIPIENT_PHONE_VALIDATION_GROUP,
        ],
        'pickupPointId' => [
            'type' => FoxPostPickupPointSelectType::class,
            'group' => SelectShippingTypeExtension::FOXPOST_PARCEL_LOCKER_VALIDATION_GROUP,
        ],
    ];

    /** @param class-string $type */
    #[DataProvider('fieldProvider')]
    public function testThePluginAddsTheFieldOnItsOwn(string $field, string $type, string $group): void
    {
        $form = $this->shipmentForm();

        self::assertTrue($form->has($field), \sprintf(
            'A `%s` mező nincs a shipment formon. A #199 óta a plugin adja — enélkül egy üres ' .
            'Sylius bolt nem látja a FoxPost-mezőket a pénztárban, és a hostnak kézzel kell ' .
            'replikálnia őket.',
            $field,
        ));

        self::assertInstanceOf($type, $form->get($field)->getConfig()->getType()->getInnerType());
        self::assertNotSame('', $group);
    }

    /**
     * A kötelezőség a MÓDHOZ van kötve, nem a mezőhöz: a `NotBlank` csak a
     * `SelectShippingTypeExtension` által feltett validációs csoportban él.
     * Csoport nélkül (vagy elírt csoportnévvel) a mező MINDEN módnál
     * kötelezővé válna — a futáros rendelés is csomagautomatát kérne.
     */
    #[DataProvider('fieldProvider')]
    public function testTheFieldIsOnlyRequiredInItsOwnValidationGroup(
        string $field,
        string $type,
        string $group,
    ): void {
        $constraints = $this->shipmentForm()->get($field)->getConfig()->getOption('constraints');

        self::assertIsArray($constraints);

        $notBlanks = array_values(array_filter(
            $constraints,
            static fn (mixed $c): bool => $c instanceof NotBlank,
        ));

        self::assertCount(1, $notBlanks, \sprintf('A `%s` mezőn nem pontosan egy NotBlank van.', $field));
        self::assertSame([$group], $notBlanks[0]->groups, \sprintf(
            'A `%s` mező NotBlank-je nem a `%s` csoportban él, tehát a kötelezőségét nem a ' .
            'választott szállítási mód dönti el.',
            $field,
            $group,
        ));
    }

    /**
     * Egyik mező sem `required` HTML-szinten. Ez nem kozmetika: a két panelt a
     * Stimulus controller rejti el, ha a választott mód nem kéri őket — egy
     * `required` attribútum egy REJTETT mezőn a böngésző saját
     * validációjával akasztaná meg a beküldést, látható hibaüzenet nélkül.
     */
    #[DataProvider('fieldProvider')]
    public function testTheFieldIsNotRequiredAtTheHtmlLevel(string $field, string $type, string $group): void
    {
        self::assertFalse(
            $this->shipmentForm()->get($field)->getConfig()->getOption('required'),
            \sprintf('A `%s` mező `required`, pedig a controller elrejtheti.', $field),
        );
    }

    /** @return iterable<string, array{string, class-string, string}> */
    public static function fieldProvider(): iterable
    {
        foreach (self::FIELDS as $field => $spec) {
            /** @var class-string $type */
            $type = $spec['type'];

            yield $field => [$field, $type, $spec['group']];
        }
    }

    /**
     * Amit a plugin a formhoz ad, azt a publikált host-szerződésen keresztül
     * ÍRNI is kell tudni.
     *
     * A mezőlistát nem konstansból vesszük, hanem a VALÓDI formból: a plugin
     * kiterjesztésével és nélküle felépített `ShipmentType` gyerekmezőinek a
     * különbsége. Így az állítás akkor is elsül, ha valaki egy harmadik mezőt
     * ad a formhoz, és elfelejti a `FoxPostShipmentInterface`-t bővíteni — a
     * hibamód ugyanaz a `NoSuchPropertyException` a pénztár szállítási
     * lépésén, csak egy másik mezőre.
     *
     * Ez a #199 host-szerződésének egésze: a plugin a FORMOT adja, a
     * TÁROLÁST a host (#116 3.10), és a kettő között ez az interfész áll.
     */
    public function testEveryFieldThePluginAddsIsWritableThroughThePublishedContract(): void
    {
        $contract = new \ReflectionClass(FoxPostShipmentInterface::class);
        $fields = $this->fieldsAddedByThePlugin();

        self::assertNotSame([], $fields, 'A plugin kiterjesztése egyetlen mezőt sem ad a formhoz.');

        foreach ($fields as $field) {
            foreach (['get' . ucfirst($field), 'set' . ucfirst($field)] as $method) {
                self::assertTrue($contract->hasMethod($method), \sprintf(
                    'A `%s` mezőt a plugin adja a shipment formhoz, de a `%s()` nincs a ' .
                    '`FoxPostShipmentInterface`-ben. A form a property path-on keresztül ír: egy ' .
                    'olyan host, ami csak az interfészt valósítja meg, a pénztár szállítási ' .
                    'lépésén NoSuchPropertyException-nel állna le.',
                    $field,
                    $method,
                ));
            }
        }
    }

    /**
     * A két mező VALÓBAN a szállítmányra ír.
     *
     * Ez a #199 legkeményebb állítása, és ez kényszerítette ki a
     * `FoxPostShipmentInterface` két setterét: amíg a formot a host építette,
     * a plugin csak OLVASTA a két mezőt, most viszont a plugin adja hozzá
     * őket — és egy form a property path-on keresztül ír. Getter-only
     * entitáson a Symfony `PropertyAccessor` a pénztár szállítási lépését
     * `NoSuchPropertyException`-nel állítja le (mérve, pont ezen a teszten,
     * a Sylius sima `Shipment`-jével adatobjektumként).
     */
    public function testSubmittingTheFormWritesBothFieldsOntoTheShipment(): void
    {
        $shipment = new FoxPostShipment();
        $form = $this->formFactory()->create(ShipmentType::class, $shipment);

        $form->submit(['phoneNumber' => '+36301234567', 'pickupPointId' => 'HU1234'], false);

        self::assertTrue($form->isSynchronized(), 'A form nem szinkronizált a beküldés után.');
        self::assertSame('+36301234567', $shipment->getPhoneNumber());
        self::assertSame('HU1234', $shipment->getPickupPointId());
    }

    /**
     * A host FELÜLÍRHATJA a plugin mezőjét: elég ugyanazon a néven újra
     * hozzáadnia a sajátját. A `ResolvedFormType::buildForm()` a
     * kiterjesztéseket sorrendben hívja, tehát a későbbi `->add()` nyer — a
     * plugin prioritása pont azért magas, hogy ez a „későbbi" a host legyen.
     *
     * A mérés a referencia-bolt valódi esete: a `phoneNumber`-t a bolt a
     * saját, országra szűkített típusával és validátorával írja felül.
     */
    public function testAHostExtensionRunningLaterOverridesThePluginField(): void
    {
        $form = $this->shipmentForm(new class() extends AbstractTypeExtension {
            public function buildForm(FormBuilderInterface $builder, array $options): void
            {
                // A típus itt szándékosan NEM a boltbeli telefon-típus: a
                // lényeg nem a konkrét osztály, hanem hogy a host nyer.
                $builder->add('phoneNumber', TextareaType::class, ['required' => true]);
            }

            public static function getExtendedTypes(): iterable
            {
                return [ShipmentType::class];
            }
        });

        self::assertInstanceOf(
            TextareaType::class,
            $form->get('phoneNumber')->getConfig()->getType()->getInnerType(),
            'A host kiterjesztése nem tudta felülírni a plugin mezőjét.',
        );
        self::assertTrue($form->get('phoneNumber')->getConfig()->getOption('required'));
        self::assertTrue(
            $form->has('pickupPointId'),
            'Az egyik mező felülírása elvitte a másikat is.',
        );
    }

    /**
     * A SORREND a konténerben dől el, és a valódi Symfony
     * `FormPass`-szal mérhető: a `form.type_extension` tagokat
     * `findAndSortTaggedServices()` rendezi csökkenő prioritás szerint, és az
     * eredmény a `form.extension` 1. argumentumába kerül, kiterjesztett
     * típusonként egy `IteratorArgument`-be.
     *
     * Prioritás nélkül a plugin nyerne a holtversenyben: a bundle
     * szolgáltatásai a `MergeExtensionConfigurationPass`-ban kerülnek a
     * konténerbe, tehát a host `services.yaml`-ja UTÁN.
     */
    public function testTheContainerSortsThePluginExtensionBeforeAHostExtension(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('form.extension', new Definition(\stdClass::class, [null, null, null]));

        $container->setDefinition('host.extension', (new Definition(HostPhoneNumberExtension::class))
            ->addTag('form.type_extension'));
        $container->setDefinition(self::SERVICE_ID, (new Definition(CheckoutShipmentTypeExtension::class))
            ->addTag('form.type_extension', ['priority' => CheckoutShipmentTypeExtension::PRIORITY]));

        (new \Symfony\Component\Form\DependencyInjection\FormPass())->process($container);

        $extensions = $container->getDefinition('form.extension')->getArgument(1);

        self::assertIsArray($extensions);
        self::assertArrayHasKey(ShipmentType::class, $extensions);

        $iterator = $extensions[ShipmentType::class];
        self::assertInstanceOf(IteratorArgument::class, $iterator);

        self::assertSame(
            [self::SERVICE_ID, 'host.extension'],
            array_map(static function (mixed $reference): string {
                self::assertInstanceOf(Reference::class, $reference);

                return (string) $reference;
            }, $iterator->getValues()),
            'A plugin kiterjesztése nem a host elé rendeződik, tehát a plugin írná felül a hostot.',
        );
    }

    /**
     * A szolgáltatás-definíció és az osztály konstansa UGYANAZT a prioritást
     * mondja, és az `autoconfigure` ki van kapcsolva.
     *
     * Az autoconfigure prioritás nélküli `form.type_extension` taget tesz fel;
     * a kézi tag mellett ez KÉT taget jelentene ugyanazon a szolgáltatáson,
     * vagyis a `FormPass` kétszer vennénk fel a kiterjesztést — a mezők
     * kétszer épülnének, és a második (prioritás nélküli) felvétel a host
     * MÖGÉ kerülne, pont elrontva a felülírhatóságot.
     */
    public function testTheServiceDefinitionCarriesExactlyOneTagWithTheClassPriority(): void
    {
        $container = new ContainerBuilder();
        (new XmlFileLoader($container, new FileLocator(\dirname(__DIR__, 3))))->load(self::SERVICES_FILE);

        $definition = $container->getDefinition(self::SERVICE_ID);
        $tags = $definition->getTag('form.type_extension');

        self::assertCount(1, $tags, 'A kiterjesztésen nem pontosan egy `form.type_extension` tag van.');
        self::assertSame(CheckoutShipmentTypeExtension::PRIORITY, $tags[0]['priority'] ?? null);
        self::assertFalse(
            $definition->isAutoconfigured(),
            'Az `autoconfigure` be van kapcsolva ezen a szolgáltatáson: a kézi tag mellé egy ' .
            'prioritás nélküli másodikat is kapna.',
        );
    }

    // -------------------------------------------------------------------------

    /**
     * A valódi Sylius `ShipmentType`, a plugin kiterjesztésével — plusz, ha a
     * teszt ad ilyet, a host kiterjesztéseivel UTÁNA (ez a konténer
     * prioritás-sorrendje, l. a fenti `FormPass`-mérést).
     */
    private function shipmentForm(FormTypeExtensionInterface ...$hostExtensions): FormInterface
    {
        return $this->formFactory(...$hostExtensions)->create(ShipmentType::class, new FoxPostShipment());
    }

    private function formFactory(FormTypeExtensionInterface ...$hostExtensions): FormFactoryInterface
    {
        $builder = $this->formFactoryBuilderWithoutThePlugin()
            ->addTypeExtension(new CheckoutShipmentTypeExtension());

        foreach ($hostExtensions as $extension) {
            $builder->addTypeExtension($extension);
        }

        return $builder->getFormFactory();
    }

    private function formFactoryBuilderWithoutThePlugin(): FormFactoryBuilderInterface
    {
        return Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(new ShipmentType(Shipment::class))
            ->addType($this->shippingMethodChoiceType())
            ->addType(new FoxPostPickupPointSelectType($this->lockerProvider()));
    }

    /**
     * A plugin kiterjesztése által hozzáadott mezők — a kiterjesztéssel és
     * nélküle felépített form gyerekmezőinek a különbsége. A `method` mezőt
     * így nem kell kézzel kizárni: azt a Sylius `ShipmentType` adja mindkét
     * esetben.
     *
     * @return list<string>
     */
    private function fieldsAddedByThePlugin(): array
    {
        $withPlugin = array_keys($this->shipmentForm()->all());
        $without = array_keys(
            $this->formFactoryBuilderWithoutThePlugin()
                ->getFormFactory()
                ->create(ShipmentType::class, new FoxPostShipment())
                ->all(),
        );

        return array_values(array_diff($withPlugin, $without));
    }

    /**
     * A Sylius `ShipmentType` PRE_SET_DATA listenere felveszi a `method`
     * mezőt `ShippingMethodChoiceType`-ként — az osztály `final`, a három
     * konstruktor-argumentuma viszont interfész, tehát dublőrözhető. A
     * választéklista üres: ez a teszt nem a módválasztót méri, de a
     * `ShipmentType`-ot VALÓDIKÉNT akarja felépíteni, mert a plugin
     * kiterjesztése is azon fut.
     */
    private function shippingMethodChoiceType(): ShippingMethodChoiceType
    {
        $resolver = self::createStub(ShippingMethodsResolverInterface::class);
        $resolver->method('supports')->willReturn(false);

        $repository = self::createStub(RepositoryInterface::class);
        $repository->method('findAll')->willReturn([]);

        return new ShippingMethodChoiceType(
            $resolver,
            self::createStub(ServiceRegistryInterface::class),
            $repository,
        );
    }

    /**
     * Valódi szolgáltatás, PSR-18 mock HTTP kliensen: a csomagautomata-típus a
     * nevet és a címet csak NEM ÜRES azonosítóra kérdezi meg, tehát ebben a
     * tesztben egyetlen HTTP-hívás sem indul.
     */
    private function lockerProvider(): CachedParcelLockerProvider
    {
        $psr17 = new Psr17Factory();

        return new CachedParcelLockerProvider(
            new Client(new MockHttpClient(), $psr17, $psr17, 'felhasznalo', 'jelszo', 'kulcs', Environment::Production),
            new ArrayAdapter(),
        );
    }
}

/**
 * Egy host form kiterjesztés helyettese a konténer-mérésben — prioritás
 * nélkül, ahogy egy bolt `services.yaml`-ja írná.
 */
final class HostPhoneNumberExtension extends AbstractTypeExtension
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('phoneNumber', TelType::class);
    }

    public static function getExtendedTypes(): iterable
    {
        return [ShipmentType::class];
    }
}
