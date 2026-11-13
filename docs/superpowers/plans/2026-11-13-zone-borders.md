# Bords de zone Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** les bâtiments posés sur le bord d'une zone (anneau de N cases) comptent dans la zone ; portes, escaliers et échelles sont affichés à part comme « bord ».

**Architecture:** le mod calcule l'anneau (fonction pure dans `Uplink.Core`) et lit les états des bâtiments sur zone + anneau ; le cadre du layout s'élargit à `max(2, N + 1)`. Le site classe chaque bâtiment de l'état rapide en contenu ou bord par `prefabId` dans `ZonePresenter`, et l'onglet Entités affiche un groupe « Bord ».

**Tech Stack:** C# netstandard2.0 / net48 + xUnit net8.0 (`~/dev/uplink`), Laravel 13 / Pest / Inertia Vue (`~/dev/ony-planner`).

**Spec:** `docs/superpowers/specs/2026-11-13-zone-borders-design.md`

## Global Constraints

- `zoneBorderCells` : entier, défaut 1, borné à [0, 3] ; 0 = comportement actuel.
- Distance de Chebyshev (diagonales comprises) ; pas de passage d'un bord de la grille à l'autre.
- Les cases de la zone ne changent pas : terrain, températures, verdict sur les cases de la zone seules.
- Champ de layout ajouté `borderCells` optionnel : version des messages inchangée.
- Liste « passage » (bord) : `Door`, `ManualPressureDoor`, `PressureDoor`, `BunkerDoor`, `InsulatedDoor`, `GlassDoorSimple`, `GlassDoorComplex`, `WoodenDoor`, `AIO_FacilityDoor`, `AIO_WoodenDoor`, `TinyDoor`, `Ladder`, `LadderFast`, `AIO_MetalLadder`, `FirePole`, `Stairs`. Tout le reste est contenu (`LadderBed` est un lit : contenu).
- Commits sans GPG (`git -c commit.gpgsign=false commit`), sans `Co-Authored-By`. `bun`/`bunx` seulement. Pint sur les PHP modifiés. Docs en revue `required`.

## Review Focus

1. Zone collée au bord gauche/droit de la grille : l'anneau ne déborde pas sur la ligne voisine (test Task 1).
2. Anneau sur une case d'un autre monde (astéroïde voisin en Spaced Out) : bâtiment ignoré (garde `Grid.WorldIdx` en Task 3, vérif en jeu).
3. Porte dans les cases mêmes de la zone : reste bord (test Task 4).
4. Bâtiment de bord avec un statut de problème (porte bloquée) : ne compte ni dans les problèmes ni dans le résumé de la liste des zones (test Task 4).
5. `uplink.json` sans `zoneBorderCells`, ou avec une chaîne : défaut 1, ou `FormatException` lisible (test Task 2).

---

### Task 1: Anneau de bord (mod, Core)

**Files:**
- Create: `~/dev/uplink/Uplink.Core/Zones/ZoneBorder.cs`
- Test: `~/dev/uplink/Uplink.Core.Tests/ZoneBorderTests.cs`

**Interfaces:**
- Produces: `public static class ZoneBorder { public static List<int> Cells(IEnumerable<int> zoneCells, int gridWidth, int gridHeight, int width) }` — cases à distance de Chebyshev 1..width d'une case de la zone, hors zone, dans la grille, triées croissantes, sans doublon ; `width <= 0` → liste vide.

- [ ] **Step 1: tests xUnit** `ZoneBorderTests` :
  - `Width1_AroundOneCell_GivesItsEightNeighbours` : grille 10×10, case (5,5) → 8 cases (4..6 × 4..6 sauf (5,5)).
  - `Width2_AroundOneCell_Gives24Cells`.
  - `Width0_GivesNothing`.
  - `ExcludesZoneCells` : zone (5,5),(6,5) → aucune case de la zone dans le résultat ; 10 cases.
  - `NeverWrapsAcrossTheGridEdge` : case (0,5) grille 10×10 width 1 → 5 cases, aucune avec x = 9.
  - `ClampsToTheGrid` : case (0,0) → 3 cases.
