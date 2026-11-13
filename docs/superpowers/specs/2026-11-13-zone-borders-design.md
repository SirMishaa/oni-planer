# Bords de zone — design

Date : 2026-11-13. Chantier 2 de la feuille de route des zones (après 1 rendu de carte et 1b orchestration de la synchro).

## Intention

En mode pièce, une zone ne prend que la cavité de la pièce. Les bâtiments posés sur les murs, le sol ou le plafond (station de pêche, distributeurs, point de dépôt des poissons, plus tard le ramasseur d'œufs) restent hors zone : ils n'apparaissent ni dans le contenu, ni dans les problèmes, ni dans les entités. Dans la zone Pacu, ce sont justement les bâtiments qui travaillent pour la pièce.

Mesure sur les 4 zones en base : dans les zones faites en mode pièce, `FishingStation`, `FishFeeder` et `FishDeliveryPoint` sont tous à **1 case** de la cavité (distance de Chebyshev, diagonales comprises), et certains `FishFeeder` ont déjà 2 cases sur 3 dans la zone. Portes, `TinyDoor`, escaliers et échelles des couloirs voisins sont eux aussi à 1 case. PacuTwo (62), peinte à la main, a des bâtiments à 2 cases : ça vient du dessin, pas de la règle. On n'en fait pas un cas.

## Objectifs

- Un **anneau de bord** autour de chaque zone : les cases à distance ≤ N (Chebyshev) d'une case de la zone qui ne sont pas elles-mêmes dans la zone. N vaut **1 par défaut et se règle** (choix utilisateur : prévoir « pièce + 2 » un jour).
- Un bâtiment terminé dont **au moins une case occupée** est dans la zone ou dans l'anneau est rattaché à la zone.
- Chaque bâtiment rattaché est classé :
  - **contenu** : il compte partout (contenu, compteur de bâtiments, problèmes, entités, carte) ;
  - **bord** : portes, escaliers, échelles (et autres passages, voir la liste plus bas). Il est affiché à part comme « bord », ne compte pas dans les problèmes ni dans le compteur de bâtiments, mais reste dessiné sur la carte (choix utilisateur).
- Le classement se fait par type de bâtiment, pas par position : une porte compte comme bord même si elle est dans les cases de la zone.

## Hors objectifs

- Les cases de la zone ne changent pas. Terrain, éléments, températures, moyennes et verdict restent calculés sur les cases de la zone seules ; les murs de l'anneau ne faussent rien.
- Pas de dessin de l'anneau sur la carte.
- Plantes et critters : pas concernés ici. Les critters (chantier 4) pourront réutiliser l'anneau.
- Pas de réglage par zone ni d'interface de réglage : un seul réglage pour tout le mod.
- Pas de rattrapage pour une zone peinte trop petite (PacuTwo) : on la repeint.

## Ce qui est décidé

### Mod (`~/dev/uplink`)

- **Réglage** `zoneBorderCells` dans `uplink.json`. Entier, défaut 1, borné à [0, 3]. 0 revient au comportement actuel. Lu par `Settings.Parse` comme les autres réglages, avec une valeur hors bornes ramenée dans les bornes, et un type faux qui lève la même `FormatException` lisible que les autres champs.
- **Anneau** : une fonction pure dans `Uplink.Core` calcule les cases de l'anneau à partir des cases de la zone, de la largeur et de la hauteur de la grille et de N. Les cases hors grille sont écartées, sans faire le tour d'un bord à l'autre de la carte (x = 0 et x = largeur − 1 ne sont pas voisins). Testée en xUnit.
- **États des bâtiments** (`ZoneReaders.BuildingStates`, section du flux rapide) : parcourt les cases de la zone **puis** celles de l'anneau, avec le même dédoublonnage par `GameObject`. Une case de l'anneau qui n'est pas dans le même monde que la zone est ignorée (même règle que pour les cases peintes).
- **Layout** : le cadre (`CellBox.Around`) a aujourd'hui une marge fixe de 2. La marge devient `max(2, N + 1)`, pour que tout bâtiment de l'anneau ait au moins une case dans le cadre et soit dessiné.
- Le layout porte un nouveau champ `borderCells` (la valeur de N utilisée). Champ optionnel ajouté : la version du message ne change pas (règle de la 1b).
- Un bâtiment de l'anneau peut être rattaché à deux zones voisines (mur commun entre deux bassins) : il apparaît dans les deux. C'est voulu.

### Site (`ony-planner`)

- **Classement** côté site, par `prefabId`, dans une seule liste nommée de types « passage » : `Door`, `ManualPressureDoor`, `PressureDoor`, `TinyDoor`, `Ladder`, `LadderFast`, `FirePole`, `Stairs` (mod). Tout ce qui n'est pas dans la liste est du contenu : un bâtiment de mod inconnu compte comme contenu par défaut. La liste exacte des prefabs de portes et d'échelles du jeu est vérifiée par le constructeur dans les données importées (`buildings`).
- `ZonePresenter` sépare les bâtiments de l'état rapide en contenu et bord :
  - entités, problèmes, résumé des problèmes et compteur `buildings` : contenu seulement ;
  - nouvelle liste des entités de bord, exposée à la page de zone avec le même format d'entité, sans problèmes.
- Carte : inchangée, elle dessine déjà tous les bâtiments du layout.
- Front : l'onglet Entités affiche les entités de bord dans un groupe à part, replié ou sous les autres, libellé « Bord ». Style au choix du constructeur, dans le style existant de `ZoneEntities.vue`.
- Docs : mettre à jour les pages touchées (`development/uplink`, `product/colony`) via `larecipe:affected`.

## Décisions prises après relecture (à valider)

- **Toutes les zones ont un anneau**, qu'elles soient faites en mode pièce ou peintes : le mod ne garde pas le mode d'origine d'une zone, une zone n'est qu'un ensemble de cases.
- **Bâtiment « contenu » de la pièce voisine collé au mur** (lampe, pompe) : accepté tel quel, sans filtre. Mesure sur les 4 zones : à 1 case, il n'y a que des bâtiments des bassins et des passages. Les bâtiments étrangers (`PowerTransformerSmall`, `Refrigerator`) sont à 2 cases. Filtre à ajouter seulement si le bruit apparaît en jeu.
- **Carte** : le layout parcourt tout le cadre, pas les cases de la zone. Un cadre élargi suffit donc à dessiner les bâtiments de l'anneau. Pas de distinction visuelle sur la carte.
- **Données déjà en base** : pas de migration. Le mod renvoie l'état rapide toutes les quelques secondes, et le bord apparaît au premier envoi.
- **Réglage** : lu au chargement de `uplink.json`, comme les autres. Changer N demande de relancer le jeu.

## Laissé au constructeur

- Le nom exact de la fonction d'anneau et de ses fichiers, en suivant les conventions de `Uplink.Core/Zones`.
- Le nom du champ de la liste de bord dans `ZoneShowData`.
- Le rendu exact du groupe « Bord » dans l'onglet Entités.

## Vérification

- xUnit : anneau (N = 0, 1, 2 ; zone collée au bord de la grille ; pas de passage d'un bord à l'autre ; cases de la zone exclues de l'anneau) ; lecture du réglage (défaut, bornes, type faux).
- Pest : un état rapide avec une `FishingStation` et une `Door` → la station est une entité, compte dans le compteur et peut porter un problème ; la porte est dans la liste de bord, sans problème, hors compteur.
- Test en jeu sur PacuOne et Beakon : station de pêche, distributeurs et point de dépôt visibles dans Entités ; portes et escaliers dans « Bord ».
