<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Integration;

use CodeConjure\SyliusFoxPostPlugin\Command\FoxPostTrackingSyncCommand;
use CodeConjure\SyliusFoxPostPlugin\DependencyInjection\CodeConjureSyliusFoxPostExtension;
use Doctrine\ORM\EntityManagerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * A `codeconjure/simplepay-sylius-plugin` mintája alapján (lásd ott
 * `tests/Integration/ContainerCompilationTest.php`), de más hibaosztályra:
 * itt nem a plugin SAJÁT szolgáltatásainak fordíthatóságát mérjük (arra a
 * `ServiceDefinitionTest` néz rá), hanem azt, hogy a `DependencyInjection\
 * CodeConjureSyliusFoxPostExtension` MAGA nem sérti meg a Symfony extension-
 * szerződést.
 *
 * A plugin öt saját YAML-ja (framework/workflows, sylius_resource,
 * sylius_grid, twig, sylius_twig_hooks) más-más HOST extension gyökér-
 * kulcsával kezdődik. Ha ezeket a plugin saját `load()`-ja tölti be
 * `YamlFileLoader`-rel, a konténer SOSEM fordul le — a `load()` egy
 * extension-mentes `ContainerBuilder`-t kap (ezt hívja a
 * `MergeExtensionConfigurationPass`), és a betöltő "There is no extension
 * able to load the configuration for ..."-tal bukik, mert a gyökérkulcs
 * (pl. "framework") nem regisztrált extension ebben a konténerben — az csak
 * a HOST alkalmazásban (Sylius) létezik.
 *
 * A helyes hely a `PrependExtensionInterface::prepend()`, ami
 * `prependExtensionConfig()`-gal adja át a konfigurációt a HOST megfelelő
 * extensionjének — ez nem igényli, hogy a jelen extension konténere ismerje
 * azokat, csak hogy a VÉGSŐ (host) konténerben létezzenek, amikor a
 * `MergeExtensionConfigurationPass` ténylegesen összefésüli őket.
 *
 * Ez a teszt szándékosan NEM épít teljes Sylius/Symfony kernelt (a plugin
 * nem szállít teszt-kernelt, lásd a README-t) — egy csupasz
 * `ContainerBuilder`-en méri a szerződés két felét.
 */
final class ContainerCompilationTest extends TestCase
{
    /**
     * A prepend() ide teszi a plugin YAML-jait — az öt gyökérkulcs pontosan
     * ennyi (és nem több/kevesebb) HOST extension aliasát célozza meg.
     *
     * @var list<string>
     */
    private const array EXPECTED_PREPENDED_ALIASES = [
        'framework',
        'sylius_resource',
        'sylius_grid',
        'twig',
        'sylius_twig_hooks',
    ];

    /**
     * A hiba 1 pontos regressziós tesztje: ha a `load()` YAML-lal próbálja
     * betölteni más extension konfigurációját, ez EGY CSUPASZ
     * `ContainerBuilder`-en (semmilyen más extension nincs regisztrálva)
     * kivétellel bukik. A javított kód `load()`-ja csak a `services.xml`-t
     * tölti be — ahhoz nem kell más extension.
     */
    public function testLoadDoesNotThrowOnABareContainerWithNoOtherExtensionsRegistered(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        (new CodeConjureSyliusFoxPostExtension())->load([], $container);

        // A load() a services.xml-t is betölti — ha idáig eljutottunk kivétel
        // nélkül, legalább egy plugin szolgáltatás definíciónak léteznie kell.
        self::assertNotSame(
            [],
            array_filter(
                array_keys($container->getDefinitions()),
                static fn (string $id): bool => str_starts_with($id, 'CodeConjure\\SyliusFoxPostPlugin\\'),
            ),
            'A load() a services.xml-t sem töltötte be.',
        );
    }