- [ ] **Step 2:** `dotnet test Uplink.Core.Tests --filter ZoneBorderTests` → FAIL (type absent).
- [ ] **Step 3:** implémenter `ZoneBorder.Cells` (HashSet de la zone, parcours du carré autour de chaque case, `SortedSet` en sortie).
- [ ] **Step 4:** même commande → PASS 6/6.
- [ ] **Step 5:** commit `feat: zone border ring`.

### Task 2: Réglage `zoneBorderCells` (mod, Core)

**Files:**
- Modify: `~/dev/uplink/Uplink.Core/Settings.cs`
- Test: `~/dev/uplink/Uplink.Core.Tests/SettingsTests.cs`

**Interfaces:**
- Produces: `Settings.ZoneBorderCells` (int), constantes `DefaultZoneBorderCells = 1`, `MinZoneBorderCells = 0`, `MaxZoneBorderCells = 3`.

- [ ] **Step 1: tests** : absent → 1 ; `2` → 2 ; `9` → 3 ; `-1` → 0 ; `"deux"` → `FormatException` dont le message cite `zoneBorderCells` ; `1.6` → arrondi ou refus, au choix, mais cohérent avec `ReadSeconds` (ledger la décision).
- [ ] **Step 2:** `dotnet test Uplink.Core.Tests --filter SettingsTests` → FAIL.
- [ ] **Step 3:** helper `ReadCells(JObject root, string name, int defaultValue, int min, int max)` sur le modèle de `ReadSeconds`.
- [ ] **Step 4:** PASS.
- [ ] **Step 5:** commit `feat: zoneBorderCells setting`.

### Task 3: Lecture des bâtiments sur l'anneau + layout (mod)

**Files:**
- Modify: `Uplink.Core/Zones/ZoneSection.cs` (`ZoneReadContext`), `Uplink.Core/Zones/ZoneMessages.cs` (`Layout`), `Uplink/Zones/ZoneReaders.cs` (`BuildingStates`), `Uplink/Zones/ZoneSections.cs`, `Uplink/Zones/ZoneSampler.cs`, `Uplink/Mod.cs`
- Test: `Uplink.Core.Tests/ZoneMessagesTests.cs`, `Uplink.Core.Tests/ZoneSectionComposerTests.cs`

**Interfaces:**
- Consumes: `ZoneBorder.Cells`, `Settings.ZoneBorderCells`.
- Produces:
  - `ZoneReadContext` : nouveau paramètre optionnel en fin de constructeur `IReadOnlyList<int> borderCells = null`, propriété `BorderCells` (jamais null, vide par défaut).
  - `ZoneMessages.Layout(..., IReadOnlyList<ZoneSectionValue> sections, int? borderCells = null)` : écrit `"borderCells": N` juste après `"box"` quand non null ; rien sinon (golden strings existants inchangés).
  - `ZoneReaders.BuildingStates(Zone zone, IReadOnlyList<int> borderCells, Action<string, Exception> skip)` : cases de la zone puis de l'anneau, dédoublonnage par `GameObject` ; une case de l'anneau avec `Grid.WorldIdx[cell] != zone.WorldId` est ignorée.
  - `ZoneSampler.Begin(float fastPeriodSeconds, float slowPeriodSeconds, int borderCells)` ; marge du cadre `Math.Max(BoxMargin, borderCells + 1)` pour layout et slow ; anneau calculé dans `SendFast` (grille : `Grid.WidthInCells`/`Grid.HeightInCells`) et passé au contexte.

