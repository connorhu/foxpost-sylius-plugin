# Változásnapló

A csomag a [SemVer](https://semver.org/lang/hu/) szerint verziózódik. A `0.x`
sor azt jelenti, hogy a nyilvános API — a host-szerződés interfészei és
traitjei is — még mozog: egy `0.y.z` → `0.(y+1).0` váltás törő változást is
tartalmazhat, a javító kiadások (`0.y.z` → `0.y.(z+1)`) viszont visszafelé
kompatibilisek.

## [Nem kiadott]

## [0.1.3] – 2026-10-10

Javító kiadás: a nyomkövetés-szinkron záró naplója a futás EREDMÉNYÉT írja, nem
a lekérdezés találatszámát. A host-szerződés (interfészek, traitek,
szolgáltatás-azonosítók, route-ok) változatlan; a nyilvános API csak BŐVÜL
(`FoxPostTrackingSyncService::syncActive()` mostantól visszaad egy összegzőt —
a hívót ez nem érinti, az osztály `final`), ezért a befogadó bolt `^0.1` pinje
is marad.

### Javítva

- **A nyomkövetés-szinkron záró naplója nem a feldolgozott csomagokat
  számolta** (#173): a `['count' => count($parcels)]` a repository
  találatszámát írta, miközben a ciklus három helyen `continue`-zik (nincs
  vonalkód, API-hiba, használhatatlan válasz). Egy teljesen sikertelen futás —
  minden API-hívás elbukik — pontosan ugyanazt a `count`-ot jelentette, mint
  egy teljesen sikeres, tehát a napi cron egyetlen összegző soráról nem
  lehetett megmondani, működött-e a szinkron. A sor mostantól öt számlálót
  ad: `found`, `synced`, `transitions`, `skipped`, `failed`.

### Hozzáadva

- **`FoxPostTrackingSyncSummary`** — a futás eredménye értékobjektumként; a
  `syncActive()` ezt adja vissza, és a napló is ebből kapja a kontextusát.
- **A `foxpost:tracking:sync` parancs kiírja a számlálókat.** Korábban egyetlen
  sort írt (`FoxPost tracking sync complete.`), pontosan ugyanazt egy teljesen
  sikertelen futás után is. A kilépési kód szándékosan nem változott: az a
  cron szerződése.
- **A parancs tesztfedezete** (`tests/Unit/Command/FoxPostTrackingSyncCommandTest`,
  a valódi szolgáltatással és PSR-18 mock HTTP klienssel), és a
  nyomkövetés-szinkron öt új számláló-tesztje — köztük a #173 elfogadási
  feltétele (a teljesen sikertelen futás `synced: 0`-t jelent) és a
  `found === synced + skipped + failed` szerződés. 291 → 298 teszt.
- **A README leírja a nyomkövetés-szinkront** (a parancs, a számlálók jelentése
  és hogy a cron gyakorisága a hostra tartozik) — eddig egyik dokumentum sem
  említette.

## [0.1.2] – 2026-10-10

Javító kiadás: a hat admin művelet-route a befogadó bolt admin-útvonalát
követi. A nyilvános API — a host-szerződés interfészei és traitjei, a
szolgáltatások, a route-NEVEK — változatlan.

### Javítva

- **A hat admin művelet-route bedrótozott `/admin` előtaggal képződött**
  (#209), miközben a csomaglista resource-route-ja a konfigurálható
  `/%sylius_admin.path_name%` prefixet használta. Egy olyan boltban, ami a
  `SYLIUS_ADMIN_ROUTING_PATH_NAME`-et nem az alapértelmezésen hagyja, a
  feladás, a sztornó, az átadás és a két címke-letöltés az admin tűzfalon
  KÍVÜL maradt: a bolt `access_control`-szabálya a
  `%sylius.security.admin_regex%`-re (`^/%sylius_admin.path_name%`) van kötve,
  tehát ezekre a route-okra nem illeszkedett a
  `ROLE_ADMINISTRATION_ACCESS` követelmény. Az előtag mostantól mindkét
  importon ugyanaz a paraméter; a route-nevek és az alapértelmezett
  admin-útvonalon az útvonalak sem változnak. Hordozhatósági hiba: a
  default admin-útvonalú boltokat (így az eddigi egyetlen befogadót) nem
  érintette.

### Hozzáadva

- **A route-előtag szerződése mérve** (#209):
  `tests/Unit/Admin/AdminRoutePrefixTest` a hét route útvonalát KÉT
  admin-útvonalon állítja (`admin` és egy nem alapértelmezett), őrzi a
  route-készletet és a HTTP-metódusokat, elsül egy új controller
  bedrótozott `/admin`-ján, és azt is kimondja, miért pont ez a paraméter:
  a Sylius admin tűzfal-regexe is ebből képződik. A `tests/Support/PluginRoutes`
  ezzel a valódi betöltő-láncra került (Symfony `YamlFileLoader` → Sylius
  `ResourceLoader` + FrameworkBundle `AttributeRouteControllerLoader`, a
  `%...%` feloldása a FrameworkBundle `Router`-ében), tehát az útvonalak
  byte-azonosak a bolt `debug:router`-ének kimenetével. 280 → 291 teszt.

## [0.1.1] – 2026-10-10

Javító kiadás: az admin réteg tesztfedezete (#198) és a mérés során talált
négy néma hiba javítása. A nyilvános API — a host-szerződés interfészei és
traitjei, a szolgáltatások — változatlan.

### Javítva

- **Az admin csomaglista az első valódi csomagsoron 500-as hibára futott**
  (#185): az `internalStatus` mező `type: string`-gel volt beállítva, a
  Sylius `StringFieldType` pedig `(string) $value`-vel castol, ami egy backed
  enumon fatális hiba. A mező mostantól `type: twig`, és fordított
  állapotnevet jelenít meg. Addig nem derült ki, hogy a `foxpost_parcel`
  tábla üres volt, tehát a grid soha nem rendelt sort.
- **A grid öt akció-sablonja halott kód volt** (#198): a kiszervezés
  `type: links` + `options.template` párral vette át őket, a Sylius
  akció-renderelője viszont az `options.template` kulcsot nem olvassa — a
  Sylius generikus `links` sablonjára esett vissza, ami egy üres legördülőt
  rendelt. Az akciók mostantól saját típusnévvel és a
  `sylius_grid.templates.action`/`.bulk_action` regisztrációval kötődnek a
  plugin sablonjaihoz. A sablonok a renderelő VALÓDI változóit használják
  (`data`, `action`) a nem létező `resource`/`ids` helyett, a tömeges
  címke-letöltés pedig a Sylius admin generikus tömeges kijelölését
  (`data-bulk-delete`).
- **Hiányzó állapot-fordítások** (#185): a `FoxpostParcelStatus` 13 esete
  közül a `pending_registration`, a `delivery_failed` és a `returned`
  fordítás nélkül volt, és a grid állapot-szűrőjéből is kimaradt.
- **Az admin menüpont a szállítmányok UTÁN csúszhatott** (#198): az
  átrendezés `array_filter` utáni kulcsot adott át az `array_splice`
  offszetének.

### Változott

- **Tömeges feladás nincs többé a csomaglista akciói között** (#198). A
  Sylius tömeges kijelölése a SOROK (csomagok) azonosítóit küldi, a
  `bulk_register` route viszont szállítmány-azonosítókat vár — a kettő
  összekötése más szállítmányokra adott volna fel csomagot. A route és a
  controller megmaradt; a grid-akció a paraméter-szerződés eldöntése után
  kerülhet vissza.

### Hozzáadva

- **Az admin réteg tesztfedezete** (#198): a grid mező- és
  akció-renderelése egy valódi csomagsoron (a Sylius igazi renderelőivel, a
  plugin valódi route-gyűjteményén és fordítási katalógusán), a két admin
  controller mind a hat route-ja, és a menü listener. A tesztek
  teszt-kernel nélkül futnak: 183 → 280 teszt.

## [0.1.0] – 2026-10-10

Az első kiadás. Tartalma a FoxPost integráció kiszervezése a
`praemonstratensis/egyhazzene-bolt` boltból (#116): a Sylius-réteg ebbe a
csomagba került, a protokoll-réteg a
[`codeconjure/foxpost`](https://github.com/connorhu/foxpost-lib) 0.1.0-ba.

### Hozzáadva

- **Host-szerződés** — `FoxPostShipmentInterface`/`Trait`,
  `FoxPostShippingMethodInterface`/`Trait`, `FoxPostChannelInterface`/`Trait`,
  `DeliveryKindAwareInterface`. A plugin így nem nyúl bele a host
  entitásaiba: a bolt implementálja az interfészt, és használja a traitet.
- **Üzleti logika** — `EligibilityChecker` + `EligibilityResult`,
  `FoxPostParcelPayloadFactory`, `FoxPostParcelRegistrationService`,
  `FoxPostLabelService`, `FoxPostTrackingSyncService`,
  `CachedParcelLockerProvider`.
- **Entitások és állapotgép** — `FoxpostParcel`, `FoxpostParcelStatus`,
  `FoxpostParcelRepository`, a `foxpost_parcel` Symfony workflow, és három
  migráció (`Version20260918000001`–`000003`).
- **Admin felület** — csomaggrid a hat akcióval (regisztrálás, tömeges
  regisztrálás, címke, tömeges címke, sztornó, feladás-panel), menüpont,
  `FoxPostParcelController` + `FoxPostLabelController`, a csatorna
  címke-lapmérete és a szállítási mód alapméret-mezője.
- **Bolt felület** — a pénztár FoxPost-blokkja, a csomagautomata-választó
  form type és widget, a rendelés-összegző átvevőpont-sora, és két Stimulus
  controller (`foxpost-shipping`, `foxpost-pickup-point`).
- **Parancs** — `foxpost:tracking:sync` a nyomkövetés-szinkronhoz.
- **Fordítások** — a kulcsok a plugin saját névterében (`hu` és `en`).
- **Telepítési szerződés** — a host-lépések a README-ben; a migrációs útvonalat
  és a saját route-jait a plugin maga teszi be (#194).
- Kapuk: PHPUnit (183 teszt), PHPStan level 9, ECS — GitHub Actionsön, PHP 8.4
  és 8.5 × Sylius `~2.1.0`/`~2.2.0` mátrixon (#196).

### Ismert korlátok

- **A támogatott Sylius-sor `~2.1`–`~2.2`.** A `~2.3` soron (Symfony 8 + DBAL 4)
  a csomag nem fordul: a Symfony 8 kivette az `XmlFileLoader`-t, a DBAL 4 a
  `Type::getName()`-et. Mérve és külön issue-ban: #205.
- **A pénztár FoxPost-mezőkészlete részben a boltban maradt** — a két form
  extension a `foxpostSameAsBilling`/`foxpostAddress` mezőkkel a #99 után
  kerül át (#199).
- **Az admin réteg tesztfedezete hiányos**: a grid, a két controller és a hét
  route nincs lefedve (#198). *(Megoldva a `0.1.1`-ben.)*
- **A hat művelet-route nem a bolt admin-útvonalát követi** (#209).
  *(Megoldva a `0.1.2`-ben.)*
- **A nyomkövetés-szinkron záró naplója a lekérdezés találatszámát írja**, nem
  a futás eredményét (#173). *(Megoldva a `0.1.3`-ban.)*

[Nem kiadott]: https://github.com/connorhu/foxpost-sylius-plugin/compare/0.1.3...main
[0.1.3]: https://github.com/connorhu/foxpost-sylius-plugin/compare/0.1.2...0.1.3
[0.1.2]: https://github.com/connorhu/foxpost-sylius-plugin/compare/0.1.1...0.1.2
[0.1.1]: https://github.com/connorhu/foxpost-sylius-plugin/compare/0.1.0...0.1.1
[0.1.0]: https://github.com/connorhu/foxpost-sylius-plugin/releases/tag/0.1.0
