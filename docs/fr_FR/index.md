Description
===========

Plugin servant au contrôle d'un poêle à pellets équipé du boîtier Fumis WiRCU (Heta, Austroflamm...).

> **Version 2.0** : version maintenue et compatible PHP 8, dérivée du plugin original
> d'Emmanuel Deweerdt ([edeweerdt/jeedom_heta](https://github.com/edeweerdt/jeedom_heta)).

![ScanLine Green 200](../assets/images/heta-scanline-green-200.jpg) ![AUSTROFLAMM Poly 2.0](../assets/images/austroflamm-polly-2.jpg) ![Widget](../assets/images/WidgetHeta.png)

Prérequis
=========

Le poêle à pellets doit être équipé d'un boîtier WiRCU :

![Fumis WiRCU](../assets/images/WiRCU.jpg "WiRCU")

Vous aurez besoin de l'adresse MAC (majuscules, sans `:`) et du code PIN du boîtier, inscrits
dessus ou visibles dans l'application mobile :

![Mac adresse](../assets/images/HetaAppMac.jpg "App mobile Heta")

Installation
============

Depuis GitHub (Plugins > Gestion des plugins > ajout depuis une source GitHub) : utilisateur
`franck179`, dépôt `jeedom_heta`, branche `master`, identifiant logique `heta`.
La source GitHub doit être activée dans Réglages > Système > Configuration.

Après installation, activez le plugin puis ajoutez un équipement avec l'adresse MAC et le code PIN.

En cas de mise à jour depuis la version 1.x, les équipements existants sont conservés et les
nouvelles commandes sont créées automatiquement.

Fonctionnement
==============

Le plugin interroge le cloud Fumis **toutes les 5 minutes** (timeout 45 s). Le module WiRCU ne
contacte lui-même le cloud qu'environ une fois par minute : une commande peut mettre jusqu'à une
minute à être appliquée par le poêle.

Commandes
---------

| Commande | Type | Description |
|---|---|---|
| Température, Consigne, Thermostat | info / action | Température ambiante (sonde du poêle) et consigne |
| On, Off, Actif, Etat | action / info | Marche, arrêt, état détaillé (14 états) |
| Régler puissance, Puissance consigne, Puissance | action / info | Puissance maximale demandée (1 à 5) et puissance réellement appliquée |
| Pellet | info | Niveau estimé du réservoir (%) |
| Erreur, Code erreur, Alerte, Code alerte | info | Erreurs (E1xx, E2xx) et alertes (A0xx) du poêle, avec libellé |
| Maintenance, Allumages, Allumages ratés, Surchauffes, Chauffage, Secteur | info | Statistiques et heures avant entretien |
| Extracteur, Dépression | info | Vitesse de l'extracteur de fumées (tr/min) et capteur de dépression, pour le suivi de l'encrassement |
| Signal Wi-Fi, Dernier contact | info | Qualité de la liaison du module et âge des données |
| Écart horloge, Synchroniser horloge | info / action | L'horloge du poêle est resynchronisée automatiquement chaque heure si elle dérive de 3 min ou plus (changement d'heure) |
| Programmation hebdo, Programmation On / Off | info / action | Programmation horaire interne du poêle |

Remarques :

- La puissance réglée est un **plafond** : le poêle module lui-même sa puissance réelle en
  approchant de la consigne.
- La sonde d'ambiance du poêle est influencée par l'appareil (plus haute en chauffe, plus basse à
  l'arrêt) ; une sonde indépendante est préférable pour la régulation.
- Non disponibles ou instables sur certains contrôleurs, donc non intégrés : mode éco, vitesse du
  ventilateur, démarrage / arrêt différés (à remplacer par des commandes On / Off programmées).

Outils (dossier `tools/`)
=========================

- `mise-en-page-heta.php` : ordonne le widget et masque les commandes secondaires.
  `sudo -u www-data php /var/www/html/plugins/heta/tools/mise-en-page-heta.php`
- `alertes-poele.php` : code d'un bloc « Code » de scénario (programmé toutes les 5 min) qui
  envoie des notifications [ntfy](https://ntfy.sh) en cas d'erreur, d'alerte, d'entretien proche,
  de pellets bas ou de perte de connexion. Renseigner le nom du sujet ntfy en tête du code.
- `test_fumis.py` : script de test à lancer sur un PC (bibliothèque Python `fumis`) pour vérifier
  quelles fonctions acceptent un poêle donné. `pip install "fumis[cli]"` puis `python test_fumis.py`.

Compatibilité
=============

Vérifié :
-   Heta ScanLine Green 200 (plugin original)
-   AustroFlamm Poly 2.0 (plugin original, merci à Yann)
-   AustroFlamm Clou (version 2.0 : contrôleur type 20, modèle 205/10)

À vérifier :
-   Tout équipement utilisant le boîtier [Fumis WiRCU](http://www.fumis.si/en/wircu-connection-guide)

Changelog
=========
[Voir la page dédiée](changelog.md)
