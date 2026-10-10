# Telepítés egy befogadó Sylius boltba

A `composer require codeconjure/foxpost-sylius-plugin` **önmagában nem elég**:
a plugin mögött nincs Flex recipe (l. [lentebb](#flex-recipe)), ezért a
befogadó boltban néhány lépést kézzel kell megtenni.

Ez a dokumentum ezeket a lépéseket sorolja fel — mindegyik mellett ott van,
**mi a hibamód, ha kimarad**, mert a listán csak kettő hangos, a többi néma
vagy félrevezető.

**Előfeltételek:** Sylius `^2.1`, PHP `^8.4`, és a bolt eszközrétege Webpack
Encore + `@symfony/stimulus-bridge` (a Sylius 2.1 alapértelmezése). Az
AssetMapper-es felállás nincs mérve.

A példák a `codeconjure/egyhazzene-hu-bolt` valódi konfigurációjából valók —
az a bolt a plugin első (és a mérések idején egyetlen) befogadója.

---

## A lépések egy lapon

| # | Hely | Kötelező? | Hibamód, ha kimarad |
|---|---|---|---|
| 1 | `config/bundles.php` | igen | **hangos** — a konténer nem fordul |
| 2 | `config/routes/codeconjure_foxpost.yaml` | igen | **hangos** — minden admin lap `RouteNotFoundException` |
| 3 | `assets/controllers.json` | igen (ha van pénztár-UI) | néma — a két Stimulus controller nem kerül a bundle-be |
| 4 | `.env` | igen | **hangos** — `EnvNotFoundException` |
| 5 | `config/packages/_sylius.yaml` — pénztár hook | igen (ha van pénztár-UI) | néma — a FoxPost-mezők nem jelennek meg |
| 6 | `config/packages/_sylius.yaml` — admin feladás-panel | igen | néma — a csomagot nem lehet feladni a rendelés lapjáról |
| 7 | a checkout shipment form két mezője | igen (ha van pénztár-UI) | néma — a panelek kimaradnak |
| 8 | `config/services.yaml` — városkereső route | nem | néma — a postai kód → város kiegészítés nem működik |
| 9 | `config/packages/sylius_labs_doctrine_migrations_extra.yaml` | csak frissítő boltnak | a migrációk sorrendje fordított |

A 2., 3., 5., 6. és 7. lépés a `codeconjure/egyhazzene-hu-bolt` oldalán
**teszttel őrzött** (`tests/Functional/FoxPostPluginWiringTest.php`) — érdemes
ugyanígy megőrizni, mert mind az öt hibamód néma vagy félrevezető.

---

## 1. Bundle-regisztráció

```php
// config/bundles.php
return [
    // ...
    CodeConjure\SyliusFoxPostPlugin\CodeConjureSyliusFoxPostPlugin::class => ['all' => true],
];
```

**Miért nem automatikus:** nincs Flex recipe.

**Mérve (2026-10-10)** — a sor nélkül a `bin/console cache:clear` ezzel áll le:

```
In Kernel.php line 215:
  Bundle "CodeConjureSyliusFoxPostPlugin" does not exist or it is not enabled.
  Maybe you forgot to add it in the "registerBundles()" method of your
  "App\Kernel.php" file?
```

Ez a lista egyik hangos hibamódja — és csak azért hangos, mert a 2. lépés
route-importja a bundle-aliasra hivatkozik. Bundle **és** route-import nélkül
a bolt elindul, csak nem tud semmit a FoxPostról.

## 2. Route-import

```yaml
# config/routes/codeconjure_foxpost.yaml
codeconjure_foxpost:
    resource: '@CodeConjureSyliusFoxPostPlugin/src/Resources/config/routes.yaml'
```

Ez **egyetlen** blokk: a plugin `routes.yaml`-ja maga importálja a két admin
controller `#[Route]` attribútumait is (`type: attribute` a `src/Controller/`
könyvtárra). A boltnak tehát nem kell attribútum-szkennelést írnia — ez a
#194 előtt még az ő dolga volt, és a két blokk közül a második könnyen
lemaradt.

**Miért nem automatikus:** Symfonyban egy bundle nem tud route-ot
regisztrálni a host importja nélkül; a router csak a `config/routes/`
erőforrásaiból gyűjt.

**Mérve (2026-10-10)** — a hét route (`debug:router | grep foxpost`) a fájl
nélkül **nullára** esik, és a plugin admin menüpontja az URL-generálásnál áll
le:

```
Symfony\Component\Routing\Exception\RouteNotFoundException:
  Unable to generate a URL for the named route "app_admin_foxpost_parcel_index"
  as such route does not exist.
```

A menüt a `Menu\AdminMenuListener` minden admin lapon felépíti, tehát ez
**minden** admin lapot leállít, nem csak a FoxPost-listát.

Amit ez az egy import ad:

| route | metódus |
|---|---|
| `app_admin_foxpost_parcel_index` | GET |
| `app_admin_foxpost_parcel_register` | POST |
| `app_admin_foxpost_parcel_cancel` | POST |
| `app_admin_foxpost_parcel_hand_over` | POST |
| `app_admin_foxpost_parcel_bulk_register` | POST |
| `app_admin_foxpost_label_download_single` | GET |
| `app_admin_foxpost_label_download_bulk` | POST |

## 3. Stimulus controllerek

```json
// assets/controllers.json
{
    "controllers": {
        "@codeconjure/foxpost-sylius-plugin": {
            "foxpost-shipping": { "enabled": true, "fetch": "eager" },
            "foxpost-pickup-point": { "enabled": true, "fetch": "eager" }
        }
    }
}
```

és a `package.json`-ban:

```json
"@codeconjure/foxpost-sylius-plugin": "file:vendor/codeconjure/foxpost-sylius-plugin/assets"
```

**Miért nem automatikus:** a Symfony Flex `PackageJsonSynchronizer`-e minden
`composer update` után a nulláról írja újra a `controllers` kulcsot, és csak
azt a csomagot ismeri fel, aminek a **composer.lock-bejegyzésében** ott van a
`symfony-ux` kulcsszó. A plugin `composer.json`-jában ez a kulcsszó ma nincs
ott, ezért a Flex a fenti blokkot **minden update-nél kitörli**. A befogadó
boltnak ezt vissza kell tennie, amíg a plugin a kulcsszót meg nem kapja —
l. `codeconjure/egyhazzene-hu-bolt#197`.

> **Ismert hiba:** a két controller `data-controller` azonosítója a
> kiszervezés (#187) óta nem egyezik a sablonokéval — a `stimulus-bridge` a
> csomagnévből képzi (`codeconjure--foxpost-sylius-plugin--foxpost-shipping`),
> a sablonok viszont a rövid nevet (`foxpost-shipping`) írják. A két
> controller emiatt **nem kapcsolódik be**. A javítás a plugin
> `assets/package.json`-jában egy `name` kulcs — l. a külön issue-t.

## 4. Környezeti változók

```dotenv
###> foxpost ###
FOXPOST_USERNAME=
FOXPOST_PASSWORD=
FOXPOST_API_KEY=
FOXPOST_SANDBOX=true
###< foxpost ###
```

Mind a **négy** kell: a `CodeConjure\FoxPost\Client` három hitelesítési
argumentuma és a `codeconjure.foxpost.environment` sandbox-flagje
(`src/Resources/config/services.xml`). A plugin nem ad hozzájuk
`env()`-alapértelmezést — szándékosan, mert így a hiányuk hangos.

**Mérve (2026-10-10)** — hiányzó változóval a dumpolt konténer ezt dobja a
szolgáltatás példányosításánál:

```
Symfony\Component\DependencyInjection\Exception\EnvNotFoundException:
  Environment variable not found: "FOXPOST_SANDBOX".
```

A `FOXPOST_SANDBOX=true` a FoxPost sandbox API-ját használja; élesben
`false`.

## 5. A pénztár szállítási lépésének hookja

```yaml
# config/packages/_sylius.yaml
sylius_twig_hooks:
    hooks:
        'sylius_shop.checkout.select_shipping.content.form.shipments':
            shipment:
                template: '@CodeConjureSyliusFoxPostPlugin/shop/checkout/select_shipping/shipments.html.twig'
                priority: 0   # vagy magasabb, ha a host maga is hookol ide
```

**Miért nem automatikus:** a `shipment` hookable-nevet a SyliusShopBundle maga
is definiálja, és egy plugin `prepend()`-je **nem tud meglévő kulcsot
felülírni** — a bundle-prepend a konfiglista elejére kerül, a későbbi (Sylius
saját) bejegyzés kulcsonként nyer. A hosté a legutolsó szó, ezért ez a csere
a telepítési szerződés része. Részletek és a hibamód: a README
„A pénztár felülete" szakasza.

## 6. Az admin feladás-panel hookja

```yaml
# config/packages/_sylius.yaml
sylius_twig_hooks:
    hooks:
        'sylius_admin.order.show.content.sections.shipments.item.actions':
            ship:
                props:
                    template: '@CodeConjureSyliusFoxPostPlugin/admin/shipment/ship.html.twig'
```

Ugyanaz a mechanizmus, mint az 5. lépésnél: a `ship` kulcs a Syliusé. A
`props` **kulcs szerint** fésül össze, tehát a Sylius `resource` / `path` /
`pathParameters` propjai megmaradnak — csak a sablon cserélődik. Részletek: a
README „Az admin szállítási panel" szakasza.

A plugin sablonja a nem-FoxPost szállítási módoknál a Sylius saját
panelját rendereli, tehát a csere nem FoxPost-specifikus boltot is kiszolgál.

## 7. A checkout shipment form két mezője

A `foxpost_fields.html.twig` **olvassa**, de nem hozza létre a `phoneNumber`
és `pickupPointId` mezőket — ezeket a hostnak kell a shipment formhoz adnia,
pontosan ezekkel a nevekkel. A szerződés (típusok, validációs csoportok, és
hogy miért néma a hiányuk) a README „A pénztár felülete" szakaszában van.

## 8. Városkereső route (opcionális)

```yaml
# config/services.yaml
parameters:
    codeconjure.foxpost.city_lookup_route: 'app_shop_cities_lookup'
```

A plugin szállítási controllere a postai kódból ki tudja egészíteni a város
nevét, ha a bolt ad neki egy saját route-ot ehhez. A plugin nem ismerhet host
route-nevet, ezért konténer-paraméterként kéri — **nem** `twig.globals`-ban
`"@=path(...)"` alakban, mert a DI ExpressionLanguage-ben nincs `path()`
függvény, az konténer-fordításkor elszállna.

**Mérve (2026-10-10)** — a sor nélkül a paraméter a plugin alapértelmezésén
marad (`"null"`), a `foxpost_city_lookup_url()` `null`-t ad, a sablon a
`data-foxpost-shipping-city-lookup-url-value` attribútumot ki sem teszi, és a
controller kiegészítés-ága csendben nem fut. Semmi nem hibázik — ezért
opcionális, és ezért érdemes tudni róla.

## 9. Migrációs sorrend — csak frissítő boltnak

A plugin a saját migrációs útvonalát **maga** regisztrálja (a Sylius
`PrependDoctrineMigrationsTrait`-jével), és azt is kijelenti, hogy a
migrációi a Sylius mag migrációi után futnak. Egy **üres** Sylius bolt tehát
ehhez nem ír konfigurációt.

Egy olyan bolt viszont, aminek a **saját** migrációi már létrehozták a
`foxpost_parcel` táblát és a három trait-oszlopot (ilyen minden bolt, ami a
plugin előtt saját FoxPost-kódot futtatott), a plugin migrációit a sajátjai
**után** kell sorolnia:

```yaml
# config/packages/sylius_labs_doctrine_migrations_extra.yaml
sylius_labs_doctrine_migrations_extra:
    migrations:
        'DoctrineMigrations': ~
        'CodeConjure\SyliusFoxPostPlugin\Migrations': ['DoctrineMigrations']
```

(A `'DoctrineMigrations': ~` deklaráció is kell, különben a hivatkozás
„Dependency ... not found" hibával elszáll.)

**Mérve (2026-10-10)** — e sor nélkül a `doctrine:migrations:list` a plugin
három migrációját a bolt tizenhat migrációja **elé** sorolja; üres
adatbázison a bolt `foxpost_parcel`-t létrehozó migrációja így már létező
táblába futna.

Ez az egyetlen pont, ahol a plugin kijelentését a hostnak felül kell írnia —
a `migrations` kulcs értéke ugyanis cserélődik, nem összefésül.

---

## Amit a plugin magától megtesz

Hogy a lista hossza arányban legyen: a telepítéshez szükséges munka nagyobb
része a plugin oldalán van. A befogadó boltnak **nem** kell:

- a hét admin route-ot deklarálnia (2. lépés, egy import);
- a migrációs útvonalat regisztrálnia (9. lépés, csak a sorrend);
- a `foxpost_parcel` resource-ot, gridet és állapotgépet felvennie;
- a csomagautomata-választó form theme-jét bekapcsolnia;
- az admin menüpontot, a grid-akciókat vagy a csatorna/szállítási mód
  mezőbővítéseinek hookjait kiraknia;
- a fordításokat behúznia.

Ezek a plugin `prepend()`-jéből és `services.xml`-jéből jönnek (öt host
extension konfigurációja: `framework`, `sylius_resource`, `sylius_grid`,
`twig`, `sylius_twig_hooks`, plusz a `doctrine_migrations` útvonal).

## Ellenőrzés

A telepítés után ez a négy parancs méri meg, hogy minden a helyén van:

```bash
# 1. A hét admin route létezik
bin/console debug:router | grep -c foxpost          # → 7

# 2. A plugin migrációs névtere regisztrált
bin/console debug:config doctrine_migrations | grep SyliusFoxPostPlugin

# 3. A plugin három migrációja látszik, és a bolt sajátjai UTÁN
bin/console doctrine:migrations:list | grep -c 'SyliusFoxPostPlugin'   # → 3

# 4. A városkereső paraméter (ha beállítottad)
bin/console debug:container --parameter=codeconjure.foxpost.city_lookup_route
```

Felületen: az admin menüben megjelenik a FoxPost csomaglista, a pénztár
szállítási lépésén pedig a FoxPost mód kiválasztásakor a telefonszám- és
csomagautomata-panel.

---

## Flex recipe

**Nem készül** (2026-10-10-i döntés). A `codeconjure/simplepay*` hármasnak
sincs, és a fenti lista fele olyan érték, amit egy recipe sem tudna kitalálni
(a városkereső route neve, a pénztár form mezői, a bolt saját migrációs
névtere). Amit egy recipe automatizálni tudna: az 1. (bundle), a 2. (route) és
a 4. (`.env`) lépés — azaz pont a két hangos hibamód, meg egy harmadik, ami
szintén hangos.

Ha mégis készül, a `symfony/recipes-contrib` a helye, és a tartalma a
`bundles.php`-bejegyzés, a `config/routes/codeconjure_foxpost.yaml` és az
`.env` blokk. A 3. lépés (`assets/controllers.json`) **nem** recipe-munka: azt
a Flex saját `PackageJsonSynchronizer`-e kezeli, amint a plugin megkapja a
`symfony-ux` kulcsszót.

## Döntés-napló

| Mi | Hova került | Miért |
|---|---|---|
| a hat controller-route attribútum-importja | **pluginba** (#194) | a bolt nem tud róla semmit, amit a plugin ne tudna jobban; két host-blokk helyett egy |
| a migrációs útvonal (`migrations_paths`) | **pluginba** (#194) | a Sylius ad rá kész traitet (`PrependDoctrineMigrationsTrait`), ugyanaz, amit a `sylius/paypal-plugin` is használ |
| a migrációk sorrendje | **hostnál maradt** | bolt-specifikus: csak az tudja, hogy a saját migrációi létrehozták-e már ugyanazokat az objektumokat |
| a két hook-felülírás | **hostnál maradt** | a kulcsok a Syliuséi, és egy plugin `prepend()`-je meglévő kulcsot nem tud felülírni |
| a városkereső route neve | **hostnál maradt** | host route-név, a plugin nem ismerheti |
| a checkout két mezője | **hostnál maradt** | a bolt saját cím- és telefonszám-infrastruktúrájára épül (l. README) |
