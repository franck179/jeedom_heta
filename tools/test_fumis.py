#!/usr/bin/env python3
"""
test_fumis.py - Vérifie quelles fonctions de la bibliothèque « fumis »
fonctionnent avec votre poêle (Austroflamm Clou + module WiRCU).

Utilisation (PowerShell) :
    cd $HOME
    .\\fumis-test\\Scripts\\Activate.ps1
    python test_fumis.py

MAC et PIN : variables d'environnement FUMIS_MAC / FUMIS_PASSWORD,
sinon le script les demande.

Déroulement :
  1. Lectures seules (aucun risque) : tout ce que le poêle remonte.
  2. Actions, une par une, chacune après votre accord (o/N, q pour arrêter).
     Chaque réglage modifié est remis à sa valeur d'origine.
  3. Un rapport texte + la réponse brute JSON sont enregistrés dans le
     dossier courant : envoyez-les pour préparer le patch du plugin.

Le module WiRCU ne contacte le cloud qu'environ une fois par minute :
chaque action peut mettre jusqu'à 1-2 minutes à se confirmer.
"""
import asyncio
import getpass
import json
import os
import sys
from datetime import datetime, timedelta

try:
    from fumis import Fumis, FumisError, StoveAlert, StoveError
except ImportError:
    sys.exit("Bibliothèque fumis introuvable : activez d'abord le venv\n"
             "    .\\fumis-test\\Scripts\\Activate.ps1")

POLL = 10        # secondes entre deux lectures
WAIT_MAX = 150   # secondes maximum pour voir un changement

ETATS = {
    0: "Arrêt", 1: "Arrêt (après démarrage à froid)", 2: "Arrêt (bûches)",
    10: "Préchauffage", 20: "Allumage", 21: "Pré-combustion", 30: "Combustion",
    40: "Éco", 50: "Refroidissement", 60: "Hybride : init", 80: "Hybride : démarrage",
    90: "Démarrage bûches", 100: "Démarrage à froid", 110: "Combustion bûches",
}
ETATS_ARRET = (0, 1, 2)

# ----------------------------------------------------------------- rapport
lignes = []      # journal complet
resultats = []   # (fonction, statut, détail)


def log(texte=""):
    print(texte)
    lignes.append(texte)


def resultat(nom, statut, detail=""):
    resultats.append((nom, statut, detail))
    log(f"  => [{statut}] {nom}" + (f" : {detail}" if detail else ""))


def titre(texte):
    log("")
    log("=" * 70)
    log(texte)
    log("=" * 70)


# ----------------------------------------------------------- lecture brute
def ctrl(raw):
    return raw.get("controller", {}) or {}


def var(raw, vid):
    for v in ctrl(raw).get("diagnostic", {}).get("variables", []) or []:
        if v.get("id") == vid:
            return v.get("value")
    return None


def sonde(raw, sid):
    for t in ctrl(raw).get("temperatures", []) or []:
        if t.get("id") == sid:
            return t
    return None


def ventilo(raw, fid=1):
    for f in ctrl(raw).get("fans", []) or []:
        if f.get("id") == fid:
            return f
    return None


def horloge_poele(raw):
    """Horloge interne du contrôleur (variables 23 à 29)."""
    vals = [var(raw, i) for i in range(23, 30)]
    if None in vals:
        return None
    sec, minute, heure, _jour_sem, jour, mois, annee = vals
    try:
        return datetime(2000 + annee, mois, jour, heure, minute, sec)
    except ValueError:
        return None


def ecart_horloge(raw):
    h = horloge_poele(raw)
    if h is None:
        return None
    return (h - datetime.now()).total_seconds()


def ts(valeur):
    if valeur in (None, -1):
        return "aucun"
    return datetime.fromtimestamp(valeur).strftime("%d/%m/%Y %H:%M")


# ------------------------------------------------------------ interactions
class Arret(Exception):
    pass


def demander(question):
    while True:
        rep = input(f"\n{question} [o/N/q] ").strip().lower()
        if rep in ("o", "oui", "y"):
            return True
        if rep in ("", "n", "non"):
            return False
        if rep == "q":
            raise Arret()


async def lire(f):
    return await f.raw_status()


