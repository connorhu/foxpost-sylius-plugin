<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Support;

use CodeConjure\SyliusFoxPostPlugin\Controller\FoxPostLabelController;
use CodeConjure\SyliusFoxPostPlugin\Controller\FoxPostParcelController;
use Sylius\Bundle\ResourceBundle\Routing\ResourceLoader;
use Sylius\Bundle\ResourceBundle\Routing\RouteFactory;
use Sylius\Resource\Metadata\Metadata;
use Sylius\Resource\Metadata\MetadataInterface;
use Sylius\Resource\Metadata\RegistryInterface;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;

/**
 * A plugin HÉT admin route-ja, a plugin SAJÁT konfigurációjából felépítve —
 * Symfony és Sylius valódi betöltőivel, nem kézzel felsorolva.
 *
 * Miért nem egy lista: a #198 pont azt a néma hibamódot zárja le, hogy egy
 * admin sablon olyan route-ra hivatkozik, ami nem létezik (a #194-ben a hat
 * controller-route tényleg nem létezett, és a csomaglista első kattintásnál
 * `RouteNotFoundException`-nel állt le). Egy bedrótozott route-lista ellen
 * mérve ez a hiba nem bukik ki: a lista és a sablon együtt csúszna el. Ezért
 * a hat controller-route a `#[Route]` attribútumokból jön
 * (`AttributeRouteControllerLoader`, ugyanaz, amit a FrameworkBundle használ),
 * a `_index` pedig a `routes.yaml` resource-blokkjából, a Sylius saját
 * `ResourceLoader`-ével és a `sylius_resource.yaml`-ból képzett valódi
 * `Metadata`-val.
 *
 * Két tudatos korlát:
 *
 *  1. A resource-route a hostban a `/%sylius_admin.path_name%` prefixet is
 *     megkapja, itt nem: azt a prefixet az IMPORTÁLÓ (host) oldal adja, nem a
 *     plugin. Ezért ebben a gyűjteményben a `_index` útvonala prefix nélküli,
 *     és a tesztek a route NEVÉRE és a PARAMÉTEREIRE állítanak, nem az
 *     abszolút útvonalra.
 *  2. Hogy a hét route a befogadó boltban is tényleg regisztrálódik, azt
 *     plugin-oldalról nem lehet mérni — azt a bolt
 *     `FoxPostPluginWiringTest::testEveryPluginAdminRouteIsRegistered()`-je
 *     méri valódi kernellel (#194).
 */
final class PluginRoutes
{
    private const string RESOURCE_ROUTE_BLOCK = 'app_admin_foxpost_parcel';

    /** @var list<class-string> */
    private const array CONTROLLERS = [
        FoxPostParcelController::class,
        FoxPostLabelController::class,
    ];

    public static function collection(): RouteCollection
    {
        $collection = new RouteCollection();

        $loader = new AttributeRouteControllerLoader();
        foreach (self::CONTROLLERS as $controller) {
            $collection->addCollection($loader->load($controller));
        }

        $collection->addCollection(self::resourceRoutes());

        return $collection;
    }

    public static function generator(): UrlGeneratorInterface
    {
        return new UrlGenerator(self::collection(), new RequestContext());
    }

    /**
     * A `routes.yaml` resource-blokkja a Sylius saját betöltőjén át — a
     * `app_admin_foxpost_parcel_index` NEVÉT így nem a teszt mondja meg,
     * hanem a Sylius `ResourceLoader::getRouteName()`-je az alias és a
     * `section` alapján (`app` + `admin_` + `foxpost_parcel` + `_index`).
     */
    private static function resourceRoutes(): RouteCollection
    {
        $block = Yaml::at('src/Resources/config/routes.yaml', [self::RESOURCE_ROUTE_BLOCK]);

        $resource = $block['resource'] ?? null;
        \assert(\is_string($resource));

        $loader = new ResourceLoader(self::resourceRegistry(), new RouteFactory(), null, false);

        return $loader->load($resource, 'sylius.resource');
    }

    private static function resourceRegistry(): RegistryInterface
    {
        $resources = Yaml::at('src/Resources/config/app/sylius_resource.yaml', ['sylius_resource', 'resources']);

        $alias = array_key_first($resources);
        \assert(\is_string($alias));

        $parameters = $resources[$alias];
        \assert(\is_array($parameters));

        // A `driver` a Sylius `Metadata` kötelező paramétere; a plugin
        // `sylius_resource.yaml`-ja nem írja ki, mert a host alapértelmezését
        // (doctrine/orm) használja — a route képzésében nem játszik szerepet.
        $metadata = Metadata::fromAliasAndConfiguration($alias, $parameters + ['driver' => 'doctrine/orm']);

        return new class($metadata) implements RegistryInterface {
            public function __construct(private readonly MetadataInterface $metadata)
            {
            }

            /** @return list<MetadataInterface> */
            public function getAll(): array
            {
                return [$this->metadata];
            }

            public function get(string $alias): MetadataInterface
            {
                return $this->metadata;
            }

            public function getByClass(string $class): MetadataInterface
            {
                return $this->metadata;
            }

            public function add(MetadataInterface $metadata): void
            {
            }

            /** @param array<string, mixed> $configuration */
            public function addFromAliasAndConfiguration(string $alias, array $configuration): void
            {
            }
        };
    }
}