    /**
     * A hiba 1 másik fele: a `prepend()` mind az öt YAML-t a HELYES host
     * extension aliashoz rendeli — nem többhöz, nem kevesebbhez, és nem a
     * sajátjához.
     */
    public function testPrependAddsConfigurationToExactlyTheExpectedHostExtensionAliases(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        // Szintetikus extension-stubok az öt alias-ra — a valódi Sylius/
        // Symfony alkalmazásban ezek a FrameworkBundle, a SyliusResource-,
        // Grid- és TwigHooksBundle, illetve a TwigBundle saját extensionjei.
        // A `prependExtensionConfig()` ugyan enélkül is működne (a
        // ContainerBuilder saját belső tömbjébe teszi, az extension létét
        // csak a tényleges `compile()` ellenőrzi), de a stubok regisztrálása
        // közelebb visz a valódi host-környezethez.
        foreach (self::EXPECTED_PREPENDED_ALIASES as $alias) {
            $container->registerExtension(new class($alias) extends \Symfony\Component\DependencyInjection\Extension\Extension {
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

        (new CodeConjureSyliusFoxPostExtension())->prepend($container);

        foreach (self::EXPECTED_PREPENDED_ALIASES as $alias) {
            $config = $container->getExtensionConfig($alias);

            self::assertNotSame(
                [],
                $config,
                sprintf('A "%s" extension alias nem kapott konfigurációt a prepend()-től.', $alias),
            );
            self::assertNotSame(
                [[]],
                $config,
                sprintf('A "%s" extension alias üres tömböt kapott a prepend()-től.', $alias),
            );
        }
    }

    /**
     * C4 pontos regressziós tesztje: a `Client` és a mögötte álló
     * `codeconjure.foxpost.environment` enum-szolgáltatás ténylegesen
     * PÉLDÁNYOSÍTHATÓ, valós FOXPOST_* env-változókkal, egy a Symfony
     * `Kernel::dumpContainer()`-ével AZONOS opciókkal (`as_files: true`)
     * dumpolt konténeren keresztül — nem csak a definíció létét nézzük, a
     * kódnak ténylegesen le is kell futnia.
     *
     * ŐSZINTE MEGJEGYZÉS (lásd a task-11-report.md „C4" szakaszát): ez a
     * teszt a JAVÍTÁS ELŐTTI kóddal (`<argument>%env(bool:FOXPOST_SANDBOX)%
     * </argument>` közvetlenül a factory-hívásban) is ZÖLD volt minden
     * saját mérésemben — a bolt valódi KernelTestCase-ében látott
     * `ParameterNotFoundException`-t a plugin-only sandboxban (Sylius
     * teszt-kernel nélkül, ahogy a README is előírja) nem tudtam
     * előidézni, feltehetően mert a hiba a bolt teljes, ~50 bundle-ös
     * konténergráfjának egy olyan részletétől függ, amit itt nem lehet
     * hitelesen reprodukálni. A teszt így nem „piros előtte, zöld utána"
     * bizonyíték erre a KONKRÉT mechanizmusra — hanem egy általános
     * védőháló, ami MOSTANTÓL örökre biztosítja, hogy a Client és az
     * Environment ténylegesen példányosítható marad egy realisztikus,
     * dumpolt konténerben. A javítás maga (lásd services.xml) egy
     * bizonyítottan törékeny PhpDumper-kódutat (a "teljes string %env(...)%
     * mint egyetlen argumentum" mintát, ami dumpParameter()-en és
     * hasParameter()-en át futásidejű getParameter()-hívást generálhat, ha
     * a placeholder dump-időben bármi okból nem regisztrálódik) egy
     * bevált, explicit <parameter>-indirekcióra cseréli — ugyanarra a
     * mintára, amit a codeconjure.foxpost.city_lookup_route már
     * bizonyítottan helyesen használ ugyanebben a fájlban.
     */
    public function testClientAndEnvironmentServicesAreInstantiableWithRealEnvironmentVariables(): void
    {
        $env = ['FOXPOST_USERNAME' => 'teszt-felhasznalo', 'FOXPOST_PASSWORD' => 'teszt-jelszo', 'FOXPOST_API_KEY' => 'teszt-kulcs', 'FOXPOST_SANDBOX' => 'true'];
        foreach ($env as $key => $value) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        try {
            $container = $this->fullyWiredContainer();
            $container->getDefinition('CodeConjure\FoxPost\Client')->setPublic(true);
            $container->getDefinition('codeconjure.foxpost.environment')->setPublic(true);

            $container->compile();

            $containerClass = $this->dumpAsFiles($container, 'FoxpostC4Container');

            /** @var \Symfony\Component\DependencyInjection\Container $dumped */
            $dumped = new $containerClass();

            $environment = $dumped->get('codeconjure.foxpost.environment');
            self::assertInstanceOf(\CodeConjure\FoxPost\Environment::class, $environment);
            self::assertSame(\CodeConjure\FoxPost\Environment::Sandbox, $environment, 'A FOXPOST_SANDBOX=true nem Sandbox-ra oldódott fel.');

            $client = $dumped->get('CodeConjure\FoxPost\Client');
            self::assertInstanceOf(\CodeConjure\FoxPost\Client::class, $client);
        } finally {
            foreach (array_keys($env) as $key) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            }
        }
    }

    /**
     * C3 regressziós tesztje: a FoxPostTrackingSyncCommand — ami a
     * FoxPostTrackingSyncService::syncActive()-ot hívja — ténylegesen
     * `console.command` taget kap (a Symfony `#[AsCommand]`/`Command`
     * autoconfigurációja, amit minden Symfony-app maga a FrameworkExtension
     * regisztrál — itt ugyanazt a két hívást reprodukáljuk, nem találjuk ki
     * újra), és a konténer TÉNYLEGESEN LEFORDUL vele (ez a wiring — a
     * `lint:container` ugyanezt méri).
     *
     * A teljes futásidejű PÉLDÁNYOSÍTÁST (a `FoxPostTrackingSyncCommand` ->
     * `FoxPostTrackingSyncService` -> `FoxpostParcelRepository` láncon
     * keresztül) szándékosan NEM méri: a `FoxpostParcelRepository` a
     * Doctrine `ServiceEntityRepository`-t terjeszti ki, aminek a
     * konstruktora a `ManagerRegistry`-n keresztül VALÓDI `EntityManager`-t
     * és osztály-metaadatot vár — ennek hiteles szintetizálása Sylius
     * teszt-kernel nélkül aránytalan volna egy „megjelenik-e a
     * bin/console list-ben" kérdéshez képest, amit a tag jelenléte már
     * eldönt. A futásidejű példányosítást (amikor a parancs ténylegesen
     * FUT) a coordinator a bolt oldalán ellenőrzi, ahogy jelezte.
     */
    public function testTheTrackingSyncCommandIsRegisteredAsAConsoleCommand(): void
    {
        $env = ['FOXPOST_USERNAME' => 'teszt-felhasznalo', 'FOXPOST_PASSWORD' => 'teszt-jelszo', 'FOXPOST_API_KEY' => 'teszt-kulcs', 'FOXPOST_SANDBOX' => 'true'];
        foreach ($env as $key => $value) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        try {
            $this->assertTrackingSyncCommandIsRegistered();
        } finally {
            foreach (array_keys($env) as $key) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            }
        }
    }

    private function assertTrackingSyncCommandIsRegistered(): void
    {
        $container = $this->fullyWiredContainer();

        // A FrameworkExtension::load() két sora, szó szerint — ez teszi
        // fel a console.command taget minden Symfony-appban, a miénkben is
        // csak ezt a mechanizmust reprodukáljuk, nem helyettesítjük.
        $container->registerAttributeForAutoconfiguration(AsCommand::class, static function (ChildDefinition $definition, AsCommand $attribute): void {
            $definition->addTag('console.command', [
                'command' => $attribute->name,
                'description' => $attribute->description,
            ]);
        });
        $container->registerForAutoconfiguration(Command::class)->addTag('console.command');

        $commandId = FoxPostTrackingSyncCommand::class;
        $container->getDefinition($commandId)->setPublic(true);

        $container->compile();

        self::assertTrue(
            $container->getDefinition($commandId)->hasTag('console.command'),
            'A FoxPostTrackingSyncCommand nem kapott console.command taget — nem jelenne meg a bin/console list-ben.',
        );

        $tag = $container->getDefinition($commandId)->getTag('console.command');
        self::assertSame('foxpost:tracking:sync', $tag[0]['command'] ?? null, 'A parancs neve nem foxpost:tracking:sync — ez a szerződés része, nem szabadna változnia.');
    }

    /**
     * A plugin teljes szolgáltatás-gráfjához szükséges KÜLSŐ (host)
     * szolgáltatások szintetikus regisztrálása — ugyanaz a minta, mint a
     * `codeconjure/simplepay-sylius-plugin` `ContainerCompilationTest`-jéé,
     * de ennek a pluginnak a saját (bővebb) függőséglistájára szabva.
     */
    private function fullyWiredContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        $container->register('service_container', ContainerBuilder::class)->setSynthetic(true)->setPublic(true);
        $container->setAlias(ContainerInterface::class, 'service_container')->setPublic(true);

        // Doctrine repository — explicit id-vel wire-elve, a típusnak nem
        // kell autoload-olhatónak lennie.
        $container->register('doctrine', \stdClass::class)->setPublic(true);

        $container->register('doctrine.orm.entity_manager', EntityManagerInterface::class)->setSynthetic(true)->setPublic(true);
        $container->setAlias(EntityManagerInterface::class, 'doctrine.orm.entity_manager')->setPublic(true);

        $container->register('router', RouterInterface::class)->setSynthetic(true)->setPublic(true);
        $container->setAlias(RouterInterface::class, 'router')->setPublic(true);

        $container->register(ChannelContextInterface::class, ChannelContextInterface::class)->setSynthetic(true)->setPublic(true);
        $container->register(CacheInterface::class, CacheInterface::class)->setSynthetic(true)->setPublic(true);

        // #[Autowire(service: 'monolog.logger.foxpost')]
        $container->register('monolog.logger.foxpost', NullLogger::class)->setPublic(true);

        // #[Target('foxpostParcelStateMachine')] WorkflowInterface — a
        // Symfony névvel-célzott autowiring aliasa: "<Típus> $<név>".
        $container->register('foxpostParcelStateMachine', WorkflowInterface::class)->setSynthetic(true)->setPublic(true);
        $container->setAlias(WorkflowInterface::class . ' $foxpostParcelStateMachine', 'foxpostParcelStateMachine')->setPublic(true);

        $container->register('nyholm.psr7.psr17_factory', Psr17Factory::class)->setPublic(true);

        (new CodeConjureSyliusFoxPostExtension())->load([], $container);

        foreach (array_keys($container->getDefinitions()) as $id) {
            if (str_starts_with($id, 'CodeConjure\\')) {
                $container->getDefinition($id)->setPublic(true);
            }
        }

        return $container;
    }