async def attendre(f, condition, libelle):
    """Relit l'état jusqu'à ce que condition(raw) soit vraie."""
    debut = asyncio.get_running_loop().time()
    while True:
        ecoule = asyncio.get_running_loop().time() - debut
        try:
            raw = await lire(f)
            if condition(raw):
                print(" " * 70, end="\r")
                log(f"     confirmé après {ecoule:.0f} s")
                return raw
        except FumisError as e:
            log(f"     (lecture impossible : {e})")
        if ecoule >= WAIT_MAX:
            print(" " * 70, end="\r")
            log(f"     pas de confirmation après {WAIT_MAX} s ({libelle})")
            return None
        print(f"     en attente ({libelle})... {ecoule:.0f} s", end="\r")
        await asyncio.sleep(POLL)


async def envoyer(coro):
    try:
        await coro
        return None
    except FumisError as e:
        return str(e)


# ---------------------------------------------------------- 1. lectures
def lectures(raw):
    c = ctrl(raw)
    u = raw.get("unit", {}) or {}

    titre("1. LECTURES (aucune modification)")

    log(f"Module WiRCU      : firmware {u.get('version')}, IP {u.get('ip')}")
    rssi = int(u.get("rssi", -100))
    log(f"Signal Wi-Fi      : {rssi} dBm (~{max(0, min(100, 2 * (rssi + 100)))} %)")
    log(f"Température module: {u.get('temperature')} °C")
    log(f"Contrôleur        : type {c.get('type')}, version {c.get('version')}, "
        f"modèle var96={var(raw, 96)} / var97={var(raw, 97)}")
    age = (c.get("currentTime") or 0) - (c.get("stoveLastAvailability") or 0)
    log(f"Dernier contact   : il y a {age} s (normal : 0 à 60 s)")
    resultat("Dernier contact module", "OK" if c.get("stoveLastAvailability") else "ABSENT", f"{age} s")

    st = c.get("status")
    log(f"\nÉtat brut         : {st} -> {ETATS.get(st, 'INCONNU de la bibliothèque')}")
    resultat("États complets", "OK" if st in ETATS else "À COMPLÉTER", f"code {st}")

    err, alr = c.get("error", 0), c.get("alert", 0)
    e = StoveError.from_code(err) if err is not None else None
    a = StoveAlert.from_code(alr) if alr is not None else None
    log(f"Erreur            : {err} -> {e.value + ' ' + e.name if e else 'aucune'}")
    log(f"Alerte            : {alr} -> {a.value + ' ' + a.name if a else 'aucune'}")
    resultat("Erreur / alerte", "OK" if "error" in c and "alert" in c else "ABSENT",
             f"erreur={err}, alerte={alr}")

    t1, t7 = sonde(raw, 1), sonde(raw, 7)
    log(f"\nSonde 1 (ambiante): {t1.get('actual') if t1 else '-'} °C, consigne {t1.get('set') if t1 else '-'} °C")
    log(f"Sonde 7 (foyer)   : {t7.get('actual') if t7 else 'absente'}")
    log(f"Var 11 (fumées)   : {var(raw, 11)}")
    log("  (poêle éteint : 0 est normal ; à relancer poêle en marche pour savoir laquelle bouge)")
    resultat("Température fumées (var 11)", "PRÉSENT" if var(raw, 11) is not None else "ABSENT", str(var(raw, 11)))

    v33 = var(raw, 33)
    log(f"Var 33 (porte)    : {v33} -> {'fermée' if v33 == 1 else 'ouverte' if v33 == 0 else '?'}")
    log(f"Var 35 (pression) : {var(raw, 35)}   Var 4 (ventilo 1) : {var(raw, 4)}")

    p = c.get("power", {}) or {}
    log(f"\nPuissance         : demandée {p.get('setPower')}, réelle {p.get('actualPower')}, {p.get('kw')} kW")
    eco = c.get("ecoMode")
    log(f"Mode éco          : {eco.get('ecoModeEnable') if eco else 'non supporté'}")
    fan = ventilo(raw)
    log(f"Ventilateur 1     : vitesse {fan.get('speed') if fan else 'absent'}")
    log(f"Programmation hebdo activée : {c.get('timerEnable')}")
    log(f"Démarrage différé : {ts(c.get('delayedStartAt'))}   Arrêt différé : {ts(c.get('delayedStopAt'))}")
    af = c.get("antifreeze") or {}
    log(f"Hors-gel          : {af.get('enable')} à {af.get('temperature')} °C")

    s = c.get("statistic", {}) or {}
    log(f"\nStatistiques      : {s.get('igniterStarts')} allumages, {s.get('misfires')} allumages ratés, "
        f"{s.get('overheatings')} surchauffes, {round((s.get('heatingTime') or 0) / 3600)} h de chauffe, "
        f"{c.get('timeToService')} h avant entretien")

    h = horloge_poele(raw)
    d = ecart_horloge(raw)
    if h is None:
        log("\nHorloge du poêle  : illisible")
        resultat("Lecture horloge", "ABSENT")
    else:
        log(f"\nHorloge du poêle  : {h:%d/%m/%Y %H:%M:%S}  (PC : {datetime.now():%d/%m/%Y %H:%M:%S})")
        log(f"Écart             : {d / 60:+.0f} min")
        resultat("Lecture horloge", "OK", f"écart {d / 60:+.0f} min")