- [ ] **Step 1: tests** : `Layout_WritesBorderCellsAfterBox` (N = 1 → JSON contient `"box":{...},"borderCells":1`) ; `Layout_OmitsBorderCellsWhenNull` (golden existant toujours égal) ; `ReadContext_BorderCellsDefaultsToEmpty`.
- [ ] **Step 2:** `dotnet test Uplink.Core.Tests` → FAIL sur les nouveaux tests.
- [ ] **Step 3:** implémenter Core (contexte, `Layout`).
- [ ] **Step 4:** `dotnet test Uplink.Core.Tests` → tout vert.
- [ ] **Step 5:** implémenter la partie jeu (`BuildingStates`, `ZoneSections`, `ZoneSampler`, `Mod.cs`) ; build du mod jeu (`dotnet build Uplink` comme d'habitude) → 0 erreur.
- [ ] **Step 6:** commit `feat: read zone building states on the border ring`.

### Task 4: Classement contenu / bord (site)

**Files:**
- Modify: `app/Services/Zones/ZonePresenter.php`, `app/Data/ZoneShowData.php`
- Test: `tests/Unit/Services/ZonePresenterTest.php`

**Interfaces:**
- Produces:
  - `ZonePresenter::PASSAGE_PREFABS` (`private const`, liste des Global Constraints).
  - `ZoneShowData::$borderEntities` (`list<ZoneEntityData>`, placé après `$problems`) : entités des bâtiments passage de l'état rapide, `problems` toujours `[]`, dans l'ordre reçu.
  - `entities`, `problems`, `counts->buildings`, `problemSummary()` : bâtiments contenu seulement.

- [ ] **Step 1: tests Pest** dans `ZonePresenterTest` :
  - `it('counts a fishing station on the border as zone content')` : état rapide avec `FishingStation` portant un statut de problème + `Door` → la station est dans `entities` et `problems`, `counts->buildings === 1`, la porte est seule dans `borderEntities`.
  - `it('keeps passages out of the problems even with a problem status')` : `Door` et `Stairs` avec un statut d'arrêt → `problems === []`, `borderEntities` a 2 entités sans problème, `problemSummary()['count'] === 0`.
  - `it('treats an unknown modded building as content')` : prefab `SomeModThing` → dans `entities`.
- [ ] **Step 2:** `php artisan test --compact tests/Unit/Services/ZonePresenterTest.php` → FAIL.
- [ ] **Step 3:** implémenter (séparer `$fastBuildings` en deux listes avant de construire les entités ; même séparation dans `problemSummary`).
- [ ] **Step 4:** même commande → PASS ; `vendor/bin/pint --dirty --format agent`.
- [ ] **Step 5:** commit `feat: present passages on the zone border apart`.

### Task 5: Groupe « Bord » dans l'onglet Entités (site, front)

**Files:**
- Modify: `resources/js/pages/Zones/Show.vue`, `resources/js/components/zones/ZoneEntities.vue`, `lang/fr/zones.php`, `lang/en/zones.php`, types TS générés (`php artisan typescript:transform` si c'est la convention du projet, sinon le fichier de types existant)
- Test: `tests/Feature/ZoneTranslationsTest.php` (doit rester vert), `bun run lint`/`vue-tsc` selon les scripts du projet

**Interfaces:**
- Consumes: `ZoneShowData['borderEntities']`.
- Produces: prop `borderEntities: ZoneEntityData[]` sur `ZoneEntities` ; groupe `border` affiché en dernier dans les filtres `all` et `buildings`, non compté dans le filtre `problems` ; clés `zones.entities.groups.border` = « Bord » / « Border ».

- [ ] **Step 1:** ajouter la prop, le groupe, les traductions ; `Show.vue` passe la prop (et l'inclut dans le rechargement partiel live, liste `only` lignes ~43-47).
- [ ] **Step 2:** `php artisan test --compact tests/Feature/ZoneTranslationsTest.php tests/Feature/Zones` et `bunx vue-tsc --noEmit` (ou le script de type-check du projet) → vert.
- [ ] **Step 3:** commit `feat: border group in zone entities`.

### Task 6: Docs + déploiement + vérif en jeu

- [ ] **Step 1:** `php artisan larecipe:affected --uncommitted --base=main` ; mettre à jour `development/uplink` (anneau, réglage, `borderCells`) et `product/colony` (groupe Bord) avec le skill `larecipe-update-docs` ; `php artisan larecipe:validate`. Montrer le diff à l'utilisateur avant commit (revue `required`).
- [ ] **Step 2:** suites complètes : `php artisan test --compact` et `dotnet test` (Uplink.Core.Tests) → vert.
- [ ] **Step 3:** installer le mod (`php artisan uplink:install` ou la commande habituelle) ; demander à l'utilisateur de relancer le jeu ; vérifier en base que l'état rapide de PacuOne (61) et Beakon (64) contient `FishingStation`, `FishFeeder`, `FishDeliveryPoint` et que la page de zone les montre en entités, portes/escaliers en « Bord ».
