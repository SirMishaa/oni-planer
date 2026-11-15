---
title: Alimentation
description: "Comment le planner calcule ce qu'un groupe de critters mange et pond par cycle, et combien de cycles le stock de nourriture acceptée tient."
sources:
  - app/Services/GameRules/Critters/CritterFeeding.php
  - app/Services/Critters/EloquentCritterDietRepository.php
  - app/Data/GameRules/ResourceFlowData.php
  - app/Data/GameRules/FeedingBalanceData.php
order: 2
---

# Alimentation

Pour un groupe de critters (une espèce à un stade donné), le planner calcule ce que le groupe mange par cycle, ce qu'il produit, et combien de cycles le stock de nourriture qu'il accepte peut tenir.

Exemple : un Pacu mange 7,5 kg d'algues par cycle. 38 Pacus en demandent 285 kg. Avec 456 kg d'algues en stock, le groupe tient **1,6 cycle**.

Tous ces chiffres sont calculés par le planner à partir des données du jeu : ils sont marqués **dérivé**.

## Nourriture acceptée

Chaque espèce accepte plusieurs aliments. Pour chacun, le planner donne la quantité par cycle si cet aliment nourrit seul tout le groupe, et ce qu'il rejette en échange (par exemple du sable pollué pour le Pacu). Ces lignes sont des alternatives, pas une somme : un critter mange l'un ou l'autre.

## Autonomie

Le stock compte tous les aliments acceptés présents dans l'inventaire ; un autre matériau, même stocké au même endroit, ne compte pas. L'aliment **principal** est celui dont le stock est le plus grand.

Chaque aliment en stock nourrit le groupe pendant son stock divisé par son besoin par cycle ; l'autonomie est la somme de ces durées. Sans aucun aliment accepté en stock, le besoin par cycle du premier aliment accepté est affiché comme un **déficit**.

<Accordion title="Comment les chiffres sont calculés">

- **Besoin d'un aliment** : la quantité qu'un critter en mange par cycle (ses calories par cycle divisées par les calories par kg de l'aliment), multipliée par le nombre de critters.
- **Rejet** : la quantité mangée multipliée par le taux de conversion du régime (kg rejetés par kg mangé).
- **Production** : chaque sortie du critter (ses œufs) par cycle, multipliée par le nombre de critters. Les œufs se comptent en unités, pas en kg.
- **Autonomie** : la somme, sur chaque aliment accepté, de son stock divisé par son besoin par cycle. Un stock mélangé reste exact : 560 kg de sable et 7 kg de viande pour 2 Hatchs (280 et 0,875 kg par cycle) tiennent 2 + 8 = 10 cycles. Un aliment au besoin nul ne compte pas.

</Accordion>
