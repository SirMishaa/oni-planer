---
title: Surpeuplement
description: "Ce que veulent dire Surpeuplé, À l'étroit et Confiné pour une pièce ou un bassin, quand les œufs comptent et comment le planner calcule combien de critters retirer."
sources:
  - app/Services/GameRules/Critters/Overcrowding.php
  - app/Services/Zones/ZonePopulation.php
  - app/Services/Zones/RemovalAdvice.php
  - app/Services/Critters/OccupancyInputBuilder.php
---

# Surpeuplement

Le bloc **Population** d'une zone dit, pour chaque pièce ou bassin, si ses critters sont trop nombreux, et combien en retirer pour relancer la ponte.

Les trois états viennent du jeu. Le planner ne les invente pas : il les lit dans ce que le mod Uplink envoie, et ne calcule que le nombre de critters à retirer.

| État | Ce qu'il veut dire |
| --- | --- |
| Surpeuplé | Les critters occupent plus de cases que la pièce n'en a. |
| À l'étroit | Il y a encore de la place, mais les œufs, une fois éclos, la dépasseront. La ponte s'arrête. |
| Confiné | La pièce est plus petite que l'espace qu'un seul critter de cette espèce demande. La ponte s'arrête. |

Quand une pièce est **Confinée**, seul cet état s'affiche : dans le jeu, il remplace les deux autres.

## Retirer combien

Sous la jauge, chaque espèce affiche `−N` : le nombre de critters à retirer de cette espèce seule pour sortir de l'état. Ces lignes sont des alternatives, pas une somme : **une seule** suffit, comme l'indique l'en-tête « Une de ces options suffit ». Le problème de la zone le dit de la même façon : « retirer 2 Pacu ou 2 Blowter ».

Une espèce trop peu nombreuse pour régler le problème seule est grisée et marquée « ne suffit pas ». Si aucune espèce seule ne suffit, l'en-tête le dit et donne le nombre de cases à libérer : il faut alors retirer des critters de plusieurs espèces.

Le marqueur **dérivé** signale un chiffre calculé par le planner (voir plus bas).

<Accordion title="Quand les œufs comptent">

Les critters occupent chacun un nombre de cases propre à leur espèce (leur espace personnel, lu dans les données du jeu). Les œufs ne prennent de place qu'à l'éclosion : ils comptent pour l'espace du critter qu'ils deviendront.

La pièce est **À l'étroit** quand les critters, plus les œufs à venir, dépassent ses cases, alors que les critters seuls y tiennent encore. Le planner prend la place des œufs telle que le jeu la compte, car la liste des œufs arrive plus lentement que le reste de l'état.

Dans un bassin, seuls les poissons comptent ; dans une pièce, les poissons sont ignorés.

</Accordion>

<Accordion title="Comment le retrait est calculé">

Pour une espèce, le nombre à retirer est le dépassement divisé par son espace personnel, arrondi à l'entier supérieur, sans dépasser le nombre de critters présents. Le dépassement est :

- **Surpeuplé** : les cases des critters moins celles de la pièce. Le jeu calcule ce chiffre lui-même, il n'est pas marqué dérivé.
- **À l'étroit** : les cases des critters plus celles des œufs, moins celles de la pièce. Le jeu ne le calcule pas : il est marqué **dérivé**.

Si retirer cette espèce seule ne suffit pas, la ligne montre tout de même le maximum possible, marqué « ne suffit pas ». Les cases à libérer sont le dépassement lui-même.

Le calcul compte tous les critters de la pièce ou du bassin, y compris ceux qui se trouvent hors de la zone.

</Accordion>

<Accordion title="Jeu ou dérivé">

Les états, les cases occupées et la place des œufs sont ceux du jeu. Le planner recalcule la règle de son côté et compare à chaque envoi : un écart apparaît dans l'inspecteur Uplink (voir [Ma colonie](../colony.md)). Seul le nombre de critters à retirer en cas d'étroitesse est dérivé.

Si une espèce de la pièce n'est pas dans les données du jeu importées, le planner ne propose aucun retrait pour cette pièce.

</Accordion>
