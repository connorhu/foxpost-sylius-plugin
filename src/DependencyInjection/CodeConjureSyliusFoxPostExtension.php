<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\Yaml\Yaml;

final class CodeConjureSyliusFoxPostExtension extends Extension implements PrependExtensionInterface
{
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
    }
}