# ---------------------------------------------------------- 2. actions
async def actions(f, raw, a_restaurer):
    titre("2. ACTIONS (une par une, avec votre accord)")
    c = ctrl(raw)
    log("On / Off et consigne de température ne sont pas retestés (déjà validés via Jeedom).")

    # --- 2.1 Horloge
    log("\n--- Synchroniser l'horloge du poêle sur celle du PC")
    d = ecart_horloge(raw)
    log(f"Écart actuel : {d / 60:+.0f} min" if d is not None else "Écart actuel : inconnu")
    if demander("Synchroniser l'horloge ? (sans risque, corrige le changement d'heure)"):
        err = await envoyer(f.set_clock())
        if err:
            resultat("Synchroniser horloge", "ÉCHEC", err)
        else:
            r = await attendre(f, lambda x: (ecart_horloge(x) is not None and abs(ecart_horloge(x)) < 120),
                               "horloge à l'heure")
            resultat("Synchroniser horloge", "OK" if r else "NON CONFIRMÉ",
                     f"nouvel écart {ecart_horloge(r) / 60:+.0f} min" if r else "")
    else:
        resultat("Synchroniser horloge", "NON TESTÉ")

    # --- 2.2 Mode éco
    log("\n--- Mode éco (activé puis remis à l'état d'origine)")
    eco = c.get("ecoMode")
    if not eco or eco.get("ecoModeEnable") is None:
        resultat("Mode éco", "NON SUPPORTÉ", "absent de la réponse")
    elif demander(f"Basculer le mode éco (actuellement {eco['ecoModeEnable']}) puis le remettre ?"):
        orig = bool(eco["ecoModeEnable"])
        a_restaurer["mode éco"] = lambda: f.set_eco_mode(enabled=orig)
        err = await envoyer(f.set_eco_mode(enabled=not orig))
        r = None if err else await attendre(
            f, lambda x: bool((ctrl(x).get("ecoMode") or {}).get("ecoModeEnable")) == (not orig), "éco basculé")
        await envoyer(f.set_eco_mode(enabled=orig))
        await attendre(f, lambda x: bool((ctrl(x).get("ecoMode") or {}).get("ecoModeEnable")) == orig, "éco restauré")
        a_restaurer.pop("mode éco", None)
        resultat("Mode éco", "OK" if r else "ÉCHEC" if err else "NON CONFIRMÉ", err or "")
    else:
        resultat("Mode éco", "NON TESTÉ")

    # --- 2.3 Puissance
    log("\n--- Puissance (changée puis remise)")
    p = c.get("power", {}) or {}
    orig = p.get("setPower")
    if orig is None:
        resultat("Puissance", "NON SUPPORTÉ")
    elif demander(f"Changer la puissance demandée ({orig}) puis la remettre ?"):
        cible = 2 if orig != 2 else 3
        a_restaurer["puissance"] = lambda: f.set_power(orig)
        err = await envoyer(f.set_power(cible))
        r = None if err else await attendre(f, lambda x: (ctrl(x).get("power") or {}).get("setPower") == cible,
                                            f"puissance à {cible}")
        await envoyer(f.set_power(orig))
        await attendre(f, lambda x: (ctrl(x).get("power") or {}).get("setPower") == orig, "puissance restaurée")
        a_restaurer.pop("puissance", None)
        resultat("Puissance", "OK" if r else "ÉCHEC" if err else "NON CONFIRMÉ", err or f"{orig} -> {cible} -> {orig}")
    else:
        resultat("Puissance", "NON TESTÉ")

    # --- 2.4 Ventilateur
    log("\n--- Vitesse du ventilateur (changée puis remise)")
    fan = ventilo(raw)
    if not fan:
        resultat("Ventilateur", "NON SUPPORTÉ", "aucun ventilateur déclaré")
    elif demander(f"Changer la vitesse du ventilateur ({fan.get('speed')}) puis la remettre ?"):
        orig = fan.get("speed", 0)
        cible = 1 if orig != 1 else 2
        a_restaurer["ventilateur"] = lambda: f.set_fan_speed(orig)
        err = await envoyer(f.set_fan_speed(cible))
        r = None if err else await attendre(f, lambda x: (ventilo(x) or {}).get("speed") == cible,
                                            f"ventilateur à {cible}")
        await envoyer(f.set_fan_speed(orig))
        await attendre(f, lambda x: (ventilo(x) or {}).get("speed") == orig, "ventilateur restauré")
        a_restaurer.pop("ventilateur", None)
        resultat("Ventilateur", "OK" if r else "ÉCHEC" if err else "NON CONFIRMÉ",
                 err or ("" if r else "peut être ignoré poêle éteint"))
    else:
        resultat("Ventilateur", "NON TESTÉ")

    # --- 2.5 Arrêt différé (sans risque)
    log("\n--- Arrêt différé (programmé dans 2 jours puis annulé)")
    if demander("Tester l'arrêt différé ? (sans risque : il ne fait qu'arrêter)"):
        cible = datetime.now().astimezone() + timedelta(days=2)
        a_restaurer["arrêt différé"] = lambda: f.set_delayed_stop(None)
        err = await envoyer(f.set_delayed_stop(cible))
        r = None if err else await attendre(
            f, lambda x: abs((ctrl(x).get("delayedStopAt") or -1) - int(cible.timestamp())) < 120, "arrêt programmé")
        await envoyer(f.set_delayed_stop(None))
        r2 = await attendre(f, lambda x: ctrl(x).get("delayedStopAt") in (-1, None), "arrêt annulé")
        if r2:
            a_restaurer.pop("arrêt différé", None)
        resultat("Arrêt différé", "OK" if (r and r2) else "ÉCHEC" if err else "NON CONFIRMÉ", err or "")
    else:
        resultat("Arrêt différé", "NON TESTÉ")

    # --- 2.6 Démarrage différé (annulé tout de suite)
    log("\n--- Démarrage différé (programmé dans 2 jours puis annulé aussitôt)")
    if demander("Tester le démarrage différé ? (annulé immédiatement, revérifié en fin de script)"):
        cible = datetime.now().astimezone() + timedelta(days=2)
        a_restaurer["démarrage différé"] = lambda: f.set_delayed_start(None)
        err = await envoyer(f.set_delayed_start(cible))
        r = None if err else await attendre(
            f, lambda x: abs((ctrl(x).get("delayedStartAt") or -1) - int(cible.timestamp())) < 120,
            "démarrage programmé")
        await envoyer(f.set_delayed_start(None))
        r2 = await attendre(f, lambda x: ctrl(x).get("delayedStartAt") in (-1, None), "démarrage annulé")
        if r2:
            a_restaurer.pop("démarrage différé", None)
        resultat("Démarrage différé", "OK" if (r and r2) else "ÉCHEC" if err else "NON CONFIRMÉ", err or "")
    else:
        resultat("Démarrage différé", "NON TESTÉ")

    # --- 2.7 Programmation hebdomadaire
    log("\n--- Programmation hebdomadaire (basculée puis remise)")
    orig = c.get("timerEnable")
    if orig is None:
        resultat("Programmation hebdo", "NON SUPPORTÉ")
    else:
        if not orig:
            log("ATTENTION : elle est désactivée. L'activer, même brièvement, peut ALLUMER le poêle\n"
                "si l'heure actuelle tombe dans un créneau programmé.")
        if demander(f"Basculer la programmation hebdo ({orig}) puis la remettre ?"):
            a_restaurer["programmation"] = lambda: f.set_timer(enabled=orig)
            err = await envoyer(f.set_timer(enabled=not orig))
            r = None if err else await attendre(f, lambda x: ctrl(x).get("timerEnable") == (not orig),
                                                "programmation basculée")
            await envoyer(f.set_timer(enabled=orig))
            await attendre(f, lambda x: ctrl(x).get("timerEnable") == orig, "programmation restaurée")
            a_restaurer.pop("programmation", None)
            resultat("Programmation hebdo", "OK" if r else "ÉCHEC" if err else "NON CONFIRMÉ", err or "")
        else:
            resultat("Programmation hebdo", "NON TESTÉ")

    # --- 2.8 Porte (manuel)
    log("\n--- Détection de la porte du foyer (test manuel)")
    if c.get("status") not in ETATS_ARRET:
        resultat("Capteur de porte", "NON TESTÉ", "uniquement poêle éteint et froid")
    elif demander("Tester le capteur de porte ? (poêle ÉTEINT et FROID uniquement)"):
        v0 = var(await lire(f), 33)
        input("Ouvrez la porte du foyer, puis appuyez sur Entrée...")
        r = await attendre(f, lambda x: var(x, 33) != v0, "changement var 33")
        v1 = var(r, 33) if r else None
        input("Refermez la porte, puis appuyez sur Entrée...")
        await attendre(f, lambda x: var(x, 33) == v0, "retour var 33")
        resultat("Capteur de porte", "OK" if r else "NON CONFIRMÉ", f"var 33 : fermée={v0}, ouverte={v1}")
    else:
        resultat("Capteur de porte", "NON TESTÉ")


