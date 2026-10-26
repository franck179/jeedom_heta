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
| Régler puissance, Puissance consigne, Puissance | action / info | Puissance maximale demandée (1 à 5) et allure réellement appliquée (1 à 5) |
| Puissance (kW) | info | Puissance de chauffe instantanée annoncée par le contrôleur, ramenée à 0 poêle éteint (historisée) |
| Pellet | info | Niveau estimé du réservoir (%) |
| Erreur, Code erreur, Alerte, Code alerte | info | Erreurs (E1xx, E2xx) et alertes (A0xx) du poêle, avec libellé |
| Maintenance, Allumages, Allumages ratés, Surchauffes, Chauffage, Secteur | info | Statistiques et heures avant entretien |
| Extracteur, Dépression | info | Vitesse de l'extracteur de fumées (tr/min) et capteur de dépression, pour le suivi de l'encrassement |
| Signal Wi-Fi, Dernier contact | info | Qualité de la liaison du module et âge des données |
| Écart horloge, Synchroniser horloge | info / action | L'horloge du poêle est resynchronisée automatiquement chaque heure si elle dérive de 3 min ou plus (changement d'heure) |
| Programmation hebdo, Programmation On / Off | info / action | Programmation horaire interne du poêle |

Remarques :

- La puissance réglée est un **plafond** : le poêle module lui-même son allure en approchant de
  la consigne. *Puissance (kW)* suit cette modulation plus finement que l'allure (par paliers).
- Estimation de la consommation : l'énergie (moyenne de *Puissance (kW)* × durée de chauffe,
  en kWh) divisée par ~4,8 kWh/kg donne les kilos de pellets brûlés (ordre de grandeur).
