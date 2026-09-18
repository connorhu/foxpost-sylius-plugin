# FoxPost Sylius Plugin

FoxPost szállítási integráció Syliushoz: csomagfeladás, címke, nyomkövetés.

## Installation

```bash
composer require codeconjure/foxpost-sylius-plugin
```

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

## License

MIT
