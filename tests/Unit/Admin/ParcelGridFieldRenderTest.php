<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Unit\Admin;

use CodeConjure\SyliusFoxPostPlugin\Entity\FoxpostParcelStatus;
use CodeConjure\SyliusFoxPostPlugin\Tests\Support\AdminTwig;
use CodeConjure\SyliusFoxPostPlugin\Tests\Support\ParcelFixture;
use CodeConjure\SyliusFoxPostPlugin\Tests\Support\ParcelGrid;
use CodeConjure\SyliusFoxPostPlugin\Tests\Support\Yaml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\GridBundle\FieldTypes\TwigFieldType;
use Sylius\Bundle\GridBundle\Form\Registry\FormTypeRegistryInterface;
use Sylius\Bundle\GridBundle\Parser\OptionsParser;
use Sylius\Bundle\GridBundle\Renderer\TwigGridRenderer;
use Sylius\Component\Grid\DataExtractor\PropertyAccessDataExtractor;
use Sylius\Component\Grid\Definition\Field;
use Sylius\Component\Grid\Definition\Grid;
use Sylius\Component\Grid\FieldTypes\DatetimeFieldType;
use Sylius\Component\Grid\FieldTypes\EnumFieldType;
use Sylius\Component\Grid\FieldTypes\FieldTypeInterface;
use Sylius\Component\Grid\FieldTypes\StringFieldType;
use Sylius\Component\Grid\Parameters;
use Sylius\Component\Grid\View\GridView;
use Sylius\Component\Registry\ServiceRegistry;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * A #198 első fele: az admin csomaglista grid egy VALÓDI csomagsorral
 * renderel.
 *
 * A #116 kiszervezés 183 zöld tesztet adott, de egy sem futott az admin
 * rétegen — a `ContainerCompilationTest` azt méri, hogy a konténer lefordul,
 * nem azt, hogy a lap megjelenik. A grid pedig mérve ÜRES táblán állt
 * (`SELECT COUNT(*) FROM foxpost_parcel` → 0), tehát soha nem rendelt sort:
 * a renderelési hibák nem tudtak elsülni.
 *
 * Ezen a lyukon ment át a **#185**: az `internalStatus` mező `type: string`
 * volt, a Sylius `StringFieldType` pedig `htmlspecialchars((string) $value)`-t
 * ad vissza, egy backed enum `(string)` castolása viszont FATÁLIS:
 *
 *     Error: Object of class ...\FoxpostParcelStatus could not be converted to string
 *
 * Vagyis az ELSŐ valódi FoxPost-csomag után a teljes admin csomaglista 500-ra
 * futott volna — azon a lapon, amin épp azt a csomagot kellene kezelni.
 *
 * A teszt a VALÓDI renderelési útvonalat járja be: a `grid.yaml`-ból a Sylius
 * `ArrayToDefinitionConverter`-ével épít definíciót, a mezőket a Sylius
 * `TwigGridRenderer::renderField()`-jével rendeli (OptionsResolver,
 * OptionsParser, DataExtractor mind az igazi), és a mezőtípusok is a Sylius
 * saját szolgáltatásai. Az elvárásokat nem egy másolt listából veszi, hanem a
 * `grid.yaml`-ban TÉNYLEGESEN jelen lévő mezőkből: egy új mező addig pirosat
 * ad, amíg nincs hozzá mért elvárás.
 */
final class ParcelGridFieldRenderTest extends TestCase
{
    private const string STATUS_KEY_PREFIX = 'codeconjure.foxpost.ui.status.';

