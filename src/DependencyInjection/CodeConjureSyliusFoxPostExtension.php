<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class CodeConjureSyliusFoxPostExtension extends Extension
{
    /** @param array<mixed> $configs */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new XmlFileLoader($container, new FileLocator(\dirname(__DIR__, 2) . '/src/Resources/config'));
        $loader->load('services.xml');

        $yamlLoader = new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 2) . '/src/Resources/config/app'));
        $yamlLoader->load('foxpost_parcel.yaml');
    }
}
