<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Support;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Sylius\Bundle\ResourceBundle\Routing\ResourceLoader;
use Sylius\Bundle\ResourceBundle\Routing\RouteFactory;
use Sylius\Resource\Metadata\Metadata;
use Sylius\Resource\Metadata\MetadataInterface;
use Sylius\Resource\Metadata\RegistryInterface;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\Config\FileLocatorInterface;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Loader\AttributeDirectoryLoader;
use Symfony\Component\Routing\Loader\AttributeFileLoader;
use Symfony\Component\Routing\Loader\YamlFileLoader;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;

/**
 * A plugin HÉT admin route-ja, a plugin SAJÁT `routes.yaml`-jából felépítve —
 * ugyanazzal a betöltő-lánccal és ugyanazzal a routerrel, amit a befogadó bolt
 * használ.
 *
 * Miért nem egy lista: a #198 pont azt a néma hibamódot zárja le, hogy egy
 * admin sablon olyan route-ra hivatkozik, ami nem létezik (a #194-ben a hat
 * controller-route tényleg nem létezett, és a csomaglista első kattintásnál
 * `RouteNotFoundException`-nel állt le). Egy bedrótozott route-lista ellen
 * mérve ez a hiba nem bukik ki: a lista és a sablon együtt csúszna el.
 *
 * Miért a `routes.yaml` a belépési pont, és nem a két loader külön (#209): az
 * admin előtag az IMPORTOKON van (`/%sylius_admin.path_name%`), nem a
 * controllerek attribútumain. Ha a teszt a `AttributeRouteControllerLoader`-t
 * közvetlenül hívná, a prefix sosem kerülne rá — vagyis pont az a szerződés
 * esne ki a mérésből, amiért a #209 létezik. Így viszont a lánc valódi:
 *
 *  - `YamlFileLoader` olvassa a `routes.yaml` két importját és adja a prefixet;
 *  - `ResourceLoader` (Sylius) képzi a `_index` route-ot és a NEVÉT
 *    (`ResourceLoader::getRouteName()`: `app` + `admin_` + `foxpost_parcel` +
 *    `_index`), a `sylius_resource.yaml`-ból képzett valódi `Metadata`-val;
 *  - `AttributeDirectoryLoader` + `AttributeRouteControllerLoader` olvassa a
 *    két controller `#[Route]` attribútumait (ugyanaz, amit a FrameworkBundle
 *    használ);
 *  - a `%sylius_admin.path_name%` feloldása a FrameworkBundle
 *    `Router::resolveParameters()`-ében történik, nem a tesztben.
 *
 * Tudatos korlát: hogy a hét route a befogadó boltban is tényleg
 * regisztrálódik — és hogy a bolt a sajátján kívül nem importálja-e MÉG egyszer
 * a controllereket, prefix nélkül —, azt plugin-oldalról nem lehet mérni; azt a
 * bolt `FoxPostPluginWiringTest`-je méri valódi kernellel (#194, #209).
 */
final class PluginRoutes
{
    /**
     * A Sylius alapértelmezett admin-útvonala
     * (`env(SYLIUS_ADMIN_ROUTING_PATH_NAME): admin`). A tesztek ezzel futnak,
     * hogy az elvárt útvonalak olvashatók maradjanak — a #209 őrei viszont
     * másik értéket adnak át.
     */
    public const string ADMIN_PATH_NAME = 'admin';

    private const string BUNDLE_ALIAS = '@CodeConjureSyliusFoxPostPlugin';

    private const string ROUTES_FILE = self::BUNDLE_ALIAS . '/src/Resources/config/routes.yaml';

    private const string RESOURCE_ROUTE_BLOCK = 'app_admin_foxpost_parcel';

    public static function collection(string $adminPathName = self::ADMIN_PATH_NAME): RouteCollection
    {
        return self::router($adminPathName)->getRouteCollection();
    }

    public static function generator(string $adminPathName = self::ADMIN_PATH_NAME): UrlGeneratorInterface
    {
        return self::router($adminPathName)->getGenerator();
    }

