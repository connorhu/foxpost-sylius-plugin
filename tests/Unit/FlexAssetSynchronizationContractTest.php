<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A plugin szerződése a befogadó bolt Flexével (#197).
 *
 * A `Symfony\Flex\PackageJsonSynchronizer` minden `composer update` után a
 * NULLÁRÓL írja újra a host `assets/controllers.json`-jának `controllers`
 * kulcsát, és csak azoknak a csomagoknak a bejegyzését teszi vissza,
 * amelyeket felismer. A felismerés egyetlen feltételen áll
 * (`PackageJsonSynchronizer::resolvePackageJson()`):
 *
 * ```php
 * if (!\in_array('symfony-ux', $phpPackage['keywords'] ?? [], true)) {
 *     return null;
 * }
 * ```
 *
 * A `$phpPackage` a host **composer.lock**-bejegyzése, az pedig ennek a
 * csomagnak a `composer.json`-jából származik. Kulcsszó nélkül tehát a Flex
 * minden update-nél kitörli a plugin blokkját — a FoxPost boltban ez
 * **ötször** meg is történt (#197) —, és a két pénztár-controller némán nem
 * regisztrálódik: a wrapper ott van, a viselkedés nincs, hibát semmi nem ad.
 *
 * Ez a hibamód se a `npm run build`-en, se a PHPUnit-on, se a non-JS Behaten
 * nem bukik el. Ezért a kapu itt van, a keletkezés helyén: ami a Flex
 * felismeréséhez kell, azt a teszt a Flex kódútjából visszafejtve méri, nem
 * egy másolt listából.
 *
 * Párja a host oldalán: a bolt tesztje azt méri, hogy a blokk a
 * `controllers.json`-ban VAN-E, és hogy a lock-bejegyzés hordozza-e a
 * kulcsszót. Az azonosítók szerződését a
 * {@see StimulusControllerContractTest} őrzi.
 */
final class FlexAssetSynchronizationContractTest extends TestCase
{
    /**
     * A Flex ezt a három alkönyvtárat próbálja végig, ebben a sorrendben
     * (`PackageJsonSynchronizer::resolvePackageJson()`).
     */
    private const array FLEX_PROBED_SUBDIRS = ['assets', 'Resources/assets', 'src/Resources/assets'];

    /**
     * A javítás maga (#197). Ha ez a kulcsszó kiesik, a Flex a következő
     * `composer update`-nél NÉMÁN kitörli a plugin bejegyzését a host
     * `assets/controllers.json`-jából.
     */
    public function testTheComposerMetadataCarriesTheSymfonyUxKeyword(): void
    {
        self::assertContains(
            'symfony-ux',
            self::keywords(),
            'A `composer.json` `keywords` listájából kiesett a `symfony-ux`. Ettől a befogadó bolt Flexe ' .
            'nem ismeri fel a csomagot, és a KÖVETKEZŐ `composer update` kitörli a plugin bejegyzését az ' .
            '`assets/controllers.json`-ból: a pénztár csomagautomata-választója és telefon-panelje némán ' .
            'eltűnik — nincs hiba, nincs konzol-üzenet, a build zöld.',
        );
    }

    /**
     * A kulcsszó csak akkor ér valamit, ha a Flex meg is TALÁLJA a csomag
     * `package.json`-ját: a felismerés után a három próbált alkönyvtárból
     * olvassa a `symfony.controllers` listát, és ha egyikben sincs, ugyanúgy
     * `null`-lal tér vissza, mint kulcsszó nélkül.
     */
    public function testThePackageJsonSitsWhereFlexLooksForIt(): void
    {
        $found = array_values(array_filter(
            self::FLEX_PROBED_SUBDIRS,
            static fn (string $subdir): bool => file_exists(self::path($subdir . '/package.json')),
        ));

        self::assertNotEmpty(
            $found,
            sprintf(
                'A Flex a csomag `package.json`-ját csak ebben a három alkönyvtárban keresi: %s. Máshonnan ' .
                'nem olvassa, és a `symfony-ux` kulcsszó ellenére sem regisztrálja a controllereket.',
                implode(', ', self::FLEX_PROBED_SUBDIRS),
            ),
        );
    }

    /**
     * A `controllers.json` kulcsát a Flex a PHP-csomagnévből képzi
     * (`'@' . $phpPackage['name']`), a bundle-t viszont a webpack az NPM-
     * csomagnévvel oldja fel. Ha a kettő elválik, a host `controllers.json`-ja
     * egy olyan csomagra hivatkozik, amit az npm nem ismer — a bridge a
     * bejegyzést csendben kihagyja.
     */
    public function testTheNpmPackageNameIsTheComposerNameWithALeadingAt(): void
    {
        $composerName = self::composerJson()['name'] ?? null;
        $npmName = self::packageJson()['name'] ?? null;

        self::assertIsString($composerName);
        self::assertIsString($npmName);

        self::assertSame(
            '@' . $composerName,
            $npmName,
            'Az NPM-csomagnév nem a Composer-csomagnév `@`-cal. A Flex a `controllers.json` kulcsát a ' .
            'Composer-névből képzi, a webpack pedig az NPM-nevet oldja fel: eltérésnél a bejegyzés egy ' .
            'nem létező csomagra mutat, és a controllerek némán kimaradnak.',
        );
    }

    /**
     * A Flex a frissen felvett csomag bejegyzését a plugin saját
     * alapértelmezéseiből állítja elő, és az `enabled` kulcsot
     * **alapértelmezés nélkül** olvassa (`$config['enabled'] =
     * $defaultConfig['enabled'];`) — a `fetch` mellett ott van a `?? 'eager'`,
     * az `enabled` mellett nincs. Hiányában a szinkronizálás a befogadó bolt
     * `composer update`-je közben hibázik el.
     */
    #[DataProvider('controllerKeyProvider')]
    public function testEveryControllerDeclaresTheKeysFlexReadsFromThePackage(string $key): void
    {
        $config = self::controllers()[$key];
        self::assertIsArray($config);

        self::assertArrayHasKey(
            'enabled',
            $config,
            sprintf(
                'A `%s` controller bejegyzéséből hiányzik az `enabled` kulcs. A Flex ezt alapértelmezés ' .
                'nélkül olvassa, amikor a csomagot ELSŐ alkalommal veszi fel a host `controllers.json`-jába.',
                $key,
            ),
        );

        self::assertTrue(
            $config['enabled'],
            sprintf('A `%s` controller alapértelmezése `enabled: false` — a befogadó bolt kikapcsolva kapja meg.', $key),
        );
    }

    /**
     * A kulcsszó egy szó egy listában: aki nem tudja, mire való, az egy
     * „rendrakás" közben kiveszi. A telepítési dokumentum ezért nevezi meg.
     */
    public function testTheInstallationDocumentExplainsTheKeywordRequirement(): void
    {
        $doc = self::read('docs/installation.md');

        self::assertStringContainsString(
            'symfony-ux',
            $doc,
            'A `docs/installation.md` nem nevezi meg a `symfony-ux` kulcsszót — pedig ez a csomag ' .
            'egyetlen kapcsolódási pontja a host Flexéhez, és a kiesése néma.',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function controllerKeyProvider(): iterable
    {
        foreach (array_keys(self::controllers()) as $key) {
            self::assertIsString($key);

            yield $key => [$key];
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function controllers(): array
    {
        $symfony = self::packageJson()['symfony'] ?? null;
        $controllers = \is_array($symfony) ? ($symfony['controllers'] ?? null) : null;

        if (!\is_array($controllers) || $controllers === []) {
            throw new RuntimeException('Az assets/package.json `symfony.controllers` kulcsa hiányzik vagy üres');
        }

        return $controllers;
    }

    /**
     * @return list<mixed>
     */
    private static function keywords(): array
    {
        $keywords = self::composerJson()['keywords'] ?? null;

        if (!\is_array($keywords)) {
            throw new RuntimeException('A composer.json-nak nincs `keywords` listája');
        }

        return array_values($keywords);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function composerJson(): array
    {
        return self::decode('composer.json');
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function packageJson(): array
    {
        return self::decode('assets/package.json');
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decode(string $relative): array
    {
        $decoded = json_decode(self::read($relative), true, 512, \JSON_THROW_ON_ERROR);

        if (!\is_array($decoded)) {
            throw new RuntimeException(sprintf('A `%s` nem objektum', $relative));
        }

        return $decoded;
    }

    private static function read(string $relative): string
    {
        $contents = file_get_contents(self::path($relative));

        if ($contents === false) {
            throw new RuntimeException(sprintf('A `%s` nem olvasható', $relative));
        }

        return $contents;
    }

    private static function path(string $relative): string
    {
        return \dirname(__DIR__, 2) . '/' . $relative;
    }
}
