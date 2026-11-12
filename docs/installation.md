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
| 1 | `config/bundles.php` (a közös csomaggal együtt, l. 5.) | igen | **hangos** — a konténer nem fordul |
| 2 | `config/routes/codeconjure_foxpost.yaml` | igen | **hangos** — minden admin lap `RouteNotFoundException` |
| 3 | `assets/controllers.json` | **automatikus** (a Flex írja) | néma — a Stimulus controllerek nem kerülnek a bundle-be |
| 4 | `.env` | igen | **hangos** — `EnvNotFoundException` |
| 5 | a közös szállító-csomag: bundle + két `_sylius.yaml` sor | igen | néma — a pénztár mezői rejtve maradnak, a csomagot nem lehet feladni |
| 6 | *(megszűnt — az 5. része)* | | |
| 7 | a két entitás szerződése (`deliversToPickupPoint()` új) | igen | **hangos** — fordítási hiba, vagy `NoSuchPropertyException` a szállítási lépésen |
| 8 | *(megszűnt — városkereső)* | | |
| 9 | `config/packages/sylius_labs_doctrine_migrations_extra.yaml` | csak frissítő boltnak | a migrációk sorrendje fordított |

A 2., 5. és 7. lépés a `codeconjure/egyhazzene-hu-bolt` oldalán
**teszttel őrzött** (`tests/Functional/FoxPostPluginWiringTest.php`), a 3. a
`tests/Unit/Assets/ControllersJsonContractTest.php`-ban — érdemes ugyanígy
megőrizni, mert mind az öt hibamód néma vagy félrevezető.

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

| route | metódus | útvonal |
|---|---|---|
| `app_admin_foxpost_parcel_index` | GET | `/{admin}/foxpost-parcels/` |
| `app_admin_foxpost_parcel_register` | POST | `/{admin}/foxpost/parcels/register/{shipmentId}` |
| `app_admin_foxpost_parcel_cancel` | POST | `/{admin}/foxpost/parcels/{id}/cancel` |
| `app_admin_foxpost_parcel_hand_over` | POST | `/{admin}/foxpost/parcels/{id}/hand-over` |
| `app_admin_foxpost_parcel_bulk_register` | POST | `/{admin}/foxpost/parcels/bulk-register` |
| `app_admin_foxpost_label_download_single` | GET | `/{admin}/foxpost/labels/download/{id}` |
| `app_admin_foxpost_label_download_bulk` | POST | `/{admin}/foxpost/labels/download-bulk` |