    private static function router(string $adminPathName): Router
    {
        return new Router(
            self::serviceContainer(),
            self::ROUTES_FILE,
            [],
            new RequestContext(),
            self::parameterContainer(['sylius_admin.path_name' => $adminPathName]),
        );
    }

    /**
     * A router EGYETLEN szolgáltatást kér el (`routing.loader`), l.
     * `Router::getSubscribedServices()`. Bármi más kérése a harness hibája —
     * ezért dob.
     */
    private static function serviceContainer(): ContainerInterface
    {
        $locator = self::bundleLocator();
        $attributeClassLoader = new AttributeRouteControllerLoader();

        $resolver = new LoaderResolver([
            new YamlFileLoader($locator),
            new AttributeDirectoryLoader($locator, $attributeClassLoader),
            new AttributeFileLoader($locator, $attributeClassLoader),
            // A 4. argumentum a `sylius.routing_path_bc_layer` paraméter: ez
            // dönt az index route záró perjeléről. `null`-lal a Sylius saját
            // alapértelmezése marad érvényben (`?? true`), ami a boltban is
            // érvényes (mérve: a paraméter `true`, és a route
            // `/admin/foxpost-parcels/`). `false`-szal a harness útvonalai
            // némán elcsúsznának a hostétól.
            new ResourceLoader(self::resourceRegistry(), new RouteFactory(), null, null),
        ]);

        return self::container(['routing.loader' => new DelegatingLoader($resolver)]);
    }

    /**
     * A kernel `@BundleName/...` feloldása kernel nélkül: a plugin bundle
     * gyökere a repó gyökere (l. `CodeConjureSyliusFoxPostPlugin::getPath()`).
     */
    private static function bundleLocator(): FileLocatorInterface
    {
        $root = \dirname(__DIR__, 2);

        return new class($root) implements FileLocatorInterface {
            public function __construct(private readonly string $root)
            {
            }

            public function locate(string $name, ?string $currentPath = null, bool $first = true): string|array
            {
                if (str_starts_with($name, PluginRoutes::bundleAlias())) {
                    $name = $this->root . substr($name, \strlen(PluginRoutes::bundleAlias()));
                }

                if (!file_exists($name) && null !== $currentPath) {
                    $name = $currentPath . '/' . $name;
                }

                return $first ? $name : [$name];
            }
        };
    }

    public static function bundleAlias(): string
    {
        return self::BUNDLE_ALIAS;
    }

    /** @param array<string, string> $parameters */
    private static function parameterContainer(array $parameters): ContainerInterface
    {
        return new class($parameters) implements ContainerInterface {
            /** @param array<string, string> $parameters */
            public function __construct(private readonly array $parameters)
            {
            }

            public function get(string $id): string
            {
                // A `Router::getRouteCollection()` a `kernel.build_dir`-t is
                // elkéri, és KIFEJEZETTEN a `ParameterNotFoundException`-t
                // kapja el (Router.php:86) — más kivétellel a teszt elhasalna.
                if (!isset($this->parameters[$id])) {
                    throw new ParameterNotFoundException($id);
                }

                return $this->parameters[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->parameters[$id]);
            }
        };
    }

    /** @param array<string, object> $services */
    private static function container(array $services): ContainerInterface
    {
        return new class($services) implements ContainerInterface {
            /** @param array<string, object> $services */
            public function __construct(private readonly array $services)
            {
            }

            public function get(string $id): object
            {
                return $this->services[$id] ?? throw new class(\sprintf('A harness nem ad "%s" szolgáltatást.', $id)) extends \InvalidArgumentException implements NotFoundExceptionInterface {
                };
            }

            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }
        };
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

    /**
     * A `routes.yaml` resource-blokkjának neve — a #209 őre ebből olvassa ki,
     * hogy MINDKÉT import ugyanazt az előtagot deklarálja.
     */
    public static function resourceRouteBlock(): string
    {
        return self::RESOURCE_ROUTE_BLOCK;
    }
}
