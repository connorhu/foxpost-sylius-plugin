<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Unit;

use CodeConjure\SyliusFoxPostPlugin\DependencyInjection\CodeConjureSyliusFoxPostExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\Yaml\Yaml;

/**
 * A #194 szerződése: a plugin telepítéséhez a befogadó boltnak kilenc helyen
 * kell konfigurációt hozzátennie, és ennek a KILENCNEK a dokumentációja a
 * `docs/installation.md`.
 *
 * Ez a teszt két dolgot őriz, mindkettőt néma hibamód ellen:
 *
 * 1. **Amit a #194 a pluginba hozott, az a pluginban is maradjon** — a hat
 *    controller-route attribútum-importja és a migrációs útvonal
 *    `prepend()`-je. Ha bármelyik visszakerülne a host felelősségébe, a
 *    befogadó bolt oldalán hat route tűnne el, illetve a plugin migrációi
 *    nem látszanának — mindkettő csak futásidőben derülne ki.
 * 2. **Amit a hostnak kell megtennie, az legyen LEÍRVA.** A `docs/
 *    installation.md` nélkül a #194 egész munkája elveszik: a hibamódok némák,
 *    a második bolt pedig nem tudja, mit nem tett meg. Ezért a dokumentum
 *    minden host-oldali kapcsolódási pontot (env-változó, konténer-paraméter,
 *    hookable-név) szó szerint tartalmaz — ezeket a plugin KÓDJÁBÓL
 *    gyűjtjük, nem egy másolt listából, tehát egy új env-változó vagy
 *    hookable bevezetése pirosra vált, amíg nincs dokumentálva.
 */
final class InstallationContractTest extends TestCase
{
    private const string DOC = 'docs/installation.md';

    public function testTheControllerRoutesAreImportedByThePluginItself(): void
    {
        $routes = Yaml::parseFile(self::path('src/Resources/config/routes.yaml'));
        \assert(\is_array($routes));

        $attributeImports = array_filter(
            $routes,
            static fn (mixed $block): bool => \is_array($block) && ($block['type'] ?? null) === 'attribute',
        );

        self::assertCount(
            1,
            $attributeImports,
            'A plugin routes.yaml-jának pontosan egy `type: attribute` importot kell tartalmaznia — ' .
            'ez adja a két admin controller hat route-ját. Nélküle a befogadó boltnak kellene ' .
            'attribútum-szkennelést írnia (a #194 előtti állapot), és a hat route némán eltűnne.',
        );

        $import = reset($attributeImports);
        \assert(\is_array($import));

        self::assertSame(
            '@CodeConjureSyliusFoxPostPlugin/src/Controller/',
            $import['resource'] ?? null,
            'Az attribute-import nem a plugin Controller könyvtárára mutat.',
        );
    }

