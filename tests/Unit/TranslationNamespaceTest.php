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
 * A plugin fordítási névtér-szerződése (#192).
 *
 * A #192 előtt a grid 24 címkéje, az admin menüpont és a négy form-mezőcímke a
 * host `app.*` névterében élt, a bolt saját `translations/`-ából feloldva. Ez
 * két bukási módot adott, és MINDKETTŐ néma: a plugin nem hibázik, csak nyers
 * kulcsot ír ki. Egy másik bolt a csatorna-, a szállításimód- és a pénztár
 * automata-paneljén `app.form.channel.foxpost_label_page_size`-féle szöveget
 * látott; a `shipping_method.default_size` párnak pedig EBBEN a boltban sem
 * volt fordítása, mert a plugin a hostra bízta, a host meg nem szállította.
 *
 * Ezért négy állítás:
 *   1. a plugin egyetlen fordítási kulcsa sem mutat az `app.*` névtérbe;
 *   2. a plugin katalógusainak egyetlen gyökere `codeconjure`;
 *   3. minden STATIKUSAN hivatkozott `codeconjure.foxpost.*` kulcs fel is
 *      oldódik, mindkét nyelven;
 *   4. a két nyelv kulcskészlete azonos.
 *
 * A 3. pont nem önmagát igazoló kör: a hivatkozásokat a kódból és a
 * grid-konfigból olvassuk, a feloldást a katalógusokból — a két oldal
 * egymástól független fájl, egy átnevezés fele mindig kibukik.
 *
 * NEM tartozik ide a `src/Resources/config/routes.yaml` `alias: app.foxpost_parcel`
 * sora: az Sylius resource-alias, nem fordítási kulcs — a belőle képzett
 * `app_admin_foxpost_parcel_*` route-nevekre a befogadó bolt hivatkozik
 * (l. #193), átnevezése a telepítési szerződés kérdése (#194).
 */
final class TranslationNamespaceTest extends TestCase
{
    private const string PLUGIN_PREFIX = 'codeconjure.foxpost.';

    public function testNoTranslationKeyPointsAtTheHostAppNamespace(): void
    {
        $offenders = [];

        foreach (self::keyBearingFiles() as $path) {
            foreach (self::referencedKeys($path) as $line => $key) {
                if (str_starts_with($key, 'app.')) {
                    $offenders[] = sprintf('%s:%d %s', basename($path), $line, $key);
                }
            }
        }

        self::assertSame([], $offenders, 'A plugin a host `app.*` névterébe hivatkozik');
    }

    #[DataProvider('catalogueProvider')]
    public function testCatalogueHasOnlyThePluginRoot(string $path): void
    {
        self::assertSame(['codeconjure'], array_keys(self::parseCatalogue($path)), basename($path));
    }

    /** @return iterable<string, array{string}> */
    public static function catalogueProvider(): iterable
    {
        foreach (self::catalogues() as $path) {
            yield basename($path) => [$path];
        }
    }

    public function testEveryReferencedPluginKeyResolvesInBothLocales(): void
    {
        $catalogues = [
            'hu' => self::flatten(self::parseCatalogue(self::translationsDir() . '/messages.hu.yaml')),
            'en' => self::flatten(self::parseCatalogue(self::translationsDir() . '/messages.en.yaml')),
        ];

        $referenced = [];
        foreach (self::keyBearingFiles() as $path) {
            foreach (self::referencedKeys($path) as $key) {
                if (str_starts_with($key, self::PLUGIN_PREFIX)) {
                    $referenced[$key] = true;
                }
            }
        }

        self::assertNotEmpty($referenced, 'A kulcsgyűjtés nem talált semmit — a szkenner tört el');

        $missing = ['hu' => [], 'en' => []];
        foreach (array_keys($referenced) as $key) {
            foreach ($catalogues as $locale => $catalogue) {
                if (!isset($catalogue[$key])) {
                    $missing[$locale][] = $key;
                }
            }
        }

        self::assertSame(['hu' => [], 'en' => []], $missing, 'Hivatkozott, de le nem fordított kulcs');
    }

    public function testBothLocalesCarryTheSameKeys(): void
    {
        $hu = array_keys(self::flatten(self::parseCatalogue(self::translationsDir() . '/messages.hu.yaml')));
        $en = array_keys(self::flatten(self::parseCatalogue(self::translationsDir() . '/messages.en.yaml')));

        sort($hu);
        sort($en);

        self::assertSame($hu, $en);
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function translationsDir(): string
    {
        return self::root() . '/translations';
    }

    /** @return list<string> */
    private static function catalogues(): array
    {
        $paths = glob(self::translationsDir() . '/*.yaml');

        if (false === $paths || [] === $paths) {
            throw new RuntimeException('Nincs fordítási katalógus a translations/ alatt');
        }

        return $paths;
    }

    /**
     * A fordítási kulcsokat hordozó fájlok — a konfigból csak a grid.
     *
     * @return list<string>
     */
    private static function keyBearingFiles(): array
    {
        $files = [self::root() . '/src/Resources/config/app/grid.yaml'];

        foreach (['/src', '/templates'] as $dir) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(self::root() . $dir, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && in_array($file->getExtension(), ['php', 'twig'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Fordítási kulcs-hivatkozások egy fájlból, soronként.
     *
     * A pozíciókat szűken fogjuk, hogy a szolgáltatás-azonosítók és a
     * doc-blokk említések ne kerüljenek be: PHP-ban csak idézőjeles literál,
     * Twigben csak `'…'|trans`, a gridben a `label:` érték és a `choices:`
     * kulcsai.
     *
     * @return array<int, string> sor => kulcs
     */
    private static function referencedKeys(string $path): array
    {
        $lines = file($path, \FILE_IGNORE_NEW_LINES);

        if (false === $lines) {
            throw new RuntimeException(sprintf('Nem olvasható: %s', $path));
        }

        $patterns = match (true) {
            str_ends_with($path, 'grid.yaml') => ['/label:\s*([a-z][\w.]+)/', '/^\s{10,}([a-z][\w.]+):\s*\w+$/'],
            str_ends_with($path, '.twig') => ["/'([a-z][\\w.]+)'\\|trans/"],
            default => ['/\'([a-z][\w.]+\.[\w.]+)\'/'],
        };

        $keys = [];
        foreach ($lines as $index => $line) {
            foreach ($patterns as $pattern) {
                if (0 === preg_match_all($pattern, $line, $matches)) {
                    continue;
                }

                foreach ($matches[1] as $key) {
                    // A fájlnevek (`grid.yaml`, `services.xml`) nem kulcsok.
                    if (str_contains($key, '.') && 0 === preg_match('/\.(yaml|xml|twig|php|json|js)$/', $key)) {
                        $keys[$index + 1] = $key;
                    }
                }
            }
        }

        return $keys;
    }

    /** @return array<array-key, mixed> */
    private static function parseCatalogue(string $path): array
    {
        $parsed = Yaml::parseFile($path);

        if (!is_array($parsed)) {
            throw new RuntimeException(sprintf('Nem kulcsfa: %s', $path));
        }

        return $parsed;
    }

    /**
     * @param array<array-key, mixed> $tree
     *
     * @return array<string, string>
     */
    private static function flatten(array $tree, string $prefix = ''): array
    {
        $flat = [];
        foreach ($tree as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $flat += self::flatten($value, $path);
            } elseif (is_scalar($value)) {
                $flat[$path] = (string) $value;
            }
        }

        return $flat;
    }
}
