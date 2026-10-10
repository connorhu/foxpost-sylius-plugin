<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A #195 szerződése: a plugin KIADHATÓ, és a kiadás le is van írva.
 *
 * A kiszervezés (#116) után a bolt produkciós kódja ennek a csomagnak a `main`
 * ágán állt — és a plugin a magcsomagot is `dev-main`-en kérte. Mérve
 * 2026-10-10-én a `repos/connorhu/foxpost-sylius-plugin/tags` és a
 * `repos/connorhu/foxpost-lib/tags` is üres listát adott. Ez a lánc azt
 * jelentette, hogy EGY push a magcsomag `main`-jére két lépésben, verziókapu
 * nélkül, breaking change jelzése nélkül ért el a bolt szállítási
 * folyamatáig.
 *
 * A tagot ez a teszt NEM tudja mérni, és nem is próbálja: a tag a munkafán
 * kívül él, a CI `actions/checkout`-ja pedig alapértelmezésben nem is hozza
 * le. Azt a két dolgot méri, ami a munkafában van, és ami a tag mellől némán
 * kirothad:
 *
 * 1. a `CHANGELOG.md` önkonzisztenciáját — a következő tag diffje csak akkor
 *    értelmezhető, ha minden kiadott verziónak van dátumozott szakasza ÉS
 *    hivatkozása;
 * 2. a `composer.json` kiadhatóságát — a futásidejű követelmények közül egyik
 *    sem mutathat `dev-` ágra. Itt ez nem elméleti: pontosan a
 *    `codeconjure/foxpost` volt az, ami `dev-main`-en állt, és amit a plugin
 *    minden fogyasztója örökölt.
 *
 * A testvére a `ContinuousIntegrationContractTest::testTheManifestDeclaresNoPathRepository()`:
 * az a manifeszt TELEPÍTHETŐSÉGÉT őrzi, ez a KIADHATÓSÁGÁT. A kettő együtt
 * mondja ki, hogy a commitolt manifeszttel egy idegen klón nem csak feláll,
 * hanem rögzített verziókra is áll.
 */
final class ReleaseContractTest extends TestCase
{
    private const string CHANGELOG = 'CHANGELOG.md';

    /**
     * A kiadott verziók szakaszcíme. A „Nem kiadott" szakasz szándékosan nem
     * illeszkedik: annak nincs dátuma, és nem is kap taget.
     */
    private const string RELEASED_HEADING = '/^## \[(\d+\.\d+\.\d+)\][^\S\n]*[–-][^\S\n]*(\d{4}-\d{2}-\d{2})[^\S\n]*$/mu';

    public function testTheChangelogExists(): void
    {
        self::assertFileExists(
            self::path(self::CHANGELOG),
            'A csomagnak nincs változásnaplója. Tag mellé enélkül senki nem tudja ' .
            'megmondani, mi van egy kiadásban — és mi változott a következőig.',
        );
    }

    public function testTheChangelogDocumentsAtLeastOneReleasedVersion(): void
    {
        self::assertNotSame(
            0,
            preg_match_all(self::RELEASED_HEADING, self::changelog()),
            'A változásnaplóban nincs egyetlen `## [x.y.z] – ÉÉÉÉ-HH-NN` formájú kiadás sem. ' .
            'A csomag így tag nélküli `dev-main`-en áll, ami a #195 kiindulási állapota.',
        );
    }

    /**
     * A naplót felülről olvassák. Ha egy új kiadás a régi ALÁ kerül, a
     * „mi változott legutóbb" kérdésre a fájl rossz választ ad — és ez a
     * fajta hiba nem tűnik fel, mert a fájl továbbra is teljesen rendben
     * NÉZ ki.
     */
    public function testTheReleasedVersionsAreListedNewestFirst(): void
    {
        $versions = self::releasedVersions();
        $sorted = $versions;
        usort($sorted, static fn (string $a, string $b): int => version_compare($b, $a));

        self::assertSame(
            $sorted,
            $versions,
            'A változásnapló kiadásai nem csökkenő verziósorrendben állnak.',
        );
    }

    /**
     * Minden kiadott verzióhoz kell egy hivatkozás-definíció a napló alján
     * (`[0.1.0]: https://...`). Ez az a sor, ami a `## [0.1.0]` címből
     * kattinthatót csinál — és pontosan ez az, amit egy új kiadásnál el
     * szokás felejteni: a cím szögletes zárójelben marad, link nélkül.
     */
    public function testEveryReleasedVersionCarriesALinkDefinition(): void
    {
        $changelog = self::changelog();

        foreach (self::releasedVersions() as $version) {
            self::assertMatchesRegularExpression(
                '/^\[' . preg_quote($version, '/') . '\]:\s*\S+$/m',
                $changelog,
                \sprintf('A `%s` kiadásnak nincs hivatkozás-definíciója a változásnapló alján.', $version),
            );
        }
    }

    /**
     * A plugin futásidejű követelményei között nem lehet ágra mutató (`dev-`)
     * hivatkozás. A `require-dev`-et szándékosan nem méri: azt a fogyasztó
     * nem örökli.
     */
    public function testNoRuntimeRequirementPointsAtADevelopmentBranch(): void
    {
        foreach (self::runtimeRequirements() as $package => $constraint) {
            self::assertDoesNotMatchRegularExpression(
                '/(^|\|\|?|\s)\s*dev-|-dev\b|@dev\b/',
                $constraint,
                \sprintf(
                    'A `%s` követelménye (`%s`) fejlesztési ágra mutat. Ezt a plugin minden ' .
                    'fogyasztója örökli, verziókapu nélkül — tageld a függőséget, és írj rá constraintet.',
                    $package,
                    $constraint,
                ),
            );
        }
    }

    /**
     * A magcsomagra külön állítás kell, nem csak a `dev-` tilalom: a
     * `codeconjure/foxpost` nélkül a plugin nem is működik, tehát a
     * követelménynek LÉTEZNIE kell — egy véletlen törlés (vagy a `require-dev`-be
     * csúszás) ugyanúgy néma, mint egy `dev-main` pin volt.
     */
    public function testTheCoreProtocolPackageIsRequiredOnATaggedConstraint(): void
    {
        $requirements = self::runtimeRequirements();

        self::assertArrayHasKey(
            'codeconjure/foxpost',
            $requirements,
            'A plugin nem kéri futásidőben a `codeconjure/foxpost` magcsomagot.',
        );

        self::assertMatchesRegularExpression(
            '/^[\^~]\d/',
            $requirements['codeconjure/foxpost'],
            \sprintf(
                'A `codeconjure/foxpost` constraintje (`%s`) nem tagolt verzióra mutat. ' .
                'A #195 pont ezt a láncot vágta el: a mag `dev-main`-je két lépésben ért el a boltig.',
                $requirements['codeconjure/foxpost'],
            ),
        );
    }

    /** @return list<string> */
    private static function releasedVersions(): array
    {
        preg_match_all(self::RELEASED_HEADING, self::changelog(), $matches);

        return $matches[1];
    }

    /** @return array<string, string> */
    private static function runtimeRequirements(): array
    {
        $require = self::decode('composer.json')['require'] ?? null;
        self::assertIsArray($require, 'A `composer.json` nem deklarál `require` szakaszt.');

        $requirements = [];

        foreach ($require as $package => $constraint) {
            if ($package === 'php' || !\is_string($constraint)) {
                continue;
            }

            $requirements[(string) $package] = $constraint;
        }

        return $requirements;
    }

    private static function changelog(): string
    {
        return self::read(self::CHANGELOG);
    }

    /** @return array<mixed> */
    private static function decode(string $relative): array
    {
        $decoded = json_decode(self::read($relative), true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));

        return $decoded;
    }

    private static function read(string $relative): string
    {
        $contents = file_get_contents(self::path($relative));
        \assert(\is_string($contents));

        return $contents;
    }

    private static function path(string $relative): string
    {
        return \dirname(__DIR__, 2) . '/' . $relative;
    }
}
