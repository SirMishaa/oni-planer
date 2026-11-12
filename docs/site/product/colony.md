---
title: Ma colonie
description: Relier le jeu au planner avec le mod Uplink, puis suivre la viabilité alimentaire de ta colonie et voir ce que le jeu envoie.
sources: ["app/Http/Controllers/ColonyController.php", "app/Console/Commands/UplinkInstallCommand.php", "app/Services/Viability/**", "resources/js/pages/Colony/**", "lang/fr/colony.php"]
order: 6
---

# Ma colonie

Les pages **Ma colonie** suivent la partie en cours. Elles ont besoin du mod **Uplink**, qui envoie l'état de ta colonie au planner pendant que tu joues.

## Relier le jeu

<Steps>
<Step title="Installer le mod">
Compile et installe le mod Uplink, puis active-le dans le menu des mods du jeu.
</Step>
<Step title="Le relier au planner">
Depuis le dossier du planner, lance `php artisan uplink:install`. La commande écrit les réglages du mod (où envoyer les données) dans son dossier.
</Step>
<Step title="Charger une sauvegarde">
Charge une sauvegarde dans le jeu. Les pages de la colonie se remplissent en quelques secondes.
</Step>
</Steps>

Le haut de chaque page indique si le jeu est **en direct**, quand il a été vu pour la dernière fois et le cycle en cours.

## Viabilité : nourriture

La page de viabilité répond à une question : **tes duplicants vont-ils manquer de nourriture ?**

- Le verdict dit si la nourriture est **critique**, **juste** ou **tient**, et à quel cycle elle s'épuise au rythme actuel.
- Le graphique projette ton stock sur les prochains cycles : sans récolte, avec les récoltes automatiques de tes fermes, et avec les plantes sauvages en plus.
- **Récoltes à venir** liste chaque source de nourriture, avec une marque pour chaque récolte au cycle où elle mûrit.

Si le jeu n'a pas pu lire quelque chose (duplicants, stock de nourriture ou plantes), la page dit ce qui manque au lieu de deviner.

## Inspecteur Uplink

L'inspecteur liste chaque message reçu du jeu, du plus récent au plus ancien, avec sa colonie, son cycle, sa taille et son résultat. Choisis un message pour lire son contenu, ou voir pourquoi il a été refusé. C'est la page à ouvrir quand une page de la colonie reste vide.