    /**
     * A `Symfony\Component\HttpKernel\Kernel::dumpContainer()`-rel AZONOS
     * opciókkal dumpol (`as_files: true`) — ez a releváns különbség egy
     * egyszerű, egyfájlos `PhpDumper::dump()`-hoz képest, és pontosan az a
     * fájlszerkezet, amiben a coordinator a C4 hibát mérte
     * (`getCodeconjure_Foxpost_EnvironmentService.php`).
     */
    private function dumpAsFiles(ContainerBuilder $container, string $classPrefix): string
    {
        $dumper = new PhpDumper($container);
        $class = $classPrefix . '_' . bin2hex(random_bytes(4));

        $content = $dumper->dump([
            'class' => $class,
            'base_class' => 'Container',
            'as_files' => true,
            'debug' => false,
            'inline_factories' => false,
            'inline_class_loader' => false,
        ]);
        // 'as_files' => true esetén a dump() mindig tömböt ad vissza
        // (fájlnév => kód, plusz a gyökérosztály kódja az utolsó elemként).
        \assert(\is_array($content));

        $rootCode = array_pop($content);
        $dir = sys_get_temp_dir() . '/foxpost_container_' . bin2hex(random_bytes(4)) . '/';
        $filesystem = new Filesystem();

        foreach ($content as $filePath => $code) {
            $filesystem->dumpFile($dir . $filePath, $code);
        }

        $rootFile = $dir . $class . '.php';
        $filesystem->dumpFile($rootFile, $rootCode);
        require $rootFile;

        return $class;
    }
}