    /**
     * Mező → a VALÓDI csomagsorból elvárt, megjelenített érték.
     *
     * A `deliveryKind` és az `internalStatus` fordított szöveget ad, nem a
     * nyers enum-értéket: a boltot üzemeltető ember nem `label_ready`-t akar
     * olvasni. A másik négy mező nyers adat.
     *
     * @var array<string, string>
     */
    private const array EXPECTED_RENDERING = [
        'orderNumber' => ParcelFixture::ORDER_NUMBER,
        'barcode' => ParcelFixture::BARCODE,
        'deliveryKind' => 'FoxPost csomagautomata',
        'internalStatus' => 'Címke kész',
        'createdAt' => ParcelFixture::CREATED_AT,
        'labelGeneratedAt' => ParcelFixture::LABEL_GENERATED_AT,
    ];

    #[DataProvider('gridFieldProvider')]
    public function testEveryGridFieldRendersARealParcelRow(string $name, Field $field): void
    {
        self::assertArrayHasKey(
            $name,
            self::EXPECTED_RENDERING,
            \sprintf(
                'A `%s` mező benne van a grid.yaml-ban, de nincs hozzá mért elvárás ebben a tesztben. ' .
                'Egy új grid-mező nem csúszhat át mérés nélkül — ezen a lyukon ment át a #185.',
                $name,
            ),
        );

        self::assertSame(
            self::EXPECTED_RENDERING[$name],
            trim($this->renderer()->renderField($this->gridView(), $field, ParcelFixture::parcel())),
        );
    }

    /** @return iterable<string, array{string, Field}> */
    public static function gridFieldProvider(): iterable
    {
        foreach (self::definition()->getFields() as $field) {
            yield $field->getName() => [$field->getName(), $field];
        }
    }

    /**
     * A grid minden mezőtípusa olyan, amit a Sylius ismer.
     *
     * Egy elírt típusnév (`type: strign`) a hostban
     * `ExistingServiceNotFoundException`-t ad a lap renderelésekor — vagyis
     * szintén 500-at, csak a hibakeresés indul máshonnan. Itt a hiba a
     * típusnévre mutat.
     */
    public function testEveryConfiguredFieldTypeIsAKnownSyliusGridFieldType(): void
    {
        $known = array_keys(self::fieldTypes());

        foreach (self::definition()->getFields() as $field) {
            self::assertContains(
                $field->getType(),
                $known,
                \sprintf('A `%s` mező `%s` típusa nem létező grid-mezőtípus.', $field->getName(), $field->getType()),
            );
        }
    }

    /**
     * Az állapot-enum MINDEN esetére van fordítás, mindkét szállított
     * nyelvben.
     *
     * A #185 mellékmegfigyelése: a grid státusz-mezője fordítási kulcson át
     * jelenít meg, és a `FoxpostParcelStatus` 13 esete közül mérve csak 10-nek
     * volt kulcsa (`pending_registration`, `delivery_failed`, `returned`
     * hiányzott). Egy hiányzó kulcs nem hibázik: a felület a NYERS kulcsot
     * írja ki, és ezt csak az veszi észre, aki pont abban az állapotban lévő
     * csomagot lát.
     */
    #[DataProvider('statusAndLocaleProvider')]
    public function testEveryStatusCaseHasALabelInEveryShippedLocale(FoxpostParcelStatus $status, string $locale): void
    {
        $catalogue = self::flatten(self::messages($locale));

        self::assertArrayHasKey(
            self::STATUS_KEY_PREFIX . $status->value,
            $catalogue,
            \sprintf(
                'A `%s` állapotnak nincs `%s` fordítása — az admin csomaglista a nyers kulcsot írná ki.',
                $status->name,
                $locale,
            ),
        );
    }

    /** @return iterable<string, array{FoxpostParcelStatus, string}> */
    public static function statusAndLocaleProvider(): iterable
    {
        foreach (self::locales() as $locale) {
            foreach (FoxpostParcelStatus::cases() as $status) {
                yield $status->value . ' / ' . $locale => [$status, $locale];
            }
        }
    }