- La sonde d'ambiance du poêle est influencée par l'appareil (plus haute en chauffe, plus basse à
  l'arrêt) ; une sonde indépendante est préférable pour la régulation.
- Non disponibles ou instables sur certains contrôleurs, donc non intégrés : mode éco, vitesse du
  ventilateur, démarrage / arrêt différés (à remplacer par des commandes On / Off programmées).

Affichage
=========

Le plugin crée les infos principales en widget **tuile** (valeur sous l'étiquette) et le curseur
**Thermostat** au pas de **0,2 °C** (comme l'application officielle).

L'outil `tools/mise-en-page-heta.php` applique la mise en page conseillée aux équipements
existants (ordre, commandes visibles, tuiles, pas du thermostat) :

    sudo -u www-data php /var/www/html/plugins/heta/tools/mise-en-page-heta.php

Disposition conseillée (configuration de l'équipement, disposition en tableau 4 × 3) :

| | | |
|---|---|---|
| Etat | On | Off |
| Température | Thermostat | Consigne |
| Pellet | Régler puissance | Puissance |
| Erreur | Alerte | |

**Police réduite pour Etat, Erreur et Alerte** : l'outil affiche à la fin le CSS à coller dans
Réglages > Système > Personnalisation avancée (case *Activer* cochée, partie CSS bureau), puis
recharger avec Ctrl+F5. Exemple :

```css
/* Poêle : Etat, Erreur, Alerte en plus petit */
.cmd[data-cmd_id="3423"] .state,  /* etat */
.cmd[data-cmd_id="3470"] .state,  /* erreur */
.cmd[data-cmd_id="3472"] .state   /* alerte */ {
    font-size: 1.2em !important;
}
```

Les identifiants (`data-cmd_id`) changent si l'équipement est recréé : relancer l'outil pour
obtenir les nouveaux. Si la taille ne change pas, inspecter la valeur dans le navigateur
(clic droit > Inspecter) pour vérifier sa classe CSS.

Outils (dossier `tools/`)
=========================

- `mise-en-page-heta.php` : mise en page du widget (voir « Affichage »).
- `alertes-poele.php` : code d'un bloc « Code » de scénario (programmé `*/5 * * * *`) qui
  envoie des notifications [ntfy](https://ntfy.sh) en cas d'erreur (priorité urgente), d'alerte,
  d'entretien proche (< 50 h), de pellets bas (< 15 %) ou de perte de connexion (> 30 min).
  Chaque alerte n'est envoyée qu'une fois, puis un message de retour à la normale.
  Renseigner le nom du sujet ntfy en tête du code (nom long et non devinable) ; passer `$TEST`
  à `true` pour un essai. Le scénario mémorise son état dans des variables `alertePoele_…`.
- `test_fumis.py` : script de test à lancer sur un PC pour vérifier quelles fonctions un poêle
  accepte, sans passer par Jeedom (voir « Dépannage »).

Remise en place après une réinstallation de Jeedom
==================================================

1. Activer la source GitHub et installer le plugin (voir « Installation »), l'activer.
2. Recréer l'équipement (MAC, PIN), le sauvegarder ; vérifier le log `heta` (`"valid":true`).
3. Lancer `tools/mise-en-page-heta.php`, coller le CSS affiché dans la personnalisation avancée.
4. Recréer le scénario d'alertes ntfy à partir de `tools/alertes-poele.php`.
5. Revoir les scénarios et le plugin Thermostat qui utilisent les commandes du poêle
   (les identifiants des commandes ont changé).

Dépannage
=========

Mettre le log du plugin en **Debug** (configuration du plugin). Chaque lecture produit une ligne
`Fumis request` (PIN masqué) puis `Status result`.

| Symptôme | Cause probable | Action |
|---|---|---|
| `Fumis Error : HTTP/1.1 401` | MAC ou PIN non transmis / erronés | Vérifier `username` dans la ligne `Fumis request` ; MAC en majuscules sans `:` |
| `Fumis Error : HTTP/1.1 404` | Module WiRCU non connecté au cloud | Tester l'application officielle en 4G ; Wi-Fi 2,4 GHz |
| `HTTP request failed!` sans code, au bout du timeout, en alternance avec des succès | Cloud Fumis lent ou module qui remonte mal ses données | En général passager ; le plugin garde les dernières valeurs et reprend seul (voir ci-dessous) |
| État « Inconnu (n) » | Code d'état absent de la table | Compléter `const ETAT` dans `core/php/heta.inc.php` |
| Erreur « Exxx code inconnu » | Code absent de la table | Compléter `const ERREURS` |
| Écart d'horloge qui ne se corrige pas | Tâche horaire non exécutée ou commande refusée | Moteur de tâches : vérifier `heta::cronHourly` ; action *Synchroniser horloge* |
| Le poêle s'allume « tout seul » | Programmation hebdomadaire interne active | Action *Programmation Off* |
| Foyer toujours à 0 | Température non transmise par certains contrôleurs | Normal (info masquée) |
| `Could not open input file` en lançant un outil copié dans le dossier personnel | `www-data` n'a pas accès au dossier personnel | Lancer l'outil depuis `/var/www/html/plugins/heta/tools/` ou depuis `/tmp` |

**Erreurs intermittentes** : diagnostic à lancer pendant l'épisode, en SSH sur la box :

```bash
# Le cloud répond-il, et en combien de temps ? (attendre jusqu'à 90 s)
for i in 1 2 3 4 5; do curl -4 -s -o /dev/null -m 90 -w "HTTP %{http_code} en %{time_total}s\n" -H "username: VOTREMAC" -H "password: VOTREPIN" https://api.fumis.si/v1/status/; sleep 20; done

# Depuis combien de temps le module n'a-t-il pas contacté le cloud ? (normal : 0 à 60 s)
curl -4 -s -m 30 -H "username: VOTREMAC" -H "password: VOTREPIN" https://api.fumis.si/v1/status/ \
 | python3 -c "import json,sys; c=json.load(sys.stdin)['controller']; print('Dernier contact module il y a', c['currentTime']-c['stoveLastAvailability'], 's')"

# Le module est-il stable sur le Wi-Fi local ? (IP : champ unit.ip de la réponse)
ping -i 2 IP_DU_MODULE | while read l; do echo "$(date +%T) $l"; done
```

| Résultat | Interprétation | Action |
|---|---|---|
| `200` lents (20 à 60 s) | Le cloud attend le prochain contact du module | Monter `TIMEOUT` dans `heta.inc.php` |
| `000` après 90 s | Panne ou surcharge côté Fumis | Attendre |
| Dernier contact > 60 s et croissant | Le module ne joint plus le cloud | Redémarrer le module WiRCU |
| Pertes au ping ou pics > 300 ms fréquents | Wi-Fi faible | IP réservée, SSID 2,4 GHz dédié, canal fixe 1/6/11, répéteur |

**Test sans Jeedom (depuis un PC)** : la bibliothèque Python `fumis` permet de vérifier que le
cloud et le poêle répondent, indépendamment du plugin.

```powershell
python -m venv fumis-test
.\fumis-test\Scripts\Activate.ps1      # sortir avec : deactivate
pip install "fumis[cli]"
fumis info --mac VOTREMAC --password VOTREPIN
python test_fumis.py                   # test complet des fonctions, avec rapport
```

`test_fumis.py` lit tout ce que remonte le poêle puis propose chaque action une par une (avec
remise à l'état d'origine) et produit un rapport. Lancé poêle en marche en répondant « non »
à toutes les actions, il sert aussi à repérer les valeurs qui varient en fonctionnement.

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