A táblázat `{admin}` helyén **a bolt admin-útvonala** áll: mind a hét route a
`sylius_admin.path_name` konténer-paraméterből kapja az előtagot, vagyis a
`SYLIUS_ADMIN_ROUTING_PATH_NAME` környezeti változót követi (alapértelmezésben
`admin`). A boltnak ezzel nincs dolga — de érdemes tudni, hogy ez **nem
mindig** volt így: a 0.1.2 előtt a hat művelet-route bedrótozott `/admin`
előtagot használt, és egy nem alapértelmezett admin-útvonalú boltban kiesett a
`ROLE_ADMINISTRATION_ACCESS` szabály (`path: "%sylius.security.admin_regex%"`)
alól (#209).

> A két `POST` tömeges route (`bulk_register`, `label_download_bulk`)
> **csomag-azonosítókat** olvas `ids[]`-ben — mindkettő a csomaglista
> kijelöléséből dolgozik, és a lista sorai csomagok (#208). A feladás maga
> továbbra is a csomag mögötti szállítmányból építi a payloadot, azt a
> controller keresi meg. **0.2.0 előtt a `bulk_register` szállítmány-azonosítót
> várt**: ha a boltod saját felületről hívja ezt a route-ot, a frissítéssel át
> kell írnia a küldött azonosítókat.

## 3. Stimulus controllerek

**Ezzel a lépéssel nincs dolgod** — a Symfony Flex írja be, a
`composer require` (és minden későbbi `composer update`) után:

```json
// assets/controllers.json — a Flex állítja elő
{
    "controllers": {
        "@codeconjure/foxpost-sylius-plugin": {
            "foxpost-pickup-point": { "enabled": true, "fetch": "eager" }
        },
        "@codeconjure/sylius-carrier-bundle": {
            "carrier-shipment": { "enabled": true, "fetch": "eager" }
        }
    }
}
```

és ugyanő veszi fel a `package.json`-ba:

```json
"@codeconjure/foxpost-sylius-plugin": "file:vendor/codeconjure/foxpost-sylius-plugin/assets"
```

Ami ezt lehetővé teszi: a plugin `composer.json`-jának `keywords` listájában
ott van a **`symfony-ux`** kulcsszó. A Flex `PackageJsonSynchronizer`-e minden
`composer update` után a nulláról írja újra a `controllers` kulcsot, és csak
azokat a csomagokat teszi vissza, amelyek a **composer.lock-bejegyzésükben**
ezt a kulcsszót hordozzák (`resolvePackageJson()`). A kulcsszó a #197 előtt
hiányzott, és a Flex ezért a blokkot minden update-nél kitörölte — ötszer
mérve, mindannyiszor némán (`codeconjure/egyhazzene-hu-bolt#197`).

> A kulcsszót a Flex a **lockból** olvassa, nem a `vendor/.../composer.json`-ból:
> egy a #197 előtti lockfile-lal futó bolt az első `composer update
> codeconjure/foxpost-sylius-plugin`-ig még a régi viselkedést kapja. Az az
> update egyben az első, amelyik már nem töröl — a blokkot viszont a Flex
> formázása és sorrendje szerint újraírja, tehát egy egyszeri, ártalmatlan
> formázási diff még keletkezik.

Amit viszont a Flex **nem** tesz meg: az `npm install` és a build. A
bejegyzés megléte nem bizonyíték — a bundle-t kell megmérni (l. lentebb,
*Ellenőrzés*).

**Az azonosítókkal nincs dolgod.** A FoxPost controllere a rövid
`foxpost-pickup-point` azonosítón regisztrálódik (a 0.3.x `foxpost-shipping`
controllere a 0.4.0-ban megszűnt: a láthatóságot a közös csomag
`carrier-shipment` controllere kezeli) —
pontosan azon, amit a plugin sablonjainak `data-controller` attribútumai
írnak. Ez nem a bridge alapértelmezése: az a csomagnévből képezné
(`codeconjure--foxpost-sylius-plugin--foxpost-pickup-point`), ezért a plugin
`assets/package.json`-ja megadja a `name` kulcsot
(#203 — a kiszervezés óta ez a kulcs hiányzott, és a két controller némán nem
kapcsolódott be). A saját `controllers.json`-odban is adhatnál `name`-et, de
az nem tartós: a Flex szinkronizálója csak az `enabled`, a `fetch` és az
`autoimport` kulcsot írja vissza, egy ottani `name` minden `composer
update`-nél elveszne.

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

## 5. A közös szállító-csomag (`codeconjure/sylius-carrier-bundle`)

A 0.4.0 óta a plugin a **közös szállító-csomagra** épül (#15): a pénztár két
mezőjét (`pickupPointId`, `phoneNumber`), a validációs csoportjaikat, a
szállítmányonkénti Stimulus-hatókört és az admin feladás-slotot az a csomag
adja — egyszer, akárhány szállító-plugin (FoxPost, GLS, Packeta) van
telepítve. A Composer függőségként húzza be; a bundle-t regisztrálni kell, a
FoxPost **elé**:

```php
// config/bundles.php
CodeConjure\SyliusCarrierBundle\CodeConjureSyliusCarrierBundle::class => ['all' => true],
CodeConjure\SyliusFoxPostPlugin\CodeConjureSyliusFoxPostPlugin::class => ['all' => true],
```

A közös csomag **két host-sora** váltja a 0.3.x két FoxPost-sorát:

```yaml
# config/packages/_sylius.yaml
sylius_twig_hooks:
    hooks:
        # a pénztár szállítmány-ciklusa (volt: a FoxPost shipments.html.twig-je)
        'sylius_shop.checkout.select_shipping.content.form.shipments':
            shipment:
                template: '@CodeConjureSyliusCarrier/shop/checkout/select_shipping/shipments.html.twig'

        # az admin feladás-panel (volt: a FoxPost ship.html.twig-je)
        'sylius_admin.order.show.content.sections.shipments.item.actions':
            ship:
                props:
                    template: '@CodeConjureSyliusCarrier/admin/shipment/ship.html.twig'
```

A részletek és a hibamódok a közös csomag
[telepítési leírásában](https://github.com/connorhu/sylius-carrier-bundle/blob/main/docs/installation.md)
vannak. **A FoxPost maga nem kér Sylius-foglalt hookable-t**: a csomagautomata-
választója a közös csomag widget-hookjába
(`…shipments.shipment.carrier_fields`), a feladás-gombja a szállító-hookba
(`codeconjure_carrier.admin.order.shipment.ship`) kapcsolódik — mindkettő új
név, a `prepend()` magától bevezeti.

**Ha a host felülírta a FoxPost mezőpaneljét** (`foxpost_fields`): az a
hookable megszűnt. A közös csomag `carrier_fields` hookable-jét lehet
felülírni; a felülíró sablonnak a `data-carrier-shipment-target`
attribútumokat meg kell tartania, és a csomagautomata-szekcióban meg kell
hívnia a `{% hook 'carrier_fields' %}`-ot — enélkül a FoxPost-választó nem
jelenik meg.

## 6. (megszűnt) — az admin feladás-panel hookja

A 0.3.x-ben külön lépés volt; a 0.4.0 óta a fenti 5. lépés második sora.

## 7. A két entitás szerződése

A szállítmány két oszlopa **a hosté** (szolgáltatófüggetlen: a futáros mód is
kéri a telefont). A szerződés a közös csomagé, a FoxPost interfészei abból
öröklődnek:

- `FoxPostShipmentInterface` → `CarrierShipmentInterface`: getter **és
  setter** a `pickupPointId`-hez és a `phoneNumber`-hez (a pénztár űrlapja ír),
  plusz a `getDeliveryKindSlug()` fajta-pillanatkép;
- `FoxPostShippingMethodInterface` → `CarrierShippingMethodInterface`:
  `getDeliveryKindSlug()`, `requiresRecipientPhone()`, és **új a 0.4.0-ban:
  `deliversToPickupPoint()`** — a csomagautomata-fajtánál `true`. Ebből dönti
  el a közös csomag, hogy az átvételi pont kötelező-e, és megjelenjen-e a
  szekció.

```php
#[ORM\Column(name: 'pickup_point_id', type: Types::STRING, nullable: true)]
private ?string $pickupPointId = null;

#[ORM\Column(name: 'phone_number', type: Types::STRING, length: 30, nullable: true)]
private ?string $phoneNumber = null;
```

A setterek nélkül a hiba **hangos**, de nem ott, ahol az ember keresné: a form
a property path-on keresztül ír, a Symfony `PropertyAccessor` a pénztár
szállítási lépését állítja le
(`Can't get a way to read the property "pickupPointId"`).

**Felülírás.** A két mező a közös csomag alapértelmezése (`PRIORITY = 16`): egy
prioritás nélküli host form-kiterjesztés bármelyiket lecserélheti (típust,
feliratot, constrainteket) — a referencia-bolt így szűkíti a telefonmezőt a
magyar mobilszámokra.

## 8. (megszűnt) — városkereső route

A `codeconjure.foxpost.city_lookup_route` paraméter és a
`foxpost_city_lookup_url()` Twig-függvény a 0.4.0-ban megszűnt: csak a
pénztár 0.3.x-es FoxPost-wrapperén volt kiolvasva, a postai kód mezője pedig
a #99 óta nem a 2. lépésen van. Egy ottmaradt host-paraméter ártalmatlan,
törölhető.

## 9. Migrációs sorrend — csak frissítő boltnak

A plugin a saját migrációs útvonalát **maga** regisztrálja (a Sylius
`PrependDoctrineMigrationsTrait`-jével), és azt is kijelenti, hogy a
migrációi a Sylius mag migrációi után futnak. Egy **üres** Sylius bolt tehát
ehhez nem ír konfigurációt.

Egy olyan bolt viszont, aminek a **saját** migrációi már létrehozták a
`foxpost_parcel` táblát és a plugin oszlopait (ilyen minden bolt, ami a
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

A telepítés után ez az öt parancs méri meg, hogy minden a helyén van:

```bash
# 1. A hét admin route létezik, és MIND az admin-útvonal alatt
bin/console debug:router | grep -c foxpost          # → 7
bin/console debug:router | grep foxpost | grep -cv " /admin/"   # → 0
#   (nem alapértelmezett admin-útvonal esetén a saját prefixedre szűrj)

# 2. A plugin migrációs névtere regisztrált
bin/console debug:config doctrine_migrations | grep SyliusFoxPostPlugin

# 3. A plugin három migrációja látszik, és a bolt sajátjai UTÁN
bin/console doctrine:migrations:list | grep -c 'SyliusFoxPostPlugin'   # → 3

# 4. A FoxPost a közös csomag két hookjába kapcsolódik (#15)
bin/console debug:config sylius_twig_hooks | grep -B1 -A2 'foxpost:' | grep -E 'carrier_fields|codeconjure_carrier'

# 5. A Flex felismeri-e a csomagot: a kulcsszót a LOCKBÓL olvassa, és ha nincs
#    ott, a következő update kitörli a controllers.json bejegyzést (#197)
php -r '$l = json_decode(file_get_contents("composer.lock"), true);
  foreach ($l["packages"] as $p) {
      if ($p["name"] === "codeconjure/foxpost-sylius-plugin") {
          echo \in_array("symfony-ux", $p["keywords"] ?? [], true) ? "ok\n" : "HIÁNYZIK\n";
      }
  }'
```

A Stimulus-oldal böngésző nélkül a lefordított bundle regiszterén mérhető —
a rövid azonosítóknak ott kell lenniük:

```bash
grep -c "'foxpost-pickup-point'" public/build/app/shop/app-shop-entry.js   # → 1
grep -c "'carrier-shipment'" public/build/app/shop/app-shop-entry.js       # → 1
```

Felületen: az admin menüben megjelenik a FoxPost csomaglista, a pénztár
szállítási lépésén pedig a FoxPost automata-mód kiválasztásakor a közös
csomag telefonszám- és átvételi pont-szekciója, benne a FoxPost-választóval.

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
a Flex saját `PackageJsonSynchronizer`-e kezeli, a plugin `symfony-ux`
kulcsszava alapján (#197).

## Döntés-napló

| Mi | Hova került | Miért |
|---|---|---|
| a hat controller-route attribútum-importja | **pluginba** (#194) | a bolt nem tud róla semmit, amit a plugin ne tudna jobban; két host-blokk helyett egy |
| a migrációs útvonal (`migrations_paths`) | **pluginba** (#194) | a Sylius ad rá kész traitet (`PrependDoctrineMigrationsTrait`), ugyanaz, amit a `sylius/paypal-plugin` is használ |
| a migrációk sorrendje | **hostnál maradt** | bolt-specifikus: csak az tudja, hogy a saját migrációi létrehozták-e már ugyanazokat az objektumokat |
| a két hook-felülírás | **hostnál maradt** | a kulcsok a Syliuséi, és egy plugin `prepend()`-je meglévő kulcsot nem tud felülírni |
| a városkereső route neve | **hostnál maradt** | host route-név, a plugin nem ismerheti |
| a checkout két mezője | **hostnál maradt** | a bolt saját cím- és telefonszám-infrastruktúrájára épül (l. README) |
| a Stimulus-azonosítók | **pluginba** (#203) | a `name` kulcs a csomagban tartós, a host `controllers.json`-jában nem: a Flex minden update-nél újraírja |
| a `controllers.json` bejegyzés | **a Flexre** (#197) | a `symfony-ux` kulcsszóval a szinkronizáló maga tartja fenn; kézzel visszatett blokkot minden update kitörölt |
