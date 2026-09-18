# FoxPost Sylius Plugin

FoxPost szállítási integráció Syliushoz: csomagfeladás, címke, nyomkövetés.

## Installation

```bash
composer require codeconjure/foxpost-sylius-plugin
```

## A pénztár felülete — host-szerződés a checkout mezőkről

A plugin `sylius_shop.checkout.select_shipping.content.form.shipments.shipment`
hookja (`templates/shop/checkout/select_shipping/foxpost_fields.html.twig`)
**csak olvassa** a szállítási shipment form négy mezőjét — nem ő hozza
létre őket. A négy mezőt a hostnak kell hozzáadnia a shipment formhoz,
pontosan ezekkel a nevekkel:

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
