# FoxPost Sylius Plugin

[![CI](https://github.com/connorhu/foxpost-sylius-plugin/actions/workflows/ci.yaml/badge.svg)](https://github.com/connorhu/foxpost-sylius-plugin/actions/workflows/ci.yaml)

FoxPost szállítási integráció Syliushoz: csomagfeladás, címke, nyomkövetés.

## Installation

```bash
composer require codeconjure/foxpost-sylius-plugin
```

Ez **önmagában nem elég**: a plugin mögött nincs Flex recipe, ezért a befogadó
boltban néhány helyen kell konfigurációt hozzátenni — bundle-regisztráció,
route-import, `.env`, a közös szállító-csomag két host-sora, és a két entitás
szerződése.

**A teljes lista hibamódokkal és másolható blokkokkal:
[`docs/installation.md`](docs/installation.md).**

## A közös szállító-csomagra épül (0.4.0, #15)

A pénztár két szállító-független mezőjét (`pickupPointId`, `phoneNumber`), a
validációs csoportjaikat, a szállítmányonkénti Stimulus-hatókört és az admin
feladás-slotot a **[`codeconjure/sylius-carrier-bundle`](https://github.com/connorhu/sylius-carrier-bundle)**
adja — egyszer, akárhány szállító-plugin van telepítve. Enélkül egy második
szállító (GLS, Packeta) ütközne a FoxPosttal: egy Symfony-mező csak egyszer
renderelhető, két azonos nevű `add()` közül a második felülírja az elsőt, és a
Sylius két hook-slotja egyetlen host-sort fogad.

A FoxPost ebbe két ponton kapcsolódik, **host-lépés nélkül** (mindkét hook-név
új, a `prepend()` bevezeti):

| Hol | Hook | Mit ad a FoxPost |
|---|---|---|
| Pénztár | `sylius_shop.checkout.select_shipping.content.form.shipments.shipment.carrier_fields` | a csomagautomata-választót (`pickup_point_widget.html.twig`, `data-carrier-delivery-kinds="foxpost_parcel_locker"`) |
| Admin | `codeconjure_carrier.admin.order.shipment.ship` | a feladás-gombot, CSAK FoxPost-módnál (`admin/shipment/ship.html.twig`) |

A választó a pontot a `carrier:pickup-point-selected` eseménnyel adja át (a
mezőbe a közös `carrier-shipment` controller ír), és a FoxPost térképének
üzenetét csak a `https://cdn.foxpost.hu` originről fogadja el — pontos
egyezéssel.

A rendelés-összegző átvételi pont-sora (`…summary.addresses.shipping.pickup_point`
hook) változatlan: a host a szállítási blokkjában hívja
(`{% hook 'pickup_point' with { shipment: shipment } %}`), a FoxPost hookable-je
a saját módjainál rajzol.

**A plugin eltávolítása** a bundle- és a route-sor törlése: a közös csomag két
host-sora a többi szállítót (vagy a Sylius alapját) szolgálja tovább.

## Nyomkövetés-szinkron (cron)

A csomag egy konzolparancsot ad: `bin/console foxpost:tracking:sync`. A
`FoxpostParcelRepository::findActiveForTracking()` által adott csomagokra
lekéri a FoxPost nyomkövetési státuszát, elteszi nyersen, és ahol a leképezés
ad átmenetet, lépteti az állapotgépet. A parancs **nem kér host-lépést** (a
bundle autoconfigurálja), a futtatás gyakorisága viszont a befogadó boltra
tartozik: minél gyakrabban fut, annál frissebb a vevő felé látható
kiszállítási állapot, és egy futás annyi FoxPost API-hívást indít, ahány
AKTÍV csomag van (a záró naplósor `found` száma) — tehát a költség a
csomagforgalommal nő, nem a gyakorisággal. A referencia-bolt 15 percenként
futtatja, `flock`-kal az átfedő futások ellen.

A parancs és a `monolog.logger.foxpost` csatorna záró sora ugyanazt az öt
számlálót adja (#173):

```
FoxPost tracking sync complete.
  found:      42   # amit a repository adott
  synced:     40   # sikeres API-hívás + eltett nyers státusz
  transitions: 12  # ahány csomag tényleg állapotot váltott
  skipped:     1   # vonalkód nélkül vagy használhatatlan válasz
  failed:      1   # API-hiba (soronként egy warning is van a naplóban)
```

Minden csomag pontosan egy kategóriába esik a `synced`/`skipped`/`failed`
közül, tehát a három összege a `found`. A `transitions` ezekre ortogonális (a
`synced` részhalmaza), és ez a szinkron valódi kimenete: a nyers státusz
eltevése önmagában nem mozdítja a rendelést. A `0.1.3` előtt a záró sor egyetlen
számot írt, a repository találatszámát — egy teljesen sikertelen futás
ugyanazt jelentette, mint egy teljesen sikeres.

## Verziózás

A csomag [SemVer](https://semver.org/lang/hu/) szerint verziózódik, és tagolt
kiadásokat ad — a kiadások tartalma a [CHANGELOG.md](CHANGELOG.md)-ben van. A
`0.x` soron a nyilvános API (a host-szerződés interfészei és traitjei is) még
mozoghat: a mindenkori minor sorra érdemes constraintet írni (`^0.3`),
`dev-main`-re nem.

A **támogatott Sylius-sor `~2.1`–`~2.2`** — a `~2.3` ma nem telepíthető (#205).

## Fejlesztés

```bash
composer update
vendor/bin/phpunit
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
```

### A tesztek teszt-kernel nélkül futnak

A csomag **nem szállít Sylius teszt-alkalmazást**, és nem is kell neki: az
admin réteg (grid, akció-sablonok, a két controller, a menü listener) a Sylius
VALÓDI renderelőivel, a plugin valódi route-gyűjteményével és fordítási
katalógusával mérhető — `tests/Support/` alatt van az a néhány osztály, ami
ezeket összerakja (`PluginRoutes`, `AdminTwig`, `ParcelGrid`,
`AdminControllerHarness`, `ParcelWorkflow`). A FoxPost API helyén PSR-18 mock
HTTP kliens áll, tehát a hibaágak is a valódi kivételekből jönnek.

A route-gyűjteményt a `PluginRoutes` a csomag saját `routes.yaml`-jából építi
fel, a valódi betöltő-lánccal (Symfony `YamlFileLoader` → Sylius
`ResourceLoader` + FrameworkBundle `AttributeRouteControllerLoader`), és a
`%sylius_admin.path_name%` előtagot a FrameworkBundle `Router`-e oldja fel —
így az útvonalak byte-azonosak azzal, amit a befogadó bolt `debug:router`-e
mutat, és a tesztek egy NEM alapértelmezett admin-útvonalon is mérhetnek
(#209).

Amit ez a megközelítés szerkezetileg nem lát, az a HOST oldali huzalozás (hogy
a bolt tényleg importálja a route-okat, betölti a fordításokat, és beépítette
a traiteket) — azt a befogadó bolt méri, l.
`tests/Functional/FoxPostPluginWiringTest.php` a
`praemonstratensis/egyhazzene-bolt`-ban.

Ugyanezt a négy kaput futtatja a CI is, két Sylius-soron (`~2.1.0` és `~2.2.0`)
és két PHP-verzión — lásd [`.github/workflows/ci.yaml`](.github/workflows/ci.yaml).
A `~2.3.0`-s sor szándékosan nincs benne: ott a plugin ma nem fordul
(Symfony 8 `XmlFileLoader`, DBAL 4 `Type::getName()`), ez külön döntés.

### A magcsomag élő összekötése

A `codeconjure/foxpost` a Packagistról, tagolt verzión (`^0.1`) települ. A repó
gyökerében **nincs `path` repository**, és ez szándékos: attól egy olyan
klónban, ami mellett nem áll ott a `../foxpost` könyvtár, a `composer update`
el sem indul —

```
The `url` supplied for the path (../foxpost) repository does not exist
```

—, tehát a CI és minden külső közreműködő azonnal elakadna. Ha a magcsomagon
és a pluginon együtt dolgozol, a linket helyileg kell felvenni, és **nem
commitolni**:

```bash
composer config repositories.foxpost path ../foxpost
composer update codeconjure/foxpost
# ha végeztél:
composer config --unset repositories.foxpost
```

A visszavételt a `tests/Unit/ContinuousIntegrationContractTest.php` is őrzi:
ha egy `path` repository bekerül a commitolt manifestbe, a teszt pirosra vált.

## License

MIT
