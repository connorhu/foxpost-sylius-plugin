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

/**
 * A plugin Stimulus-szerződése (#203).
 *
 * A kiszervezés (#187) előtt a két controller a BOLT
 * `assets/shop/controllers/` könyvtárában élt, és a `require.context`-es
 * betöltő a FÁJLNÉVBŐL képezte az azonosítót — `foxpost-shipping`. A
 * csomagba költözés óta a `@symfony/stimulus-bridge` a NPM-CSOMAGNÉVBŐL
 * képzi (`codeconjure--foxpost-sylius-plugin--foxpost-shipping`), a sablonok
 * viszont maradtak a rövid névnél. A Stimulus az azonosítót szó szerint
 * illeszti, tehát egyik elemre sem csatolódott controller: a pénztár teljes
 * FoxPost-interaktivitása halott volt, **render-hiba nélkül**.
 *
 * Ez a hibamód a repó egyetlen mai kapuján sem bukik el: a `npm run build`
 * zölden lefut, nincs konzol-hiba, a Behat non-JS készlet nem futtat JS-t, a
 * PHPUnit pedig nem látja a bundle-t. Ezért a kapu itt van, és nem a
 * felületen: a teszt a bridge azonosító-képzését (`create-controllers-module`)
 * UGYANÚGY számolja ki, mint a webpack-loader, és a kapott azonosítókat a
 * sablonok `data-*` attribútumaihoz, illetve a controllerek `static
 * targets`/`static values` deklarációjához méri.
 *
 * Ha a `name` kulcs kiesik az `assets/package.json`-ból, vagy egy sablon
 * elír egy azonosítót, egy targetet vagy egy value-t, ez a teszt vált
 * pirosra — nem a felhasználó.
 */
final class StimulusControllerContractTest extends TestCase
{
    private const string NPM_PACKAGE = '@codeconjure/foxpost-sylius-plugin';

    /**
     * A bridge azonosító-képzése akkor helyes, ha a RÖVID nevet adja — azt,
     * amit a sablonok írnak, és amit a kiszervezés előtti betöltő adott.
     */
    #[DataProvider('controllerKeyProvider')]
    public function testTheBridgeRegistersTheControllerUnderItsShortIdentifier(string $key): void
    {
        self::assertSame(
            $key,
            self::bridgeIdentifier($key),
            sprintf(
                'A `%s` controllert a stimulus-bridge nem a rövid `%s` azonosítón regisztrálja. Ez némán megöli a sablonjait: a `name` kulcs kell az `assets/package.json` `symfony.controllers` bejegyzésébe.',
                $key,
                $key,
            ),
        );
    }

    #[DataProvider('controllerKeyProvider')]
    public function testTheControllerEntryPointFileExists(string $key): void
    {
        self::assertFileExists(self::path('assets/' . self::controllerMain($key)));
    }

    #[DataProvider('controllerKeyProvider')]
    public function testEveryRegisteredControllerIsUsedByAtLeastOneTemplate(string $key): void
    {
        $identifier = self::bridgeIdentifier($key);

        self::assertContains(
            $identifier,
            self::identifiersUsedInTemplates(),
            sprintf(
                'A `%s` controllert a bridge regisztrálja, de egyetlen plugin-sablon sem hivatkozik rá `data-controller`-rel — vagy a sablon veszett el, vagy az azonosító csúszott el.',
                $identifier,
            ),
        );
    }

    /**
     * A fordított irány: amit a sablon ír, azt a bridge regisztrálja is.
     * Pont ez bukott a #203-ban.
     */
    #[DataProvider('templateIdentifierProvider')]
    public function testEveryIdentifierTheTemplatesUseIsRegisteredByTheBridge(string $identifier, string $where): void
    {
        self::assertContains(
            $identifier,
            self::registeredIdentifiers(),
            sprintf(
                'A(z) %s a `%s` Stimulus-azonosítóra hivatkozik, de a bridge nem ezen regisztrál controllert — a hivatkozás némán hatástalan.',
                $where,
                $identifier,
            ),
        );
    }

    /**
     * A csomagnév a bridge azonosító-képzésének bemenete: ha elmozdul, a
     * `name` kulcs nélküli azonosítók is elmozdulnak. A #187 egyik javítása
     * (b2ed2d8) pont ezt a nevet igazította érvényes NPM-névre.
     */
    public function testTheNpmPackageNameIsTheOneTheHostInstalls(): void
    {
        $package = self::packageJson();

        self::assertSame(self::NPM_PACKAGE, $package['name'] ?? null);
    }

