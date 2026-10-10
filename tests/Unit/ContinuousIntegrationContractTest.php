<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A #196 szerződése: a csomag HORDOZZA a kapuit, és futtatja is őket.
 *
 * A kiszervezés után ez a plugin három kapukonfigurációval élt
 * (`phpunit.xml.dist`, `phpstan.dist.neon`, `ecs.php`) és 168 zöld teszttel,
 * de **egyetlen CI-futás nélkül**: mérve 2026-10-10-én a
 * `repos/connorhu/foxpost-sylius-plugin/actions/workflows` 0 munkafolyamatot
 * adott. A kapuk léteztek, csak nem futtatta őket senki — egy külső
 * közreműködő PR-ján semmi nem futott, a `feat/99` ág három statikus hibája
 * pedig hetekig ott állt észrevétlenül, mert a tesztek zöldek voltak, tehát
 * semmi nem jelzett.
 *
 * Ez a teszt három néma hibamódot zár le:
 *
 * 1. **Egy kapukonfiguráció ott van a repóban, de a munkafolyamat nem hívja
 *    meg.** Az elvárást a gyökérben TÉNYLEGESEN jelen lévő konfigurációs
 *    fájlokból vezetjük le, nem másolt listából — így egy új kapu bevezetése
 *    addig pirosat ad, amíg a CI nem futtatja.
 * 2. **A commitolt manifestbe `path` repository kerül.** Mérve: ettől egy
 *    olyan klónban, ami mellett nem áll ott a `../foxpost`, a `composer
 *    update` EL SEM INDUL — a CI és minden külső közreműködő azonnal elakad.
 * 3. **A mátrix elcsúszik a deklarált támogatástól.** A `composer.json` PHP
 *    alsó határát és a Sylius-constraintet a CI-nak mérnie kell; ha a floor
 *    feljebb megy, de a mátrix marad, a csomag olyan verzión bizonyított,
 *    amit már nem is támogat.
 *
 * A teszt tudatos korlátja: a felismert kapu-fájlnevek listája véges
 * (`GATES`). Egy ismeretlen nevű, negyedik eszköz bevezetése nem vált
 * pirosra — ezt a listát akkor bővíteni kell. A `GATES` így a csomag
 * eszközkészletének szándéknyilatkozata, nem automatikus felfedezés.
 */
final class ContinuousIntegrationContractTest extends TestCase
{
    private const string WORKFLOW = '.github/workflows/ci.yaml';

    private const string JOB = 'checks';

    /**
     * Kapukonfiguráció → az a parancsrészlet, aminek a munkafolyamatban
     * meg kell jelennie, ha a konfiguráció létezik.
     *
     * @var array<string, string>
     */
    private const array GATES = [
        'phpunit.xml.dist' => 'vendor/bin/phpunit',
        'phpstan.dist.neon' => 'vendor/bin/phpstan',
        'ecs.php' => 'vendor/bin/ecs',
    ];

