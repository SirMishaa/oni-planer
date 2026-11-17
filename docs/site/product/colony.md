---
title: Ma colonie
description: Relier le jeu au planner avec le mod Uplink, puis suivre la viabilité alimentaire de ta colonie, la carte de tes zones et voir ce que le jeu envoie.
sources: ["app/Http/Controllers/ColonyController.php", "app/Console/Commands/UplinkInstallCommand.php", "app/Services/Viability/**", "resources/js/pages/Colony/**", "lang/fr/colony.php", "app/Http/Controllers/ZoneController.php", "app/Services/Zones/ZonePresenter.php", "resources/js/components/zones/**", "lang/fr/zones.php", "app/Services/Zones/ZonePopulation.php", "app/Services/Zones/CritterProblems.php", "app/Services/Zones/ZoneFeeding.php", "app/Services/Zones/ZoneInventory.php", "app/Services/Zones/RanchProblems.php", "app/Services/Zones/ZoneBuildingSettings.php"]
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

L'indicateur **en direct**, en haut à droite, montre le nombre de messages reçus par minute. Clique-le pour voir le détail depuis l'ouverture de la page : l'état de la connexion WebSocket, les trajets et positions de critters relayés par le mod, les mises à jour enregistrées par le planner et la durée des rechargements.

- Le sélecteur de **calque** choisit ce que la carte montre : croissance, matériaux, température, problèmes ou critters.
- **Grille** affiche ou masque le quadrillage des cases.
- La carte s'ajuste à ton écran (100 %). Les boutons **−** et **+** changent le zoom de 10 points, un clic sur le pourcentage revient à 100 %, et Ctrl (ou ⌘) avec la molette zoome sous le curseur. Glisse la carte pour la déplacer.
- Clique une plante ou un bâtiment pour le sélectionner : il se soulève, cerné de blanc, et ses détails s'affichent à côté.
- Les critters et les œufs posés au sol sont dessinés là où le jeu les a vus, avec leur image du jeu tournée dans le sens où ils regardent. Entre deux messages du jeu, chaque critter glisse à sa vitesse de l'endroit où il est dessiné jusqu'à sa nouvelle position, puis continue le chemin que le jeu a envoyé. Quand le jeu fait partir un critter, il envoie tout son trajet : la carte le suit case par case au lieu de deviner entre deux messages. Les critters bougent dès que le message du jeu arrive dans le navigateur, sans attendre que le planner l'ait enregistré ; leur fiche, leur humeur et leurs alertes suivent un instant plus tard. Il ne saute que s'il est trop loin pour le rattraper avant le message suivant. Le critter sélectionné montre par des points le chemin qu'il suit. Clique un critter pour afficher sa fiche à côté, ou un œuf pour lire son incubation, son éclosion et sa viabilité. Le calque **Critters** estompe les plantes et les bâtiments pour les faire ressortir. Les œufs rangés dans un stockage ou un incubateur ne sont pas dessinés.
- Les cases que le jeu n'a pas lues restent hachurées.
- **Comparer avec le jeu** affiche la dernière capture du jeu à côté de la carte (en dessous si la zone est plus large que haute), à la même échelle et au même zoom (voir [Captures du jeu](#captures-du-jeu)).

### Population

Le bloc **Population**, à côté de la carte, est toujours visible quand le jeu a envoyé les critters. Il montre une pièce ou un bassin par ligne, avec sa taille en cases et une jauge : les critters, les œufs à l'éclosion et la limite. Dessous, chaque espèce affiche son nombre, l'espace qu'elle prend et, s'il faut en retirer, `−N` : une seule de ces options suffit (voir [Surpeuplement](./rules/overcrowding.md)).

Quand une pièce est **À l'étroit** ou **Confinée**, le verdict de la zone annonce **Ponte arrêtée**. Le bloc affiche « Aucun critter » si la zone n'en a pas, et « Pas encore reçu » tant que le jeu n'a rien envoyé. Les états et le calcul du retrait sont détaillés dans [Surpeuplement](./rules/overcrowding.md).

### Entités et bord

L'onglet **Entités** liste les plantes et les bâtiments de la zone, ceux qui ont un problème en premier. Les bâtiments posés sur les murs, le sol ou le plafond de la zone comptent avec elle : une station de pêche ou un distributeur collé au bassin apparaît dans les entités et peut signaler un problème.

Les alertes des créatures comptent aussi parmi les problèmes, sans les noyer : un statut partagé par tout un groupe (38 pacus à l'étroit) donne **un seul** problème, une alerte propre à une créature donne un problème pour elle, et chaque pièce ou bassin surpeuplé, à l'étroit ou confiné donne un problème qui indique combien de critters retirer, une espèce au choix (« retirer 2 Pacu ou 2 Blowter »). Un clic sur un problème de créature ouvre l'onglet Entités sur la bonne bande du troupeau, et la carte épingle d'un « ! » les créatures concernées.

Les mangeoires et les points de dépôt montrent leurs réglages sous leur nom : « Filtre : Algues, Varech · 340 / 500 kg » pour une mangeoire (« Rien de coché » si rien n'est coché), « 12 / 20 critters · Pacu » pour un point de dépôt. Trois alertes viennent de ces réglages, toutes marquées « dérivé » : le stock bas ou épuisé d'un groupe, un groupe dont aucune mangeoire n'accepte la nourriture (voir [Alimentation](./rules/feeding.md)), et un point de dépôt dont la limite dépasse ce que la pièce peut accueillir : « Le point de dépôt amène jusqu'à 20 critters, la pièce en supporte 12 ». Cette dernière ne se calcule que si la zone contient une seule pièce (ou un seul bassin, pour un point de dépôt de poissons).

Les portes, échelles, poteaux de pompier et escaliers sur ce bord sont rangés à part, dans le groupe **Bord** : ils restent sur la carte, mais ne comptent ni dans les bâtiments ni dans les problèmes.

### Troupeau et œufs

Les filtres **Critters** et **Œufs** de l'onglet **Entités** montrent les animaux de la zone. Le troupeau a une bande par groupe : une espèce à un stade (adultes apprivoisés, bébés ou sauvages), le plus grand groupe en premier. Chaque critter y est une vignette, avec son image tournée dans le sens où il regarde et une mini-jauge.

- **Alertes.** Une alerte que tout le groupe porte (À l'étroit, par exemple) s'affiche une fois sur la bande, avec son nombre. Le contour rouge et le « ! » ne marquent que les alertes propres à un critter, pour qu'elles ressortent. Un critter seul dans son groupe garde ses alertes à lui.
- **Jauge.** Le menu de la bande choisit la jauge (fertilité, croissance, estomac ou âge) et trie les vignettes, la plus haute en premier. Une valeur que le jeu n'a pas envoyée donne une jauge hachurée, pas une jauge vide.
- **Fiche.** Survole une vignette pour voir sa fiche : statuts, humeur, fertilité, estomac, âge et chances du prochain œuf. Un clic la montre sur la carte, avec sa fiche à côté.

Sous les vignettes, une ligne résume le groupe : l'humeur, l'estomac médian et les âges.

Une seconde ligne dit ce que le groupe mange et produit, et combien de temps le stock de la zone tient. Par exemple pour 38 pacus au bonheur nul : « Mange : Algues 285 kg/cycle (ou Varech) · Produit : Sable pollué 143 kg/cycle, Œuf de Pacu 1,9 œuf/cycle » et la puce **Stock pour 1,6 cycle**. Si des aliments du régime ne sont cochés dans aucune mangeoire, une ligne « À cocher dans le distributeur : … » les liste. La puce est verte à partir de 3 cycles, orange en dessous, rouge sous 1 cycle ; sans aucun aliment accepté en stock, elle devient **Il manque 285 kg/cycle**. Elle est marquée « dérivé » : voir [Alimentation](./rules/feeding.md) pour le calcul.

Un groupe qui mange des plantes sur pied ou des proies n'a pas de puce : la ligne dit ce qu'il broute ou chasse, avec le nombre de ces plantes ou proies dans la zone (« Broute : Épicier (12 dans la zone) »). Un groupe qui mange les deux montre le bilan de ses objets, puis ses plantes comme alternative (« ou broute : … »).

<Accordion title="Fertilité gelée, stock, frise des œufs">
La fertilité est marquée **gelée** quand le critter vit dans une pièce ou un bassin **À l'étroit** ou **Confiné** : le jeu garde sa valeur mais il ne pond plus.

Le stock compte ce que contiennent les mangeoires de la zone, rien d'autre : ni casier de stockage, ni terrarium à algues, ni récolte mûre pas encore ramassée. Les objets posés au sol, que les critters mangent aussi, ne sont pas envoyés par le jeu. Tant que le jeu n'a pas envoyé l'état des bâtiments, la puce dit **Stock inconnu**, sans couleur. Le besoin suit le métabolisme de chaque critter : un sauvage mange le quart d'un apprivoisé, un morose bien moins. La ponte suit aussi chaque critter : un apprivoisé heureux pond plusieurs fois le rythme de base, un misérable ou un critter à l'étroit ou confiné ne pond plus. Un régime peut lister des dizaines d'aliments : la ligne en montre trois, l'aliment le plus en stock d'abord, et compte les autres.

Les œufs sont groupés par espèce et par lieu (par terre, en incubateur, en stockage). Chaque groupe a une frise qui va de maintenant à la dernière éclosion, avec un œuf posé à son temps restant. Ce temps est calculé par le planner, d'où la mention « dérivé » : incubation restante × durée d'incubation de l'espèce dans les données du jeu. Un œuf dont l'espèce est inconnue reste hors de la frise. Une puce signale les œufs dont la viabilité baisse.
</Accordion>

### Captures du jeu

L'onglet **Captures** montre la zone telle que le jeu la dessine, pour la comparer à la carte : passe d'un onglet à l'autre, l'image tombe exactement sur la carte.

- **Capturer** demande une capture au jeu. Le bouton est grisé tant que le jeu n'est pas relié ; une pause ne le bloque pas.
- Le jeu en prend aussi une tout seul après un chantier : quand le plan de la zone a changé puis n'a plus bougé pendant deux minutes de jeu, au plus une toutes les dix minutes par zone.
- Les 15 dernières captures de chaque zone sont gardées. La plus récente s'affiche en grand, les autres en miniatures ; chacune indique quand elle a été prise, au cycle près, et si elle montre le **plan actuel** de la zone ou un plan plus ancien.

<Accordion title="Quand une capture ne vient pas">

Le jeu ne dessine que l'astéroïde affiché : une zone d'un autre astéroïde est refusée, avec la raison sous le bouton. Une capture attend aussi que l'outil Zone soit fermé, pour ne pas le photographier. L'écran se fige le temps d'une ou deux images pendant la capture.

</Accordion>

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

L'inspecteur liste chaque message reçu du jeu, du plus récent au plus ancien, avec sa colonie, son cycle, sa taille et son résultat. Choisis un message pour lire son contenu, ou voir pourquoi il a été refusé. C'est la page à ouvrir quand une page de la colonie reste vide. Quand la règle de surpeuplement du planner donne un autre résultat que le jeu, une liste d'écarts (zone, région, valeur, jeu contre planner) apparaît au-dessus des messages.
