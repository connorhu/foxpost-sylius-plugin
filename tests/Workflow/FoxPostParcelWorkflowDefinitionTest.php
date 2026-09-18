<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Workflow;

use CodeConjure\SyliusFoxPostPlugin\Entity\FoxpostParcel;
use CodeConjure\SyliusFoxPostPlugin\Entity\FoxpostParcelStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * A `foxpost_parcel` workflow YAML szerkezeti ellenőrzése — kernel nélkül.
 *
 * A bolt `tests/Functional/FoxPost/FoxPostParcelStateMachineTest.php`-je a
 * VALÓDI, konténerből elkért állapotgépen fut (#170) — az itt marad, a
 * plugin nem hoz saját Sylius teszt-kernelt (lásd README.md:154).
 *
 * Ez a teszt ugyanazt a bukási módot fogja meg kernel nélkül: egy elgépelt
 * `from`/`to` a YAML másolásakor némán elnyelne átmeneteket. A 20 elvárt
 * átmenetet (transitions-kulcsonként a `from` lista szétbontva) NEM a
 * plugin YAML-jéből olvassuk vissza, hanem a bolt eredetijéből, kézzel,
 * függetlenül rögzítjük — így egy másolási hiba a két oldal
 * összevetésekor bukik ki, nem egy önmagát igazoló kör.
 *
 * Forrás: bolt config/workflows/foxpost_parcel.yaml, #170
 */
final class FoxPostParcelWorkflowDefinitionTest extends TestCase
{
    private const array PLACES = [
        'eligible',
        'validation_failed',
        'pending_registration',
        'registration_failed',
        'registered',
        'label_ready',
        'packed',
        'handed_over',
        'in_transit',
        'delivered',
        'delivery_failed',
        'cancelled',
        'returned',
    ];

    /**
     * A bolt eredeti YAML-jének 20 átmenete, `from`-onként szétbontva —
     * ez a független referencia, nem a plugin YAML-jéből származik.
     *
     * @var list<array{string, string, string}>
     */
    private const array EXPECTED_TRANSITIONS = [
        ['validate_fail', 'eligible', 'validation_failed'],
        ['fix_and_retry', 'validation_failed', 'eligible'],
        ['queue', 'eligible', 'pending_registration'],
        ['registration_success', 'pending_registration', 'registered'],
        ['registration_fail', 'pending_registration', 'registration_failed'],
        ['re_register', 'registration_failed', 'eligible'],
        ['re_register', 'cancelled', 'eligible'],
        ['generate_label', 'registered', 'label_ready'],
        ['mark_packed', 'registered', 'packed'],
        ['mark_packed', 'label_ready', 'packed'],
        ['hand_over', 'packed', 'handed_over'],
        ['cancel', 'registered', 'cancelled'],
        ['cancel', 'label_ready', 'cancelled'],
        ['cancel', 'packed', 'cancelled'],
        ['tracking_in_transit', 'handed_over', 'in_transit'],
        ['tracking_delivered', 'in_transit', 'delivered'],
        ['tracking_delivered', 'handed_over', 'delivered'],
        ['tracking_failed', 'in_transit', 'delivery_failed'],
        ['tracking_returned', 'in_transit', 'returned'],
        ['tracking_returned', 'delivery_failed', 'returned'],
    ];

    // -------------------------------------------------------------------------
    // A definíció fejléce
    // -------------------------------------------------------------------------

    public function testTypeIsStateMachine(): void
    {
        self::assertSame('state_machine', self::type());
    }

    public function testMarkingStoreMatchesTheOriginal(): void
    {
        self::assertSame(
            ['type' => 'method', 'property' => 'internalStatus'],
            self::markingStore(),
        );
    }

    /**
     * A pontos egyenlőség (nem tartalmazás-vizsgálat) önmagában bizonyítja,
     * hogy a supports kizárólag a plugin entitására mutat — semmi másra,
     * tehát a bolt eredeti entitás-osztályára sem.
     */
    public function testSupportsPointsOnlyToThePluginEntity(): void
    {
        self::assertSame([FoxpostParcel::class], self::supports());
    }

    public function testInitialMarkingIsEligible(): void
    {
        self::assertSame('eligible', self::initialMarking());
    }

    // -------------------------------------------------------------------------
    // A places halmaz — mindkét irányban az enumhoz mérve
    // -------------------------------------------------------------------------

    public function testThePlacesMatchTheEnumCasesExactly(): void
    {
        $places = self::places();
        sort($places);

        $enumValues = array_map(
            static fn (FoxpostParcelStatus $status): string => $status->value,
            FoxpostParcelStatus::cases(),
        );
        sort($enumValues);

        self::assertSame(self::sorted(self::PLACES), $places, 'A places a rögzített referenciától tér el.');
        self::assertSame($enumValues, $places, 'A places nem egyezik a FoxpostParcelStatus enum case-eivel.');
    }

    // -------------------------------------------------------------------------
    // A 20 átmenet
    // -------------------------------------------------------------------------

    public function testThereAreExactlyTwentyTransitions(): void
    {
        self::assertCount(20, self::flattenedTransitions());
    }

    public function testTheFlattenedTransitionsMatchTheReferenceExactly(): void
    {
        $actual = self::flattenedTransitions();
        sort($actual);

        $expected = array_map(
            static fn (array $t): string => implode('|', $t),
            self::EXPECTED_TRANSITIONS,
        );
        sort($expected);

        self::assertSame($expected, $actual);
    }

    #[DataProvider('expectedTransitionProvider')]
    public function testEachExpectedTransitionExistsWithItsFromAndTo(string $name, string $from, string $to): void
    {
        self::assertContains(
            sprintf('%s|%s|%s', $name, $from, $to),
            self::flattenedTransitions(),
            sprintf('Hiányzik vagy elgépelt a(z) "%s" (%s → %s) átmenet.', $name, $from, $to),
        );
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function expectedTransitionProvider(): iterable
    {
        foreach (self::EXPECTED_TRANSITIONS as [$name, $from, $to]) {
            yield sprintf('%s: %s → %s', $name, $from, $to) => [$name, $from, $to];
        }
    }

    public function testEveryTransitionFromAndToIsAKnownPlace(): void
    {
        $places = self::places();

        foreach (self::transitions() as $name => $transition) {
            foreach ($transition['from'] as $from) {
                self::assertContains($from, $places, sprintf('A(z) "%s" átmenet "from" helye ("%s") nincs a places között.', $name, $from));
            }

            self::assertContains($transition['to'], $places, sprintf('A(z) "%s" átmenet "to" helye ("%s") nincs a places között.', $name, $transition['to']));
        }
    }

    // -------------------------------------------------------------------------
    // A YAML beolvasása és típusosan ellenőrzött kiolvasása
    // -------------------------------------------------------------------------

    /**
     * A `foxpost_parcel` workflow nyers konfigurációja a YAML-ból, betöltve
     * és futásidőben ellenőrizve — a `Yaml::parseFile()` visszatérése
     * `mixed`, ezt itt egyszer, valódi ellenőrzésekkel szűkítjük.
     *
     * @return array<array-key, mixed>
     */
    private static function rawConfig(): array
    {
        static $config = null;

        if (null === $config) {
            $path = \dirname(__DIR__, 2) . '/src/Resources/config/app/foxpost_parcel.yaml';
            $parsed = Yaml::parseFile($path);

            $framework = is_array($parsed) ? self::arrayOffset($parsed, 'framework') : null;
            $workflows = is_array($framework) ? self::arrayOffset($framework, 'workflows') : null;
            $workflow = is_array($workflows) ? self::arrayOffset($workflows, 'foxpost_parcel') : null;

            if (!is_array($workflow)) {
                throw new RuntimeException(sprintf(
                    'A(z) "%s" nem a várt framework.workflows.foxpost_parcel szerkezetű.',
                    $path,
                ));
            }

            $config = $workflow;
        }

        return $config;
    }

    /**
     * @param array<array-key, mixed> $array
     */
    private static function arrayOffset(array $array, string $key): mixed
    {
        return $array[$key] ?? null;
    }

    private static function type(): string
    {
        return self::requireString(self::rawConfig(), 'type');
    }

    private static function initialMarking(): string
    {
        return self::requireString(self::rawConfig(), 'initial_marking');
    }

    /**
     * @return array{type: string, property: string}
     */
    private static function markingStore(): array
    {
        $markingStore = self::rawConfig()['marking_store'] ?? null;

        if (!is_array($markingStore)) {
            throw new RuntimeException('A marking_store nem tömb.');
        }

        return [
            'type' => self::requireString($markingStore, 'type'),
            'property' => self::requireString($markingStore, 'property'),
        ];
    }

    /**
     * @return list<string>
     */
    private static function supports(): array
    {
        $supports = self::rawConfig()['supports'] ?? null;

        if (!is_array($supports)) {
            throw new RuntimeException('A supports nem tömb.');
        }

        return self::requireStringList($supports, 'supports');
    }

    /**
     * @return list<string>
     */
    private static function places(): array
    {
        $places = self::rawConfig()['places'] ?? null;

        if (!is_array($places)) {
            throw new RuntimeException('A places nem tömb.');
        }

        return self::requireStringList($places, 'places');
    }

    /**
     * A transitions blokk típusosan ellenőrzött alakja — a `from` mindig
     * listává normalizálva, akkor is, ha a YAML-ban egyetlen string volt.
     *
     * @return array<string, array{from: list<string>, to: string}>
     */
    private static function transitions(): array
    {
        $transitions = self::rawConfig()['transitions'] ?? null;

        if (!is_array($transitions)) {
            throw new RuntimeException('A transitions nem tömb.');
        }

        $result = [];

        foreach ($transitions as $name => $transition) {
            if (!is_string($name) || !is_array($transition)) {
                throw new RuntimeException('A transitions egy bejegyzése nem a várt alakú.');
            }

            $to = self::requireString($transition, 'to');

            $fromRaw = $transition['from'] ?? null;
            $from = is_array($fromRaw) ? self::requireStringList($fromRaw, sprintf('%s.from', $name)) : [self::requireString($transition, 'from')];

            $result[$name] = ['from' => $from, 'to' => $to];
        }

        return $result;
    }

    /**
     * A transitions `from`-onként szétbontott, "name|from|to" alakú listája
     * — ez ad 20 elemet a YAML 14 transitions-kulcsából.
     *
     * @return list<string>
     */
    private static function flattenedTransitions(): array
    {
        $flattened = [];

        foreach (self::transitions() as $name => $transition) {
            foreach ($transition['from'] as $from) {
                $flattened[] = sprintf('%s|%s|%s', $name, $from, $transition['to']);
            }
        }

        return $flattened;
    }

    /**
     * @param array<array-key, mixed> $array
     */
    private static function requireString(array $array, string $key): string
    {
        $value = $array[$key] ?? null;

        if (!is_string($value)) {
            throw new RuntimeException(sprintf('A(z) "%s" kulcs nem string.', $key));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $array
     *
     * @return list<string>
     */
    private static function requireStringList(array $array, string $context): array
    {
        $result = [];

        foreach ($array as $value) {
            if (!is_string($value)) {
                throw new RuntimeException(sprintf('A(z) "%s" listában nem-string elem van.', $context));
            }

            $result[] = $value;
        }

        return $result;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
