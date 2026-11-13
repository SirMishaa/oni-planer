---
title: Ma colonie
description: Relier le jeu au planner avec le mod Uplink, puis suivre la viabilité alimentaire de ta colonie, la carte de tes zones et voir ce que le jeu envoie.
sources: ["app/Http/Controllers/ColonyController.php", "app/Console/Commands/UplinkInstallCommand.php", "app/Services/Viability/**", "resources/js/pages/Colony/**", "lang/fr/colony.php", "app/Http/Controllers/ZoneController.php", "app/Services/Zones/ZonePresenter.php", "resources/js/components/zones/**", "lang/fr/zones.php"]
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

## Carte d'une zone

Ouvre une zone (<Route name="zones.index" />) pour voir sa carte, dessinée comme dans le jeu : le terrain porte ses textures et ses bords de biome, les liquides coulent et les gaz ondulent. Passe la souris sur une case pour lire son élément, sa masse et sa température.

- Le sélecteur de **calque** choisit ce que la carte montre : croissance, matériaux, température ou problèmes.
- **Grille** affiche ou masque le quadrillage des cases.
- Les cases que le jeu n'a pas lues restent hachurées.

### Calque température

Quatre modes colorent la carte avec les teintes de la surcouche température du jeu. Une légende accompagne chaque mode.

| Mode | Ce qu'il montre |
| --- | --- |
| Absolue | La température de chaque case sur une échelle fixe. |
| Adaptative | La même couleur, mais l'échelle s'ajuste aux températures présentes dans la zone. |
| Flux thermique | Si la case refroidit, reste stable ou chauffe depuis la lecture précédente. Le rythme se mesure en temps réel : la vitesse du jeu et les pauses le faussent. |
| Changement d'état | Les cases proches de geler ou de se condenser, ou de fondre ou de bouillir. |

Le flux thermique a besoin de deux lectures du terrain : tant que le jeu n'a envoyé que la première, la carte l'indique.

Si les couleurs de la surcouche manquent (fichier `render.json` absent), le mode Changement d'état reste coloré ; les autres modes laissent les cases connues sur le fond de la carte et une note demande de relancer l'export du jeu.

<Accordion title="Si l'animation ne s'affiche pas">

Les liquides et les gaz sont animés avec WebGL2. Si ton navigateur ne le gère pas, ou si le contexte graphique est perdu, ils restent dessinés à plat : la carte reste lisible, sans mouvement. Le calque température ne dessine pas les fluides animés.

Les textures viennent de l'export du jeu. Après une mise à jour du jeu ou du mod, relance **Export Game Data** dans le jeu, puis l'import (voir [Import des données du jeu](../development/game-data-import.md)).

</Accordion>

## Inspecteur Uplink

L'inspecteur liste chaque message reçu du jeu, du plus récent au plus ancien, avec sa colonie, son cycle, sa taille et son résultat. Choisis un message pour lire son contenu, ou voir pourquoi il a été refusé. C'est la page à ouvrir quand une page de la colonie reste vide.
