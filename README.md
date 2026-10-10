# FoxPost Sylius Plugin

[![CI](https://github.com/connorhu/foxpost-sylius-plugin/actions/workflows/ci.yaml/badge.svg)](https://github.com/connorhu/foxpost-sylius-plugin/actions/workflows/ci.yaml)

FoxPost szállítási integráció Syliushoz: csomagfeladás, címke, nyomkövetés.

## Installation

```bash
composer require codeconjure/foxpost-sylius-plugin
```

Ez **önmagában nem elég**: a plugin mögött nincs Flex recipe, ezért a befogadó
boltban kilenc helyen kell konfigurációt hozzátenni — bundle-regisztráció,
route-import, Stimulus controllerek, `.env`, két hook-felülírás, a checkout
két mezője, és (opcionálisan) a városkereső route.

**A teljes lista hibamódokkal és másolható blokkokkal:
[`docs/installation.md`](docs/installation.md).**

A két „host-szerződés" szakasz lentebb ugyanezeknek a lépéseknek a
részletezése (a pénztár-hook és a checkout mezői: 5. és 7. lépés; az admin
feladás-panel: 6. lépés); a telepítési dokumentum visszautal rájuk.

## A pénztár felülete — host-szerződés a checkout mezőkről

**A hostnak saját magának kell felülregisztrálnia a `shipment` hookable-t.**
A plugin két hookot ad a `sylius_shop.checkout.select_shipping.content.form.shipments`
hookable-hez:

- `shipment` — a `templates/shop/checkout/select_shipping/shipments.html.twig`,
  ami a Stimulus controller wrappert (`data-controller="foxpost-shipping"`)
  teszi ki minden shipment köré, és **`order`-t is átad** a beágyazott
  `…shipments.shipment` hooknak.

  Ez a név viszont **már foglalt**: a SyliusShopBundle saját maga is definiál
  egy `shipment` hookot ugyanide (`@SyliusShop/...`), és a plugin
  `prepend()`-je **nem tudja felülírni** egy már betöltött bundle saját
  definícióját — a `PrependExtensionInterface` csak addig ér, amíg a
  konfigurációt EGYMÁSSAL egyesíti, a hookable-nevek foglalását nem. Emiatt
  ez a bejegyzés a plugin `hooks.yaml`-jában ma **holt**: amíg a host nem
  regisztrálja felül explicit saját `config/packages/_sylius.yaml`-jában
  (`priority` úgy, hogy nyerjen), a Sylius alap `shipment` sablonja fut,
  ami **nem ad át `order`-t** a beágyazott hooknak, és a FoxPost-blokkok
  (`foxpost_fields.html.twig`) meg sem jelennek — az bármelyik shipment-re
  vonatkozó hook csak `form`-ot és `index`-et kap.

  Ezt a hostnak kell megtennie:
  ```yaml
  sylius_twig_hooks:
      hooks:
          'sylius_shop.checkout.select_shipping.content.form.shipments':
              shipment:
                  template: '@CodeConjureSyliusFoxPostPlugin/shop/checkout/select_shipping/shipments.html.twig'
                  priority: 0   # vagy magasabb, ha a host maga is hookol ide
  ```

  A `foxpost_fields.html.twig` erre az esetre — amikor a host elfelejti ezt
  megtenni, és a beágyazott hook `order` nélkül fut — önmagát védi: a
  `context.order`-t `is defined`-del olvassa, hiánya esetén a számlázási
  cím emlékeztető blokk egyszerűen kimarad (nem hibázik), a négy checkout-
  mezőtől függő két blokk pedig a lenti szerződés szerint viselkedik.

- `foxpost_fields` — az alábbi négy mezőt olvassa (**csak olvassa**, nem ő
  hozza létre őket). A négy mezőt a hostnak kell hozzáadnia a shipment
  formhoz, pontosan ezekkel a nevekkel:

| Mező | Típus | Mire kell |
|---|---|---|
| `foxpostSameAsBilling` | checkbox | FoxPost házhozszállítás: a szállítási cím a számlázási címmel egyezik-e |
| `foxpostAddress` | beágyazott cím-form (`lastName`, `firstName`, `countryCode`, `postcode`, `city`, `street`, `phoneNumber` gyerekmezőkkel) | FoxPost házhozszállítás: kézzel megadott cím |
| `pickupPointId` | hidden/szöveg | FoxPost csomagautomata: a kiválasztott automata azonosítója |
| `phoneNumber` | szöveg | FoxPost csomagautomata: értesítési telefonszám |

**Ha egy mező hiányzik a formról, a hozzá tartozó blokk a sablonban némán
kimarad — nem hibázik.** A két blokk (házhozszállítás-cím, illetve
csomagautomata) mindegyike a saját mezőit együtt ellenőrzi (`is defined`),
és csak akkor renderel, ha mindegyik jelen van — így nem fordulhat elő
félig kirajzolt blokk (pl. a checkbox látszik, de a hozzá tartozó cím-mezők
nem). Ez azért fontos, mert egy `FormView` nem létező gyerekmezőjének
közvetlen elérése (`form.valami.mégvalami`) Twig `RuntimeError`-t dob
`strict_variables` mellett — ez bármelyik olyan boltot render-hibával
állítaná le a pénztár szállítási lépésén, amelyik telepíti a plugint, de
nem replikálja pontosan ezt a négy mezőt.

**A bolt — a jelen (`codeconjure/egyhazzene-hu-bolt`) — ma ezt a négy mezőt
a saját `App\Form\Extensions\CheckoutShipmentTypeExtension`-je adja hozzá.**
Ez egy ismert, átmeneti állapot: a mezőkészlet a bolt saját cím- és
telefonszám-infrastruktúrájára épül (host-specifikus `Address` entitás,
telefonszám-validátor), amihez a plugin nem ad hidat, és amit egy másik,
nyitott issue (#99) úgyis átír (a szállítási cím a jövőben a mentett
címjegyzékből fog jönni, nem szabad szövegből). Egy másik Sylius bolt, ami
a plugint telepíti, de nem adja hozzá ezt a négy mezőt, egyszerűen nem
látja a FoxPost-mezőket a pénztárban — a checkout egyébként hibátlanul
működik.

## Az admin szállítási panel — host-szerződés a feladás-gombról

**A hostnak a `ship` hookable `props.template`-jét kell a plugin sablonjára
állítania.** A plugin `templates/admin/shipment/ship.html.twig`-je a FoxPost
szállítási módokra a csomagfeladás-gombot rajzolja (a plugin saját
`app_admin_foxpost_parcel_register` route-jára), minden más módra pedig
változatlanul a Sylius alapsablonját rendereli — így egyetlen sablon szolgálja
ki a panel mindkét esetét.

A `ship` kulcs viszont a SyliusAdminBundle-é, és a plugin `prepend()`-je egy már
betöltött bundle saját kulcsát nem tudja átállítani (ugyanaz a határ, mint a
checkout `shipment` hookable-jénél), ezért ezt a hostnak kell megtennie:

```yaml
sylius_twig_hooks:
    hooks:
        'sylius_admin.order.show.content.sections.shipments.item.actions':
            ship:
                props:
                    template: '@CodeConjureSyliusFoxPostPlugin/admin/shipment/ship.html.twig'
```

A `props` többi kulcsa (`resource`, `path`, `pathParameters`) a Sylius
alapkonfigurációjából marad meg — a részleges felülírás kulcs szerint fésül
össze.

**A plugin eltávolítása ezzel ennek az egy sornak a törlése**: a bolt sablonjai
nem ismerik sem a plugin route-ját, sem a Twig-szűrőit (#193).

A rendelés-összegző átvételi pont-sora ehhez képest **nem kér host-lépést**: azt
a plugin a `sylius_shop.shared.order.show.summary.addresses.shipping.pickup_point`
hookra ülteti, amit a Sylius nem foglal — a hostnak csak annyi a dolga, hogy a
szállítási blokkjában meghívja (`{% hook 'pickup_point' with { shipment: shipment } %}`),
szolgáltatónév nélkül.

## Verziózás

A csomag [SemVer](https://semver.org/lang/hu/) szerint verziózódik, és tagolt
kiadásokat ad — a kiadások tartalma a [CHANGELOG.md](CHANGELOG.md)-ben van. A
`0.x` soron a nyilvános API (a host-szerződés interfészei és traitjei is) még
mozoghat: `^0.1`-re érdemes constraintet írni, `dev-main`-re nem.

A **támogatott Sylius-sor `~2.1`–`~2.2`** — a `~2.3` ma nem telepíthető (#205).

## Fejlesztés

```bash
composer update
vendor/bin/phpunit
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
```

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