    /**
     * Minden `#[Route]`-ot hordozó osztály az attribute-importtal érintett
     * könyvtárban van — különben az import nem találja meg, és a route némán
     * nem létezik.
     */
    public function testEveryRouteCarryingClassLivesUnderTheImportedDirectory(): void
    {
        $withRoutes = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::path('src')));

        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);

            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            if (!str_contains($contents, '#[Route(')) {
                continue;
            }

            $withRoutes[] = str_replace(self::path('src') . '/', '', $file->getPathname());
        }

        self::assertNotSame([], $withRoutes, 'Egyetlen `#[Route]` sincs a src/ alatt — a mérés mellémegy.');

        foreach ($withRoutes as $relativePath) {
            self::assertStringStartsWith(
                'Controller/',
                $relativePath,
                sprintf(
                    'A %s `#[Route]`-ot hordoz, de nem a Controller/ könyvtárban van — a routes.yaml ' .
                    'attribute-importja nem találja meg, a route-ja némán nem létezne.',
                    $relativePath,
                ),
            );
        }
    }

    public function testThePluginPrependsItsOwnMigrationsPath(): void
    {
        $container = $this->containerWithHostExtensions();

        (new CodeConjureSyliusFoxPostExtension())->prepend($container);

        $paths = [];
        foreach ($container->getExtensionConfig('doctrine_migrations') as $config) {
            $paths += (array) ($config['migrations_paths'] ?? []);
        }

        self::assertSame(
            '@CodeConjureSyliusFoxPostPlugin/src/Migrations',
            $paths['CodeConjure\SyliusFoxPostPlugin\Migrations'] ?? null,
            'A plugin nem prepend-eli a saját migrációs útvonalát — a #194 előtt ezt a befogadó boltnak ' .
            'kellett a doctrine_migrations.yaml-jában felvennie, és a hiánya némán annyit jelent, ' .
            'hogy a plugin három migrációja meg sem jelenik a doctrine:migrations:list-ben.',
        );

        self::assertSame(
            '%kernel.project_dir%/migrations',
            $paths['DoctrineMigrations'] ?? null,
            'A prepend elvesztette a HOST meglévő migrations_paths bejegyzését — a trait összefésül, ' .
            'nem felülír; ha ez elromlik, a bolt SAJÁT migrációi tűnnek el.',
        );
    }

    public function testThePluginDeclaresThatItsMigrationsRunAfterTheSyliusCoreOnes(): void
    {
        $container = $this->containerWithHostExtensions();

        (new CodeConjureSyliusFoxPostExtension())->prepend($container);

        $declared = [];
        foreach ($container->getExtensionConfig('sylius_labs_doctrine_migrations_extra') as $config) {
            $declared += (array) ($config['migrations'] ?? []);
        }

        self::assertSame(
            ['Sylius\Bundle\CoreBundle\Migrations'],
            $declared['CodeConjure\SyliusFoxPostPlugin\Migrations'] ?? null,
            'A plugin migrációi `ALTER TABLE sylius_shipment`-et is kiadnak, tehát a magtábláknak már ' .
            'állnia kell. Ezt a kijelentést a host felülírhatja (l. docs/installation.md 9. lépés), de ' .
            'alapértelmezésben a pluginnak kell megadnia.',
        );
    }

    public function testTheInstallationDocumentExistsAndTheReadmeLinksIt(): void
    {
        self::assertFileExists(self::path(self::DOC));

        self::assertStringContainsString(
            '(' . self::DOC . ')',
            (string) file_get_contents(self::path('README.md')),
            'A README „Installation" szakasza nem mutat a telepítési dokumentumra — a #194 kész-feltétele.',
        );
    }

    /**
     * A plugin által kért env-változók a services.xml-ből, nem egy másolt
     * listából: egy új `%env(FOXPOST_*)%` bevezetése pirosra vált, amíg nincs
     * dokumentálva.
     */
    public function testEveryEnvironmentVariableThePluginReadsIsDocumented(): void
    {
        $servicesXml = (string) file_get_contents(self::path('src/Resources/config/services.xml'));

        preg_match_all('/%env\(([a-z:]*)(FOXPOST_[A-Z_]+)\)%/', $servicesXml, $matches);
        $variables = array_unique($matches[2]);

        self::assertNotSame([], $variables, 'Egyetlen FOXPOST_* env-változót sem találtam a services.xml-ben — a mérés mellémegy.');

        $doc = (string) file_get_contents(self::path(self::DOC));

        foreach ($variables as $variable) {
            self::assertStringContainsString(
                $variable,
                $doc,
                sprintf(
                    'A %s env-változót a plugin olvassa, de a %s nem említi — a befogadó bolt nem tudná, ' .
                    'hogy be kell állítania (EnvNotFoundException a szolgáltatás példányosításánál).',
                    $variable,
                    self::DOC,
                ),
            );
        }
    }

    /**
     * A host-oldali kapcsolódási pontok, amiket a plugin a KÓDJÁBÓL kér, és
     * amiket ezért a dokumentumnak szó szerint tartalmaznia kell.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function hostTouchpointProvider(): iterable
    {
        yield 'bundle-osztály' => ['CodeConjureSyliusFoxPostPlugin::class', 'config/bundles.php'];
        yield 'route-import' => ['@CodeConjureSyliusFoxPostPlugin/src/Resources/config/routes.yaml', 'route-import'];
        yield 'npm-csomagnév' => ['@codeconjure/foxpost-sylius-plugin', 'assets/controllers.json'];
        yield 'városkereső paraméter' => ['codeconjure.foxpost.city_lookup_route', 'config/services.yaml'];
        yield 'pénztár hookable' => ['sylius_shop.checkout.select_shipping.content.form.shipments', '_sylius.yaml'];
        yield 'admin hookable' => ['sylius_admin.order.show.content.sections.shipments.item.actions', '_sylius.yaml'];
        yield 'migrációs névtér' => ['CodeConjure\SyliusFoxPostPlugin\Migrations', 'migrations_extra'];
        yield 'checkout mező: telefon' => ['phoneNumber', 'checkout form'];
        yield 'checkout mező: automata' => ['pickupPointId', 'checkout form'];
    }

    #[DataProvider('hostTouchpointProvider')]
    public function testTheInstallationDocumentNamesEveryHostTouchpoint(string $needle, string $where): void
    {
        self::assertStringContainsString(
            $needle,
            (string) file_get_contents(self::path(self::DOC)),
            sprintf('A %s nem említi a(z) „%s" kapcsolódási pontot (%s).', self::DOC, $needle, $where),
        );
    }

    /**
     * A két hookable-név, amit a hostnak kell felülregisztrálnia, TÉNYLEGESEN
     * szerepel a plugin hooks.yaml-jában — különben a dokumentum olyan
     * lépést ír le, aminek nincs mit kiszolgálnia.
     */
    public function testTheTwoHostOverriddenHookablesExistInThePluginConfiguration(): void
    {
        $hooks = Yaml::parseFile(self::path('src/Resources/config/app/hooks.yaml'));
        \assert(\is_array($hooks));

        $root = $hooks['sylius_twig_hooks'] ?? null;
        \assert(\is_array($root));

        $defined = array_keys((array) ($root['hooks'] ?? []));

        foreach ([
            'sylius_shop.checkout.select_shipping.content.form.shipments',
            'sylius_admin.order.show.content.sections.shipments.item.actions',
        ] as $hookable) {
            self::assertContains(
                $hookable,
                $defined,
                sprintf('A docs/installation.md a(z) „%s" felülregisztrálását írja elő, de a plugin hooks.yaml-ja nem definiálja.', $hookable),
            );
        }
    }

    private function containerWithHostExtensions(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        foreach ([
            'framework',
            'sylius_resource',
            'sylius_grid',
            'twig',
            'sylius_twig_hooks',
            'doctrine_migrations',
            'sylius_labs_doctrine_migrations_extra',
        ] as $alias) {
            $container->registerExtension(new class($alias) extends Extension {
                public function __construct(private readonly string $alias)
                {
                }

                public function load(array $configs, ContainerBuilder $container): void
                {
                }

                public function getAlias(): string
                {
                    return $this->alias;
                }
            });
        }

        // A befogadó bolt saját doctrine_migrations.yaml-ja — a trait ezt
        // olvassa be és fésüli össze a sajátjával.
        $container->prependExtensionConfig('doctrine_migrations', [
            'migrations_paths' => ['DoctrineMigrations' => '%kernel.project_dir%/migrations'],
        ]);

        return $container;
    }

    private static function path(string $relative): string
    {
        return \dirname(__DIR__, 2) . '/' . $relative;
    }
}