# ---------------------------------------------------------------- main
async def main():
    mac = os.environ.get("FUMIS_MAC") or input("MAC du module (majuscules, sans ':') : ").strip().upper()
    pin = os.environ.get("FUMIS_PASSWORD") or getpass.getpass("PIN du module (masqué) : ").strip()
    horodatage = datetime.now().strftime("%Y%m%d-%H%M")
    log(f"Test fumis du {datetime.now():%d/%m/%Y %H:%M} - module {mac}")

    a_restaurer = {}
    async with Fumis(mac=mac, password=pin, request_timeout=60) as f:
        try:
            raw = await lire(f)
        except FumisError as e:
            sys.exit(f"Impossible de lire l'état du poêle : {e}")
        with open(f"fumis-avant-{horodatage}.json", "w", encoding="utf-8") as fh:
            json.dump(raw, fh, indent=2, ensure_ascii=False)

        lectures(raw)
        try:
            await actions(f, raw, a_restaurer)
        except Arret:
            log("\nTests interrompus à votre demande.")
        finally:
            if a_restaurer:
                titre("REMISE EN ÉTAT")
                for nom, fn in list(a_restaurer.items()):
                    err = await envoyer(fn())
                    log(f"  {nom} : {'remis' if not err else 'ÉCHEC ' + err}")
            # Contrôle final de sécurité
            try:
                fin = await lire(f)
                ds = ctrl(fin).get("delayedStartAt")
                if ds not in (-1, None):
                    log(f"\n!!! Un démarrage différé est encore programmé ({ts(ds)}) : annulation.")
                    await envoyer(f.set_delayed_start(None))
                with open(f"fumis-apres-{horodatage}.json", "w", encoding="utf-8") as fh:
                    json.dump(fin, fh, indent=2, ensure_ascii=False)
            except FumisError as e:
                log(f"\nContrôle final impossible ({e}) : vérifiez l'appli officielle.")

    titre("RÉSUMÉ")
    for nom, statut, detail in resultats:
        log(f"  {statut:<14} {nom}" + (f"  ({detail})" if detail else ""))

    nom_rapport = f"rapport-fumis-{horodatage}.txt"
    with open(nom_rapport, "w", encoding="utf-8") as fh:
        fh.write("\n".join(lignes).replace(pin, "****") + "\n")
    print(f"\nRapport : {os.path.abspath(nom_rapport)}")
    print(f"Réponses brutes : fumis-avant-{horodatage}.json / fumis-apres-{horodatage}.json")


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        print("\nInterrompu (Ctrl+C). Vérifiez l'état du poêle dans l'appli officielle.")
