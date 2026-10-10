<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Unit\Admin;

use CodeConjure\SyliusFoxPostPlugin\Tests\Support\PluginRoutes;
use CodeConjure\SyliusFoxPostPlugin\Tests\Support\Yaml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A #209 szerződése: a plugin hét admin route-ja UGYANABBÓL az előtagból
 * képződik, és az az előtag a boltban konfigurált admin-útvonal.
 *
 * A hibamód, amit ez a teszt lezár, néma volt és csak egy IDEGEN boltban sül
 * el: a csomaglista resource-route-ja a `/%sylius_admin.path_name%` prefixet
 * kapta, a két controller hat `#[Route]` attribútuma viszont bedrótozott
 * `/admin`-t írt. A mai egyhazzene.hu boltban a kettő egybeesik (ott a
 * `SYLIUS_ADMIN_ROUTING_PATH_NAME` az alapértelmezésen van), tehát semmi nem
 * látszott — a boltban mérve viszont `SYLIUS_ADMIN_ROUTING_PATH_NAME=backend`
 * mellett a csomaglista `/backend/foxpost-parcels/`-re ment, a hat művelet
 * pedig `/admin/foxpost/...`-on maradt, ahol a bolt
 * `access_control`-szabálya (`path: "%sylius.security.admin_regex%"`,
 * `role: ROLE_ADMINISTRATION_ACCESS`) már nem illeszkedik rájuk.
 *
 * Ezért a tesztek NEM az alapértelmezett `admin` útvonalon mérnek: egy
 * bedrótozott `/admin` ott definíció szerint helyes eredményt adna.
 */
final class AdminRoutePrefixTest extends TestCase
{
    /**
     * Szándékosan nem `admin`: ez az az érték, amivel a bedrótozott előtag
     * kibukik. (A Sylius alapértelmezése `admin`, l.
     * `PluginRoutes::ADMIN_PATH_NAME`.)
     */
    private const string FOREIGN_PATH_NAME = 'backend';

    /**
     * A plugin admin route-jai és a HTTP-metódusaik. Ez a lista az egyetlen
     * bedrótozott elem a fájlban, és szándékosan az: a #194 hibamódja (a hat
     * controller-route NEM létezett) csak egy kívülről adott elvárás ellen
     * mérve bukik ki.
     *
     * Az útvonalakban a `{admin}` helyén a konfigurált admin-útvonal áll —
     * épp ez a #209 szerződése.
     *
     * @var array<string, array{string, string}>
     */
    private const array ROUTES = [
        'app_admin_foxpost_parcel_index' => ['GET', '/{admin}/foxpost-parcels/'],
        'app_admin_foxpost_parcel_register' => ['POST', '/{admin}/foxpost/parcels/register/{shipmentId}'],
        'app_admin_foxpost_parcel_cancel' => ['POST', '/{admin}/foxpost/parcels/{id}/cancel'],
        'app_admin_foxpost_parcel_hand_over' => ['POST', '/{admin}/foxpost/parcels/{id}/hand-over'],
        'app_admin_foxpost_parcel_bulk_register' => ['POST', '/{admin}/foxpost/parcels/bulk-register'],
        'app_admin_foxpost_label_download_single' => ['GET', '/{admin}/foxpost/labels/download/{id}'],
        'app_admin_foxpost_label_download_bulk' => ['POST', '/{admin}/foxpost/labels/download-bulk'],
    ];

