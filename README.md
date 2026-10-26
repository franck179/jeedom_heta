# Plugin Jeedom Heta (Fumis WiRCU) — version maintenue

Plugin Jeedom de pilotage des poêles à pellets équipés du boîtier **Fumis WiRCU**
(Heta, Austroflamm et autres marques utilisant les contrôleurs Fumis).

Ce dépôt est une **version dérivée** du plugin original d'**Emmanuel Deweerdt**
([edeweerdt/jeedom_heta](https://github.com/edeweerdt/jeedom_heta)), dont la dernière mise à jour
date de mars 2023 et qui ne fonctionne plus sous PHP 8. Merci à lui pour le travail de départ.

## Apports de la version 2.0

- Compatibilité **PHP 8** et correction des commandes
- Réglage de la **puissance** (1 à 5)
- **Erreurs et alertes** du poêle avec libellé en français
- **Synchronisation automatique de l'horloge** du poêle (changements d'heure)
- Programmation hebdomadaire, diagnostic (extracteur, dépression, Wi-Fi, dernier contact)
- Rafraîchissement toutes les 5 minutes, gestion robuste des coupures du cloud Fumis
- Outils : mise en page du widget, alertes [ntfy](https://ntfy.sh), script de test

Détail : [documentation](docs/fr_FR/index.md) et [changelog](docs/fr_FR/changelog.md).

## Installation

Jeedom > Réglages > Système > Configuration : activer la source **GitHub**.
Puis Plugins > Gestion des plugins > ajout depuis une autre source :

| Champ | Valeur |
|---|---|
| ID logique du plugin | `heta` |
| Type de source | GitHub |
| Utilisateur ou organisation | `franck179` |
| Nom du dépôt | `jeedom_heta` |
| Branche | `master` |

L'identifiant reste `heta` : installé par-dessus la version du Market, il **conserve les
équipements, commandes, historiques et scénarios existants**.

## Licence

Code distribué sous la licence du projet d'origine (voir [LICENSE](LICENSE) ; `info.json` indique
AGPL, les en-têtes des fichiers la GPL v3 ou ultérieure). Les modifications sont distribuées sous
les mêmes conditions.

Fonctionnement non garanti ; non affilié à Fumis, Heta ou Austroflamm.