    public function testTheWorkflowFileExists(): void
    {
        self::assertFileExists(
            self::path(self::WORKFLOW),
            'A csomagnak nincs CI-munkafolyamata. Enélkül a három kapukonfiguráció és a ' .
            '168 teszt csak díszlet: egy PR-on semmi nem fut.',
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function gateProvider(): iterable
    {
        foreach (self::GATES as $config => $command) {
            if (!file_exists(self::path($config))) {
                continue;
            }

            yield $config => [$config, $command];
        }
    }

    #[DataProvider('gateProvider')]
    public function testEveryGateTheRepositoryShipsIsInvokedByTheWorkflow(string $config, string $command): void
    {
        self::assertStringContainsString(
            $command,
            self::workflowSource(),
            \sprintf(
                'A `%s` ott van a repó gyökerében, de a `%s` nem hívja meg a `%s`-t. ' .
                'Ez a néma hibamód: a kapu létezik, a CI zöld, és senki nem méri.',
                $config,
                self::WORKFLOW,
                $command,
            ),
        );
    }

    /**
     * A `push` és a `pull_request` trigger együtt kell: `push` nélkül a `main`
     * állapotáról nincs mérés, `pull_request` nélkül pedig egy KÜLSŐ
     * közreműködő forkjáról indított PR-on nem fut semmi — pontosan az a
     * hiányosság, amit a #196 mért.
     */
    public function testTheWorkflowRunsOnBothPushAndPullRequest(): void
    {
        $source = self::workflowSource();

        self::assertMatchesRegularExpression('/^\s{4}push:/m', $source, 'Hiányzik a `push` trigger.');
        self::assertMatchesRegularExpression('/^\s{4}pull_request:/m', $source, 'Hiányzik a `pull_request` trigger.');
    }

    /**
     * A commitolt manifest nem tartalmazhat `path` repositoryt.
     *
     * A #196 első mérése pont ezen bukott el: a `../foxpost` path repository
     * mellett a `composer update` egy szomszéd nélküli klónban nem figyelmeztet,
     * hanem KIVÉTELT dob (`PathRepository.php line 163`: „The `url` supplied for
     * the path (../foxpost) repository does not exist"), tehát a CI-nak nincs
     * első lépése sem. A magcsomag a Packagistról, `dev-main`-en jön; a helyi
     * élő összekötés módja a README „A magcsomag élő összekötése" szakaszában
     * van leírva, és nem commitolandó.
     */
    public function testTheManifestDeclaresNoPathRepository(): void
    {
        $repositories = self::composerJson()['repositories'] ?? [];
        self::assertIsIterable($repositories);

        foreach ($repositories as $repository) {
            if (!\is_array($repository)) {
                continue;
            }

            self::assertNotSame(
                'path',
                $repository['type'] ?? null,
                \sprintf(
                    'A commitolt `composer.json` egy `path` repositoryt tartalmaz (`%s`). ' .
                    'Ettől minden olyan klónban, ami mellett ez a könyvtár nem áll ott, a ' .
                    '`composer update` kivétellel leáll — a CI és minden külső közreműködő ' .
                    'azonnal elakad. A helyi összekötés `composer config repositories.* path` ' .
                    'paranccsal történik, és nem kerül commitba.',
                    \is_string($repository['url'] ?? null) ? $repository['url'] : '?',
                ),
            );
        }
    }

    /**
     * A mátrixnak le kell fednie a `composer.json`-ban DEKLARÁLT PHP alsó
     * határt — különben a legalacsonyabb támogatott PHP-n semmi nem fut.
     */
    public function testThePhpMatrixCoversTheDeclaredMinimumPhpVersion(): void
    {
        $require = self::composerJson()['require'] ?? null;
        $constraint = \is_array($require) ? ($require['php'] ?? null) : null;
        self::assertIsString($constraint, 'A `composer.json` nem deklarál `require.php` constraintet.');

        if (preg_match('/(\d+\.\d+)/', $constraint, $matches) !== 1) {
            self::fail(\sprintf('A `require.php` constraintből (`%s`) nem olvasható ki a minimális PHP verzió.', $constraint));
        }

        $versions = array_column(self::matrixEntries(), 'php');

        self::assertContains(
            $matches[1],
            $versions,
            \sprintf(
                'A `composer.json` `php: %s`-t deklarál, de a CI mátrixa csak a(z) %s verziót méri. ' .
                'A legalacsonyabb támogatott PHP-n így semmi nem fut.',
                $constraint,
                implode(', ', $versions),
            ),
        );
    }

    /**
     * Minden mátrix-bejegyzés megad egy Sylius-sort, és a telepítő lépés át is
     * adja a composernek.
     *
     * A `sylius/sylius: ^2.1` constraint a 2.1-től a 2.3-ig mindent megenged,
     * és a feloldás nem mindegy: mérve a 2.3-as sor Symfony 8-at és DBAL 4-et
     * húz, ahol a plugin nem fordul (#205). Ha a mátrix NEM pinelné a sort, a
     * CI azt mérné, amit a Packagist épp ad — vagyis a zöld/piros státusz a
     * csomagtól független okból változna.
     */
    public function testEveryMatrixEntryPinsTheSyliusLineAndTheInstallStepPassesItOn(): void
    {
        foreach (self::matrixEntries() as $index => $entry) {
            self::assertNotSame(
                '',
                $entry['sylius'],
                \sprintf('A mátrix %d. bejegyzése nem ad meg Sylius-sort.', $index),
            );
        }

        self::assertStringContainsString(
            '--with sylius/sylius:${{ matrix.sylius }}',
            self::workflowSource(),
            'A telepítő lépés nem adja át a mátrix Sylius-sorát a composernek, tehát a ' .
            'pinelés nem érvényesül: a CI azt méri, amit a Packagist épp feloldott.',
        );
    }

    /**
     * A build-badge nem dekoráció: ez az egyetlen hely, ahol a csomag
     * nyitólapjára ránéző ember látja, hogy a `main` zöld-e. Ha a
     * munkafolyamat fájlneve változik, a badge némán örökre „no status"
     * marad — ezért a badge URL-jét a munkafolyamat ÚTVONALÁHOZ mérjük.
     */
    public function testTheReadmeCarriesTheBadgeOfThisWorkflow(): void
    {
        self::assertStringContainsString(
            'actions/workflows/' . basename(self::WORKFLOW) . '/badge.svg',
            self::read('README.md'),
            'A README-ben nincs a CI-munkafolyamathoz tartozó build-badge.',
        );
    }

    /** @return list<array{php: string, sylius: string}> */
    private static function matrixEntries(): array
    {
        $parsed = Yaml::parseFile(self::path(self::WORKFLOW));
        \assert(\is_array($parsed));

        $jobs = $parsed['jobs'] ?? null;
        \assert(\is_array($jobs));

        $job = $jobs[self::JOB] ?? null;
        self::assertIsArray($job, \sprintf('A munkafolyamatban nincs `%s` job.', self::JOB));

        $strategy = $job['strategy'] ?? null;
        \assert(\is_array($strategy));

        $matrix = $strategy['matrix'] ?? null;
        \assert(\is_array($matrix));

        $include = $matrix['include'] ?? null;
        self::assertIsArray($include, 'A mátrixnak `include` listával kell megadnia a PHP/Sylius párokat.');
        self::assertNotEmpty($include, 'A mátrix `include` listája üres.');

        $entries = [];

        foreach ($include as $entry) {
            self::assertIsArray($entry);

            $php = $entry['php'] ?? null;
            $sylius = $entry['sylius'] ?? null;
            self::assertIsString($php, 'A mátrix egy bejegyzése nem ad meg `php` kulcsot.');
            self::assertIsString($sylius, 'A mátrix egy bejegyzése nem ad meg `sylius` kulcsot.');

            $entries[] = ['php' => $php, 'sylius' => $sylius];
        }

        return $entries;
    }

    private static function workflowSource(): string
    {
        return self::read(self::WORKFLOW);
    }

    /** @return array<mixed> */
    private static function composerJson(): array
    {
        $decoded = json_decode(self::read('composer.json'), true, 512, \JSON_THROW_ON_ERROR);
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