    public function testEveryTargetAttributeNamesARegisteredControllerAndADeclaredTarget(): void
    {
        $registered = self::registeredIdentifiers();
        $found = 0;

        foreach (self::templateFiles() as $relative => $contents) {
            preg_match_all('/data-([a-z0-9-]+)-target="([^"]+)"/', $contents, $matches, \PREG_SET_ORDER);

            foreach ($matches as $match) {
                ++$found;
                [, $identifier, $target] = $match;

                self::assertContains(
                    $identifier,
                    $registered,
                    sprintf('A `%s` `data-%s-target` attribútuma nem regisztrált controllerre hivatkozik.', $relative, $identifier),
                );

                self::assertContains(
                    $target,
                    self::declaredTargets($identifier),
                    sprintf('A `%s` a `%s` controller nem deklarált `%s` targetjét kéri.', $relative, $identifier, $target),
                );
            }
        }

        self::assertGreaterThan(0, $found, 'A szkenner egyetlen `data-*-target` attribútumot sem talált — a szkenner tört el, nem a kód.');
    }

    public function testEveryValueAttributeNamesARegisteredControllerAndADeclaredValue(): void
    {
        $found = 0;

        foreach (self::templateFiles() as $relative => $contents) {
            preg_match_all('/data-([a-z0-9-]+)-value="/', $contents, $matches, \PREG_SET_ORDER);

            foreach ($matches as $match) {
                ++$found;
                $prefix = $match[1];
                $identifier = self::longestRegisteredPrefixOf($prefix);

                self::assertNotNull(
                    $identifier,
                    sprintf('A `%s` `data-%s-value` attribútuma egyetlen regisztrált controller azonosítójával sem kezdődik.', $relative, $prefix),
                );

                $value = self::camelCase(substr($prefix, strlen((string) $identifier) + 1));

                self::assertContains(
                    $value,
                    self::declaredValues((string) $identifier),
                    sprintf('A `%s` a `%s` controller nem deklarált `%s` value-ját adja át.', $relative, $identifier, $value),
                );
            }
        }

        self::assertGreaterThan(0, $found, 'A szkenner egyetlen `data-*-value` attribútumot sem talált — a szkenner tört el, nem a kód.');
    }

