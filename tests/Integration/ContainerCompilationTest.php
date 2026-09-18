<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Integration;

use CodeConjure\SyliusFoxPostPlugin\DependencyInjection\CodeConjureSyliusFoxPostExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

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
}
