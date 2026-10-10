# Változásnapló

A csomag a [SemVer](https://semver.org/lang/hu/) szerint verziózódik. A `0.x`
sor azt jelenti, hogy a nyilvános API — a host-szerződés interfészei és
traitjei is — még mozog: egy `0.y.z` → `0.(y+1).0` váltás törő változást is
tartalmazhat, a javító kiadások (`0.y.z` → `0.y.(z+1)`) viszont visszafelé
kompatibilisek.

## [Nem kiadott]

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
  route nincs lefedve (#198).

[Nem kiadott]: https://github.com/connorhu/foxpost-sylius-plugin/compare/0.1.0...main
[0.1.0]: https://github.com/connorhu/foxpost-sylius-plugin/releases/tag/0.1.0