    /** @return iterable<string, array{string}> */
    public static function controllerKeyProvider(): iterable
    {
        foreach (array_keys(self::packageControllers()) as $key) {
            yield (string) $key => [(string) $key];
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function templateIdentifierProvider(): iterable
    {
        $seen = [];

        foreach (self::templateFiles() as $relative => $contents) {
            foreach (self::identifiersIn($contents) as $identifier => $where) {
                $label = $relative . ' ' . $where;

                if (isset($seen[$identifier . $label])) {
                    continue;
                }

                $seen[$identifier . $label] = true;

                yield $relative . ': ' . $identifier . ' (' . $where . ')' => [$identifier, $relative . ' ' . $where . ' attribútuma'];
            }
        }

        if ([] === $seen) {
            throw new RuntimeException('A szkenner egyetlen Stimulus-azonosítót sem talált a sablonokban — a szkenner tört el');
        }
    }

    /**
     * A `@symfony/stimulus-bridge` `create-controllers-module.ts`-ének
     * azonosító-képzése, szó szerint: a csomagnév + a controller kulcsa, a
     * kezdő `@` nélkül, `_` → `-`, `/` → `--`; a csomag `name` kulcsa pedig
     * felülírja az egészet.
     *
     * (A host `controllers.json`-ja is tud `name`-et felülírni, de a Symfony
     * Flex `PackageJsonSynchronizer`-e csak az `enabled`, `fetch` és
     * `autoimport` kulcsokat írja vissza — egy ottani `name` minden
     * `composer update`-nél elvesznék. Ezért itt a helye.)
     */
    private static function bridgeIdentifier(string $key): string
    {
        $config = self::packageControllers()[$key] ?? throw new RuntimeException(sprintf('Nincs `%s` controller az assets/package.json-ban', $key));

        if (!\is_array($config)) {
            throw new RuntimeException(sprintf('A `%s` controller bejegyzése nem objektum', $key));
        }

        $name = $config['name'] ?? null;

        if (\is_string($name)) {
            return str_replace('/', '--', $name);
        }

        return str_replace(['_', '/'], ['-', '--'], substr(self::NPM_PACKAGE . '/' . $key, 1));
    }

    /** @return list<string> */
    private static function registeredIdentifiers(): array
    {
        return array_map(
            static fn (string $key): string => self::bridgeIdentifier($key),
            array_keys(self::packageControllers()),
        );
    }

    private static function longestRegisteredPrefixOf(string $prefix): ?string
    {
        $candidates = array_filter(
            self::registeredIdentifiers(),
            static fn (string $identifier): bool => str_starts_with($prefix, $identifier . '-'),
        );

        if ([] === $candidates) {
            return null;
        }

        usort($candidates, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $candidates[0];
    }

    /** @return array<array-key, mixed> */
    private static function packageJson(): array
    {
        $package = json_decode((string) file_get_contents(self::path('assets/package.json')), true, flags: \JSON_THROW_ON_ERROR);

        if (!\is_array($package)) {
            throw new RuntimeException('Az assets/package.json nem objektum');
        }

        return $package;
    }

    /** @return array<array-key, mixed> */
    private static function packageControllers(): array
    {
        $symfony = self::packageJson()['symfony'] ?? null;
        $controllers = \is_array($symfony) ? ($symfony['controllers'] ?? null) : null;

        if (!\is_array($controllers) || [] === $controllers) {
            throw new RuntimeException('Az assets/package.json `symfony.controllers` kulcsa hiányzik vagy üres');
        }

        return $controllers;
    }

    private static function controllerMain(string $key): string
    {
        $config = self::packageControllers()[$key] ?? null;
        $main = \is_array($config) ? ($config['main'] ?? null) : null;

        if (!\is_string($main)) {
            throw new RuntimeException(sprintf('A `%s` controllernek nincs `main` kulcsa', $key));
        }

        return $main;
    }

    /** @return array<string, string> azonosító => hol találtuk */
    private static function identifiersIn(string $contents): array
    {
        $identifiers = [];

        preg_match_all('/data-controller="([^"]+)"/', $contents, $matches);

        foreach ($matches[1] as $value) {
            foreach (preg_split('/\s+/', trim($value)) ?: [] as $identifier) {
                if ('' !== $identifier) {
                    $identifiers[$identifier] = 'data-controller';
                }
            }
        }

        preg_match_all('/data-action="([^"]+)"/', $contents, $matches);

        foreach ($matches[1] as $value) {
            preg_match_all('/(?:[^\s>]+->)?([a-z0-9-]+)#\w+/', $value, $descriptors);

            foreach ($descriptors[1] as $identifier) {
                $identifiers[$identifier] = 'data-action';
            }
        }

        return $identifiers;
    }

    /** @return list<string> */
    private static function identifiersUsedInTemplates(): array
    {
        $identifiers = [];

        foreach (self::templateFiles() as $contents) {
            foreach (array_keys(self::identifiersIn($contents)) as $identifier) {
                $identifiers[$identifier] = true;
            }
        }

        return array_keys($identifiers);
    }

    /** @return list<string> */
    private static function declaredTargets(string $identifier): array
    {
        if (!preg_match('/static\s+targets\s*=\s*\[(.*?)\]/s', self::controllerSource($identifier), $matches)) {
            return [];
        }

        preg_match_all('/[\'"]([^\'"]+)[\'"]/', $matches[1], $targets);

        return $targets[1];
    }

    /** @return list<string> */
    private static function declaredValues(string $identifier): array
    {
        if (!preg_match('/static\s+values\s*=\s*\{(.*?)\}/s', self::controllerSource($identifier), $matches)) {
            return [];
        }

        preg_match_all('/(\w+)\s*:/', $matches[1], $values);

        return $values[1];
    }

    private static function controllerSource(string $identifier): string
    {
        foreach (array_keys(self::packageControllers()) as $key) {
            if (self::bridgeIdentifier((string) $key) !== $identifier) {
                continue;
            }

            return (string) file_get_contents(self::path('assets/' . self::controllerMain((string) $key)));
        }

        throw new RuntimeException(sprintf('Nincs `%s` azonosítójú controller', $identifier));
    }

    /** @return array<string, string> útvonal => tartalom */
    private static function templateFiles(): array
    {
        $files = [];
        $root = self::path('templates');

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ('twig' !== $file->getExtension()) {
                continue;
            }

            $files['templates/' . substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
        }

        ksort($files);

        return $files;
    }

    private static function camelCase(string $dashed): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $dashed))));
    }

    private static function path(string $relative): string
    {
        return \dirname(__DIR__, 2) . '/' . $relative;
    }
}
