ChangeLog
===

Version mars 2019
---

Création du plugin

-   Lecture des informations
-   Controle marche/arret
-   Controle de la température
-   Documentation

Fix 29 octobre 2019
---

-   Correction paramètres après sauvegarde

Fix 01 janvier 2020
---

-   Compatibilité PHP7.3

Add 31 mai 2020
---

-   Ajout du param code PIN boitier

Add 01 juin 2020
---

-   Ajout du niveau de pellet

Fix 08 juin 2020
---

-   Correction bug de variation de température affichée

Add 15 octobre 2020
---

-   Ajout de la température des gaz
-   Ajout de la valeur de puissance
-   Ajout de la valeur de ventilation
-   Ajout statistic
-   Nécessite de réenregistrer l'objet

Fix 25 mars 2023
---

-   Correction connexion avec le serveur Fumis


Version 2.0 - octobre 2026
---

Version maintenue dérivée du plugin original (compatibilité PHP 8 et nouvelles fonctions).

-   Compatibilité PHP 8 : constructeurs, clés de tableaux, accès aux propriétés
-   Correction des commandes (requête envoyée deux fois imbriquée), On / Off sans erreur
-   Lecture de l'état en GET, timeout 45 s, erreurs détaillées dans le log, PIN masqué
-   Rafraîchissement toutes les 5 minutes (au lieu de chaque minute)
-   Ajout : réglage de la puissance (1 à 5) et puissance demandée
-   Ajout : 14 états, erreurs et alertes avec libellé, codes numériques
-   Ajout : signal Wi-Fi, dernier contact, surchauffes, allumages ratés
-   Ajout : extracteur et dépression (suivi de l'encrassement)
-   Ajout : écart d'horloge, synchronisation manuelle et automatique (changement d'heure)
-   Ajout : programmation hebdomadaire (info, On / Off)
-   Suppression de l'info Fumées (non transmise par le contrôleur)
-   Création automatique des nouvelles commandes lors de la mise à jour
-   Outils : mise en page du widget, alertes ntfy, script de test

[Documentation](index.md)