    /**
     * Az állapot-szűrő MINDEN enum-esetre tud szűrni.
     *
     * A grid `internalStatus` szűrője kézzel felsorolt választéklistát hordoz.
     * Ha az enum új esetet kap, a szűrő némán hiányos lesz: a `returned`
     * állapotú csomagokra például nem lehet rászűrni, pedig a tracking
     * szinkron oda is állíthat.
     */
    public function testEveryStatusCaseIsSelectableInTheGridFilter(): void
    {
        $filter = self::definition()->getFilter('internalStatus');
        $formOptions = $filter->getFormOptions();

        self::assertArrayHasKey('choices', $formOptions);
        /** @var array<string, string> $choices */
        $choices = $formOptions['choices'];

        $expected = array_map(static fn (FoxpostParcelStatus $status): string => $status->value, FoxpostParcelStatus::cases());
        sort($expected);

        $actual = array_values($choices);
        sort($actual);

        self::assertSame($expected, $actual);
    }

    /**
     * A `TwigGridRenderer` konstruktora a szűrő-renderelés két szolgáltatását
     * is kéri (`FormFactoryInterface`, `FormTypeRegistryInterface`). A
     * mező-renderelés egyiket sem hívja, ezért ott konfigurálatlan dublo áll —
     * ha a Sylius mégis hívná valamelyiket, a dublo null-t adna, és a teszt
     * pirosra váltana, nem csúszna át. Minden más alkatrész valódi.
     */
    private function renderer(): TwigGridRenderer
    {
        $registry = new ServiceRegistry(FieldTypeInterface::class, 'grid field');
        foreach (self::fieldTypes() as $type => $fieldType) {
            $registry->register($type, $fieldType);
        }

        return new TwigGridRenderer(
            AdminTwig::environment(),
            $registry,
            $this->createStub(FormFactoryInterface::class),
            $this->createStub(FormTypeRegistryInterface::class),
            '@SyliusGrid/_grid.html.twig',
            [],
            [],
            new OptionsParser(),
        );
    }

    /**
     * A Sylius saját mezőtípusai, az igazi `DataExtractor`-ral — ugyanazok a
     * szolgáltatások, amiket a `sylius.grid_field.*` id-k mögé a GridBundle
     * regisztrál.
     *
     * @return array<string, FieldTypeInterface>
     */
    private static function fieldTypes(): array
    {
        $extractor = new PropertyAccessDataExtractor(PropertyAccess::createPropertyAccessor());

        return [
            'string' => new StringFieldType($extractor),
            'datetime' => new DatetimeFieldType($extractor),
            'enum' => new EnumFieldType($extractor, AdminTwig::translator()),
            'twig' => new TwigFieldType($extractor, AdminTwig::environment()),
        ];
    }

    private static function definition(): Grid
    {
        return ParcelGrid::definition();
    }

    private function gridView(): GridView
    {
        return new GridView([ParcelFixture::parcel()], self::definition(), new Parameters());
    }

    /** @return list<string> */
    private static function locales(): array
    {
        $locales = [];

        foreach (glob(\dirname(__DIR__, 3) . '/translations/messages.*.yaml') ?: [] as $path) {
            $locale = basename($path, '.yaml');
            $locales[] = substr($locale, strrpos($locale, '.') + 1);
        }

        sort($locales);

        return $locales;
    }

    /** @return array<array-key, mixed> */
    private static function messages(string $locale): array
    {
        return Yaml::parse('translations/messages.' . $locale . '.yaml');
    }

    /**
     * A fordítási fa lapítása pont-elválasztós kulcsokra. Az ÉRTÉKEKET nem
     * tipizálja: a tesztnek csak a kulcs LÉTE kell — egy üres vagy
     * nem-string érték ugyanúgy hiányzó fordítás, és azt a plugin
     * fordítás-szerződése (`TranslationNamespaceTest`) méri.
     *
     * @param array<array-key, mixed> $tree
     *
     * @return array<string, mixed>
     */
    private static function flatten(array $tree, string $prefix = ''): array
    {
        $flat = [];

        foreach ($tree as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (\is_array($value)) {
                $flat += self::flatten($value, $path);

                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }
}
