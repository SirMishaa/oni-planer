---
title: Alimentation
description: "Comment le planner calcule ce qu'un groupe de critters mange et pond par cycle, et combien de cycles le stock de nourriture acceptée tient."
sources:
  - app/Services/GameRules/Critters/CritterFeeding.php
  - app/Services/Critters/CritterDietRepository.php
  - app/Services/Critters/EloquentCritterDietRepository.php
  - app/Services/GameImport/OniExport/CritterReader.php
  - app/Data/GameRules/ResourceFlowData.php
  - app/Data/GameRules/AcceptedFoodData.php
  - app/Data/GameRules/InventoryItemData.php
  - app/Data/GameRules/FeedingBalanceData.php
  - app/Services/Zones/ZoneInventory.php
  - app/Services/Zones/ZoneFeeding.php
  - app/Data/ZoneFeedingData.php
---

# Alimentation

Pour un groupe de critters (une espèce à un stade donné), le planner calcule ce que le groupe mange par cycle, ce qu'il produit, et combien de cycles le stock de nourriture qu'il accepte peut tenir.

Exemple : un Pacu mange 7,5 kg d'algues par cycle. 38 Pacus en demandent 285 kg. Avec 456 kg d'algues en stock, le groupe tient **1,6 cycle**.

Ces chiffres sont calculés par le planner à partir des données du jeu, pas lus dans la partie : le bilan d'un groupe (stock, autonomie, déficit) est marqué **dérivé**.

## Nourriture acceptée

Chaque espèce accepte plusieurs aliments. Pour chacun, le planner donne la quantité par cycle si cet aliment nourrit seul tout le groupe, et ce qu'il rejette en échange (par exemple du sable pollué pour le Pacu). Ces lignes sont des alternatives, pas une somme : un critter mange l'un ou l'autre.

Un aliment se mange de l'une de trois façons :

| Façon | Exemple | Se stocke |
| --- | --- | --- |
| Un objet ramassé | Algues pour le Pacu, sable pour le Hatch | Oui, en kg |
| Une plante mangée sur pied | Drecko, Squirrel | Non |
| Une proie chassée | Pacu pour le Pacu préhistorique | Non |

## Autonomie

Seuls les objets ramassés comptent dans le stock ; une plante ou une proie, et tout matériau que le groupe ne mange pas, ne comptent pas. L'aliment **principal** est l'objet accepté dont le stock est le plus grand.

Chaque aliment en stock nourrit le groupe pendant son stock divisé par son besoin par cycle ; l'autonomie est la somme de ces durées. Sans aucun objet accepté en stock, le besoin par cycle du premier objet accepté est affiché comme un **déficit**.

Un groupe qui ne mange que des plantes sur pied ou des proies n'a ni autonomie ni déficit : sa nourriture ne se compte pas en stock.

## Le stock d'une zone

Dans une zone, le stock est ce que contiennent ses bâtiments (mangeoires, stockages ; les passages du bord ne comptent pas), plus la récolte de chaque plante mûre (son rendement dans le jeu ; 0 tant qu'elle pousse). Le bilan se calcule pour chaque bande du troupeau, une espèce à un stade : voir [Ma colonie](../colony.md). Tant que le jeu n'a pas envoyé l'état des bâtiments, le stock est inconnu : pas d'autonomie ni de déficit plutôt qu'un faux manque.

Pour un groupe qui broute ou chasse, le planner compte à la place les plantes ou les critters de cette espèce présents dans la zone.

<Accordion title="Comment les chiffres sont calculés">

- **Besoin d'un aliment** : la quantité qu'un critter en mange par cycle (ses calories par cycle divisées par les calories par kg de l'aliment), multipliée par le nombre de critters. Elle est en kg pour un objet ramassé ; pour une plante, c'est de la croissance de plante, et pour une proie une quantité propre au jeu, jamais des kg.
- **Rejet** : la quantité mangée multipliée par le taux de conversion du régime. Le rejet est toujours en kg, même pour une plante ou une proie.
- **Production** : chaque sortie du critter (ses œufs) par cycle, multipliée par le nombre de critters. Les œufs se comptent en unités, pas en kg.
- **Autonomie** : la somme, sur chaque objet accepté, de son stock divisé par son besoin par cycle. Un stock mélangé reste exact : 560 kg de sable et 7 kg de viande pour 2 Hatchs (280 et 0,875 kg par cycle) tiennent 2 + 8 = 10 cycles. Un aliment au besoin nul ne compte pas et ne peut pas être l'aliment principal.
- **Égalité** : si deux objets ont le même stock, l'aliment principal est celui qui vient en premier dans le régime du jeu.

</Accordion>
