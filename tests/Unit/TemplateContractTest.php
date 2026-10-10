<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;

/**
 * A plugin sablon-szerződése (#193).
 *
 * A #193 előtt két felületi ág a BOLT `templates/` könyvtárában élt: az admin
 * szállítási panel feladás-gombja (bedrótozott plugin-route-tal) és a
 * rendelés-összegző automata-sora (a plugin `foxpost_pickup_point` szűrőjével).
 * Mindkettő néma hordozhatósági hiba volt: egy másik bolt meg sem kapta őket, a
 * mienk pedig a plugin eltávolítása után `RouteNotFoundException`-re és
 * `UnknownExtension`-re futott.
 *
 * Három állítás, mind a mozgatás irányába:
 *   1. a plugin MINDEN `@CodeConjureSyliusFoxPostPlugin/…twig` hivatkozása
 *      létező fájlra mutat (a konfig és a sablonkönyvtár nem csúszhat el);
 *   2. a #193-ban átvett két ág a vártnál van bekötve — a hookable-név és a
 *      sablon útvonala együtt, hogy egy néma átnevezés kibukjon;
 *   3. a plugin egyetlen fájlja sem hivatkozik a host `App\` névterére — ezt a
 *      szabályt a spec a kiszervezéskor húzta meg, és pont a most átvett két
 *      sablon sértette meg (`enum('\\App\\Entity\\Enums\\…')`).
 */
final class TemplateContractTest extends TestCase
{
    private const string NAMESPACE_PREFIX = '@CodeConjureSyliusFoxPostPlugin/';

    #[DataProvider('templateReferenceProvider')]
    public function testEveryTemplateReferencePointsAtAnExistingFile(string $reference): void
    {
        $relative = substr($reference, strlen(self::NAMESPACE_PREFIX));

        self::assertFileExists(self::root() . '/templates/' . $relative);
    }

    /** @return iterable<string, array{string}> */
    public static function templateReferenceProvider(): iterable
    {
        $references = [];

        foreach (self::configFiles() as $path) {
            foreach (self::collectTemplateReferences(self::parseYaml($path)) as $reference) {
                $references[$reference] = true;
            }
        }

        if ([] === $references) {
            throw new RuntimeException('A hivatkozás-gyűjtés nem talált semmit — a szkenner tört el');
        }

        foreach (array_keys($references) as $reference) {
            yield $reference => [$reference];
        }
    }

    /**
     * A #193-ban átvett két ág bekötése, hookable-névvel együtt.
     *
     * A `pickup_point` hook neve ÚJ (a Sylius nem foglalja), ezért a plugin
     * `prepend()`-je ténylegesen hozzáadja. A `ship` kulcs viszont a
     * SyliusAdminBundle-é: azt a `prepend()` nem tudja átállítani, a
     * `props.template` cseréje a befogadó bolt egy sora (README). Ez a teszt
     * így a plugin SZÁNDÉKÁT őrzi mindkettőnél; hogy a hostban meg is történt,
     * azt a bolt `FoxPostPluginWiringTest`-je méri.
     *
     * @param list<string> $path
     */
    #[DataProvider('hookWiringProvider')]
    public function testTheMovedBranchesAreWiredToThePluginTemplates(array $path, string $expected): void
    {
        self::assertSame($expected, self::valueAt(self::parseYaml(self::root() . '/src/Resources/config/app/hooks.yaml'), $path));
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function hookWiringProvider(): iterable
    {
        yield 'rendelés-összegző átvételi pont' => [
            [
                'sylius_twig_hooks',
                'hooks',
                'sylius_shop.shared.order.show.summary.addresses.shipping.pickup_point',
                'foxpost',
                'template',
            ],
            self::NAMESPACE_PREFIX . 'shop/order/summary_pickup_point.html.twig',
        ];

        yield 'admin szállítási panel' => [
            [
                'sylius_twig_hooks',
                'hooks',
                'sylius_admin.order.show.content.sections.shipments.item.actions',
                'ship',
                'props',
                'template',
            ],
            self::NAMESPACE_PREFIX . 'admin/shipment/ship.html.twig',
        ];
    }

    public function testNoPluginFileReachesIntoTheHostNamespace(): void
    {
        $offenders = [];

        foreach (self::scannedFiles() as $path) {
            $contents = file_get_contents($path);

            if (false === $contents) {
                throw new RuntimeException(sprintf('Nem olvasható: %s', $path));
            }

            if (str_contains($contents, 'App\\')) {
                $offenders[] = substr($path, strlen(self::root()) + 1);
            }
        }

        self::assertSame([], $offenders, 'A plugin a host `App\\` névterére hivatkozik');
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return list<string> */
    private static function configFiles(): array
    {
        $paths = glob(self::root() . '/src/Resources/config/{,app/}*.yaml', \GLOB_BRACE);

        if (false === $paths || [] === $paths) {
            throw new RuntimeException('Nincs konfigurációs YAML a src/Resources/config alatt');
        }

        return $paths;
    }

    /** @return list<string> */
    private static function scannedFiles(): array
    {
        $files = self::configFiles();

        foreach (['/src', '/templates'] as $dir) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(self::root() . $dir, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && in_array($file->getExtension(), ['php', 'twig', 'xml'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    /** @return array<array-key, mixed> */
    private static function parseYaml(string $path): array
    {
        $parsed = Yaml::parseFile($path);

        if (!is_array($parsed)) {
            throw new RuntimeException(sprintf('Nem kulcsfa: %s', $path));
        }

        return $parsed;
    }

    /**
     * Minden `@CodeConjureSyliusFoxPostPlugin/…twig` string a fában, bárhol.
     *
     * Szándékosan nem kulcsútvonalakon jár: a grid akció-sablonjai, a
     * form-téma és a hookok mind máshol tartják a `template` kulcsot, és egy új
     * hely (pl. egy jövőbeli `props.template`) így magától bekerül a mérésbe.
     *
     * @param array<array-key, mixed> $tree
     *
     * @return list<string>
     */
    private static function collectTemplateReferences(array $tree): array
    {
        $references = [];

        foreach ($tree as $value) {
            if (is_array($value)) {
                $references = [...$references, ...self::collectTemplateReferences($value)];

                continue;
            }

            if (is_string($value) && str_starts_with($value, self::NAMESPACE_PREFIX) && str_ends_with($value, '.twig')) {
                $references[] = $value;
            }
        }

        return $references;
    }

    /**
     * @param array<array-key, mixed> $tree
     * @param list<string> $path
     */
    private static function valueAt(array $tree, array $path): string
    {
        $cursor = $tree;

        foreach ($path as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                self::fail(sprintf('Nincs ilyen konfigurációs útvonal: %s', implode(' → ', $path)));
            }

            $cursor = $cursor[$segment];
        }

        self::assertIsString($cursor, implode(' → ', $path));

        return $cursor;
    }
}
