<?php
// Fichier de référence : contenu à coller dans un bloc « Code » de scénario (sans cette première ligne <?php).
// Voir docs/fr_FR/index.md, section « Alertes ntfy ».
return;
// =====================================================================
//  Alertes du poêle -> notifications ntfy
//  Bloc « Code » d'un scénario Jeedom programmé toutes les 5 minutes.
//  Chaque alerte n'est envoyée qu'une fois (pas de répétition toutes les
//  5 minutes) ; un message de retour à la normale est envoyé ensuite.
// =====================================================================

// ---------- Réglages ----------
$NTFY_SUJET        = 'poele-franck-CHANGEZ-MOI';   // le même nom que dans l'appli ntfy
$NTFY_SERVEUR      = 'https://ntfy.sh';
$SEUIL_MAINTENANCE = 50;    // heures avant entretien
$SEUIL_PELLETS     = 15;    // % (niveau estimé par le poêle)
$SILENCE_MAX       = 30;    // minutes sans nouvelles du poêle avant alerte
$TEST              = false; // true = envoie une notification de test à chaque exécution

// ---------- Envoi vers ntfy (JSON : accents et apostrophes sans souci) ----------
$envoyer = function ($titre, $message, $priorite = 3, $icones = array()) use ($NTFY_SUJET, $NTFY_SERVEUR, $scenario) {
    $corps = json_encode(array(
        'topic'    => $NTFY_SUJET,
        'title'    => $titre,
        'message'  => $message,
        'priority' => $priorite,      // 1 (min) à 5 (urgent : sonne même en silencieux)
        'tags'     => $icones,
    ), JSON_UNESCAPED_UNICODE);
    $ctx = stream_context_create(array('http' => array(
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\n",
        'content'       => $corps,
        'timeout'       => 15,
        'ignore_errors' => true,
    )));
    @file_get_contents($NTFY_SERVEUR, false, $ctx);
    $statut = isset($http_response_header[0]) ? $http_response_header[0] : 'pas de réponse';
    $scenario->setLog('ntfy [' . $titre . '] ' . $message . ' -> ' . $statut);
    return strpos($statut, ' 200') !== false;
};

if ($TEST) {
    $envoyer('Test Jeedom', 'Les notifications du poêle fonctionnent.', 3, array('white_check_mark'));
}

// ---------- Équipement poêle (repéré par type : insensible aux renommages) ----------
$eqs = eqLogic::byType('heta', true);
if (count($eqs) == 0) {
    $scenario->setLog('Aucun équipement heta actif');
    return;
}
$poele = reset($eqs);
$nom   = $poele->getName();
$lire  = function ($logicalId) use ($poele) {
    $cmd = $poele->getCmd(null, $logicalId);
    return is_object($cmd) ? $cmd->execCmd() : null;
};
$memo  = function ($cle, $valeur = null) use ($scenario) {
    if ($valeur === null) {
        return $scenario->getData('alertePoele_' . $cle);
    }
    $scenario->setData('alertePoele_' . $cle, $valeur);
};

// ---------- 1. Erreur du poêle ----------
$code  = (int) $lire('erreurCode');
$avant = (int) $memo('erreur');
if ($code > 0 && $code != $avant) {
    if ($envoyer("$nom : ERREUR", $lire('erreur') . ". Le poêle est en défaut, vérifiez-le.", 5, array('rotating_light'))) {
        $memo('erreur', $code);
    }
} elseif ($code == 0 && $avant > 0) {
    $envoyer("$nom : erreur terminée", "Plus d'erreur active sur le poêle.", 2, array('white_check_mark'));
    $memo('erreur', 0);
}

// ---------- 2. Alerte du poêle (A001 pellets bas, A002 entretien, A007 air...) ----------
$code  = (int) $lire('alerteCode');
$avant = (int) $memo('alerte');
if ($code > 0 && $code != $avant) {
    if ($envoyer("$nom : alerte", $lire('alerte') . '.', 4, array('warning'))) {
        $memo('alerte', $code);
    }
} elseif ($code == 0 && $avant > 0) {
    $envoyer("$nom : alerte terminée", "Plus d'alerte active sur le poêle.", 2, array('white_check_mark'));
    $memo('alerte', 0);
}

// ---------- 3. Entretien proche ----------
$heures = $lire('timeToService');
if (is_numeric($heures)) {
    if ($heures <= $SEUIL_MAINTENANCE && !$memo('maintenance')) {
        if ($envoyer("$nom : entretien à prévoir", "Plus que $heures h de fonctionnement avant l'entretien. Pensez à prendre rendez-vous (ramonage).", 3, array('wrench'))) {
            $memo('maintenance', 1);
        }
    } elseif ($heures > $SEUIL_MAINTENANCE + 10 && $memo('maintenance')) {
        $memo('maintenance', 0);   // compteur remis à zéro après l'entretien
    }
}

// ---------- 4. Pellets bas (niveau estimé par le poêle) ----------
$pellets = $lire('pellet');
if (is_numeric($pellets)) {
    if ($pellets <= $SEUIL_PELLETS && !$memo('pellets')) {
        if ($envoyer("$nom : pellets bas", "Niveau estimé : $pellets %. Pensez à remplir le réservoir (et à le déclarer dans l'appli).", 3, array('fuelpump'))) {
            $memo('pellets', 1);
        }
    } elseif ($pellets >= $SEUIL_PELLETS + 15 && $memo('pellets')) {
        $memo('pellets', 0);   // réservoir rempli
    }
}

// ---------- 5. Plus de nouvelles du poêle (cloud Fumis, Wi-Fi, module) ----------
// « Dernier contact » change à chaque lecture réussie : sa date de collecte = dernière lecture réussie.
$cmdContact = $poele->getCmd(null, 'dernierContact');
if (is_object($cmdContact) && $cmdContact->getCollectDate() != '') {
    $ageLecture = round((time() - strtotime($cmdContact->getCollectDate())) / 60);
    $ageModule  = round(((int) $cmdContact->execCmd()) / 60);   // module -> cloud
    $silence    = max($ageLecture, $ageModule);
    if ($silence > $SILENCE_MAX && !$memo('silence')) {
        $cause = ($ageLecture > $SILENCE_MAX) ? 'Jeedom ne parvient plus à lire le cloud Fumis' : "le module WiRCU ne contacte plus le cloud";
        if ($envoyer("$nom : plus de nouvelles", "Aucune donnée depuis $silence min : $cause. Vérifiez l'appli officielle et le Wi-Fi.", 3, array('satellite'))) {
            $memo('silence', 1);
        }
    } elseif ($silence <= 10 && $memo('silence')) {
        $envoyer("$nom : connexion rétablie", 'Le poêle répond de nouveau.', 2, array('white_check_mark'));
        $memo('silence', 0);
    }
}
