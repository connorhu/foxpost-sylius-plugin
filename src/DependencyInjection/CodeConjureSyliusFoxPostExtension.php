<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\DependencyInjection;

use Sylius\Bundle\CoreBundle\DependencyInjection\PrependDoctrineMigrationsTrait;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\Yaml\Yaml;

final class CodeConjureSyliusFoxPostExtension extends Extension implements PrependExtensionInterface
{
    use PrependDoctrineMigrationsTrait;

    /**
     * A plugin öt saját YAML-ja más-más extension gyökérkulcsával kezdődik
     * (framework, sylius_resource, sylius_grid, twig, sylius_twig_hooks) —
     * ezeket csak `prependExtensionConfig()`-gal lehet átadni a HOST azon
     * extensionjének, ami tényleg felelős értük. A `load()` egy
     * extension-mentes konténert kap (a MergeExtensionConfigurationPass így
     * hívja), tehát a `YamlFileLoader`-rel való betöltésük ott sosem
     * fordulna: "There is no extension able to load the configuration for
     * ...".
     *
     * @var array<string, string>
     */
    private const array APP_CONFIG_FILES = [
        'framework' => 'foxpost_parcel.yaml',
        'sylius_resource' => 'sylius_resource.yaml',
        'sylius_grid' => 'grid.yaml',
        'twig' => 'config.yaml',
        'sylius_twig_hooks' => 'hooks.yaml',
    ];

    /** @param array<mixed> $configs */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new XmlFileLoader($container, new FileLocator(\dirname(__DIR__, 2) . '/src/Resources/config'));
        $loader->load('services.xml');
    }

    public function prepend(ContainerBuilder $container): void
    {
        $configDir = \dirname(__DIR__, 2) . '/src/Resources/config/app';

        foreach (self::APP_CONFIG_FILES as $extensionAlias => $file) {
            $parsed = Yaml::parseFile($configDir . '/' . $file);
            \assert(\is_array($parsed));

            $extensionConfig = $parsed[$extensionAlias];
            \assert(\is_array($extensionConfig));

            $typedExtensionConfig = [];
            foreach ($extensionConfig as $key => $value) {
                \assert(\is_string($key));
                $typedExtensionConfig[$key] = $value;
            }

            $container->prependExtensionConfig($extensionAlias, $typedExtensionConfig);
        }

        // A plugin HÁROM migrációja (foxpost_parcel tábla + a három
        // trait-oszlop) a #194 óta a plugin saját prepend()-jéből kerül a
        // doctrine_migrations `migrations_paths`-ába — a befogadó boltnak
        // nem kell a saját config/packages/doctrine_migrations.yaml-jában
        // felvennie. A trait a Sylius sajátja
        // (Sylius\Bundle\CoreBundle\DependencyInjection), ugyanaz, amit a
        // sylius/paypal-plugin is használ; a host `migrations_paths`-át
        // beolvassa és MELLÉ teszi a sajátját, tehát nem írja felül.
        $this->prependDoctrineMigrations($container);
    }

    protected function getMigrationsNamespace(): string
    {
        return 'CodeConjure\\SyliusFoxPostPlugin\\Migrations';
    }

    protected function getMigrationsDirectory(): string
    {
        return '@CodeConjureSyliusFoxPostPlugin/src/Migrations';
    }

    /**
     * A plugin migrációi a Sylius mag migrációi UTÁN futnak: az egyik
     * `ALTER TABLE sylius_shipment`-et ad ki, tehát a magtábláknak már
     * állnia kell.
     *
     * Ennél többet a plugin nem tudhat. Egy olyan bolt, aminek a SAJÁT
     * migrációi már létrehozták a FoxPost-táblát és -oszlopokat (ilyen a
     * kiszervezés előtti egyhazzene.hu bolt), ezen felül a saját migrációs
     * névterét is fel kell vennie a lista elé — l. docs/installation.md.
     *
     * @return list<string>
     */
    protected function getNamespacesOfMigrationsExecutedBefore(): array
    {
        return ['Sylius\\Bundle\\CoreBundle\\Migrations'];
    }
}