    public function testThePluginShipsExactlyTheSevenAdminRoutes(): void
    {
        $built = [];
        foreach (PluginRoutes::collection()->all() as $name => $route) {
            $built[$name] = implode(',', $route->getMethods());
        }

        $expected = array_map(static fn (array $route): string => $route[0], self::ROUTES);

        ksort($built);
        ksort($expected);

        self::assertSame(
            $expected,
            $built,
            'A plugin `routes.yaml`-ja nem a hét várt admin route-ot adja. Ha ez egy ÚJ ' .
            'route, vedd fel a `ROUTES` listába — és győződj meg róla, hogy az előtagot is ' .
            'a `%sylius_admin.path_name%` paraméterből kapja (#209).',
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function routeProvider(): iterable
    {
        foreach (self::ROUTES as $name => [, $path]) {
            yield $name => [$name, $path];
        }
    }

    /**
     * Az előtag feloldása nem a tesztben történik: a `PluginRoutes` a
     * FrameworkBundle valódi routerét használja, és az oldja fel a
     * `%sylius_admin.path_name%`-et (`Router::resolveParameters()`) — ugyanúgy,
     * mint a befogadó boltban.
     */
    #[DataProvider('routeProvider')]
    public function testEveryAdminRouteIsServedUnderTheConfiguredAdminPath(string $name, string $path): void
    {
        foreach ([PluginRoutes::ADMIN_PATH_NAME, self::FOREIGN_PATH_NAME] as $pathName) {
            $route = PluginRoutes::collection($pathName)->get($name);
            self::assertNotNull($route, \sprintf('A `%s` route nem épült fel.', $name));

            self::assertSame(
                str_replace('{admin}', $pathName, $path),
                $route->getPath(),
                \sprintf(
                    'A `%s` route útvonala nem a konfigurált admin-útvonal (`%s`) alatt van. ' .
                    'Egy nem alapértelmezett `SYLIUS_ADMIN_ROUTING_PATH_NAME`-ű boltban az ' .
                    'ilyen route kiesik a `ROLE_ADMINISTRATION_ACCESS` szabály alól (#209); ' .
                    'egy bedrótozott előtag pedig megduplázódik a prefix mellett.',
                    $name,
                    $pathName,
                ),
            );
        }
    }

    /**
     * A `#[Route]` útvonala fordítási idejű konstans, konténer-paramétert nem
     * tud feloldani — ezért az attribútumokban egyáltalán NEM lehet
     * admin-előtag. Ez az őr egy ÚJ controlleren is elsül, amin a szerző
     * reflexből kiírná az `/admin`-t.
     */
    public function testNoControllerAttributeHardcodesTheAdminPath(): void
    {
        foreach (glob(\dirname(__DIR__, 3) . '/src/Controller/*.php') ?: [] as $file) {
            $source = file_get_contents($file);
            \assert(\is_string($source));

            self::assertDoesNotMatchRegularExpression(
                '/#\[Route\(\s*[\'"]\/admin/',
                $source,
                \sprintf(
                    'A `%s` `#[Route]` attribútuma bedrótozott `/admin` előtagot ír. Az ' .
                    'előtagot a `routes.yaml` importja adja `/%%sylius_admin.path_name%%`-ként (#209).',
                    basename($file),
                ),
            );
        }
    }

    /**
     * A `routes.yaml` MINDEN importjának ugyanazt az előtagot kell adnia. Ez
     * az az állítás, ami egy harmadik import felvételénél elsül — a
     * route-útvonalakat mérő teszt ilyenkor csak akkor bukna, ha az új route
     * bekerült volna a `ROUTES` listába is.
     */
    public function testEveryRouteImportDeclaresTheSameAdminPrefix(): void
    {
        $imports = Yaml::parse('src/Resources/config/routes.yaml');

        self::assertNotEmpty($imports, 'A `routes.yaml` egyetlen importot sem deklarál.');

        foreach ($imports as $block => $import) {
            self::assertIsArray($import);
            self::assertSame(
                '/%sylius_admin.path_name%',
                $import['prefix'] ?? null,
                \sprintf(
                    'A `%s` import nem a `/%%sylius_admin.path_name%%` előtagot adja. A hét ' .
                    'route-nak egyetlen paraméterből kell képződnie (#209).',
                    (string) $block,
                ),
            );
        }
    }

    /**
     * Miért PONT ez a paraméter: a Sylius admin tűzfala és a
     * `ROLE_ADMINISTRATION_ACCESS` szabály is ebből képződik
     * (`sylius.security.admin_regex: "^/%sylius_admin.path_name%"`). Ha a
     * plugin más paraméterre (vagy konstansra) állna, a route-ok akkor is
     * „admin alatt" lennének, csak nem a tűzfal alatt.
     *
     * Ez az egyetlen állítás, ami a Sylius vendor-fájljaiba néz. Ha a
     * konfiguráció helye egy jövőbeli Sylius-kiadásban elmozdul, a teszt
     * kihagyja magát — a szerződés dokumentálása akkor is a kódban marad.
     */
    public function testTheAdminPathNameIsTheParameterTheAdminFirewallIsBuiltFrom(): void
    {
        $config = \dirname(__DIR__, 3) . '/vendor/sylius/sylius/src/Sylius/Bundle/AdminBundle/Resources/config/app/config.yml';

        if (!file_exists($config)) {
            self::markTestSkipped(\sprintf('A Sylius AdminBundle konfigurációja nincs a várt helyen (`%s`).', $config));
        }

        $contents = file_get_contents($config);
        \assert(\is_string($contents));

        self::assertStringContainsString(
            'sylius.security.admin_regex: "^/%sylius_admin.path_name%"',
            $contents,
            'A Sylius admin tűzfal-regexe már nem a `sylius_admin.path_name` paraméterből ' .
            'képződik — a plugin route-előtagját ehhez kell igazítani (#209).',
        );
    }
}
