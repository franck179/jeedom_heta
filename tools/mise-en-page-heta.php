<?php
/*
 * mise-en-page-heta.php - Met en forme le widget du poêle (plugin heta).
 * À lancer (facultatif) après l'installation du plugin ou une réinstallation de Jeedom :
 *     sudo -u www-data php /var/www/html/plugins/heta/tools/mise-en-page-heta.php
 *
 * - ordre et visibilité des commandes (les autres restent utilisables dans les scénarios)
 * - widget « tuile » pour les infos affichées, pas de 0,2 °C pour le thermostat
 * - affiche le CSS à coller dans Réglages > Système > Personnalisation avancée
 *   (Etat, Erreur, Alerte en police réduite)
 * Ne touche ni aux noms, ni aux icônes, ni à l'historique (rien d'irréversible).
 */
require_once __DIR__ . '/../../../core/php/core.inc.php';

// Commandes affichées, dans cet ordre (disposition conseillée : tableau 4 lignes x 3 colonnes)
$visibles = array(
    'etat', 'on', 'off',                        // ligne 1
    'temperature', 'thermostat', 'consigne',    // ligne 2
    'pellet', 'setPuissance', 'puissance',      // ligne 3
    'erreur', 'alerte',                         // ligne 4
    'refresh',
);

// Commandes masquées, dans cet ordre
$masquees = array(
    'actif', 'puissanceConsigne', 'puissanceKw', 'extracteur', 'depression', 'foyer', 'ventilation',
    'timeToService', 'allumage', 'tChauffe', 'secteur', 'surchauffes', 'allumagesRates',
    'signalWifi', 'dernierContact', 'ecartHorloge', 'syncHorloge',
    'timerActif', 'timerOn', 'timerOff',
    'erreurCode', 'alerteCode', 'etatId', 'data',
);

// Infos affichées en tuile (valeur sous l'étiquette)
$tuiles = array('etat', 'temperature', 'consigne', 'pellet', 'puissance', 'erreur', 'alerte');

// Infos en police réduite (via le CSS personnalisé)
$petites = array('etat' => 'etat', 'erreur' => 'erreur', 'alerte' => 'alerte');

$ordre = array_merge($visibles, $masquees);
$eqLogics = eqLogic::byType('heta');
if (count($eqLogics) == 0) {
    echo "Aucun équipement heta trouvé.\n";
}
$css = array();
foreach ($eqLogics as $eq) {
    // Widget de référence : celui de la température (nom exact selon la version de Jeedom)
    $ref = $eq->getCmd(null, 'temperature');
    $tplDash = is_object($ref) && $ref->getTemplate('dashboard') != '' ? $ref->getTemplate('dashboard') : 'tile';
    $tplMob  = is_object($ref) && $ref->getTemplate('mobile') != '' ? $ref->getTemplate('mobile') : 'tile';

    $i = 1;
    foreach ($ordre as $logicalId) {
        $cmd = $eq->getCmd(null, $logicalId);
        if (!is_object($cmd)) {
            continue;
        }
        $cmd->setOrder($i++);
        $cmd->setIsVisible(in_array($logicalId, $visibles) ? 1 : 0);
        if (in_array($logicalId, $tuiles)) {
            $cmd->setTemplate('dashboard', $tplDash);
            $cmd->setTemplate('mobile', $tplMob);
        }
        if ($logicalId == 'thermostat') {
            $params = $cmd->getDisplay('parameters');
            if (!is_array($params)) {
                $params = array();
            }
            $params['step'] = '0.2';
            $cmd->setDisplay('parameters', $params);
        }
        $cmd->save();
        if (isset($petites[$logicalId])) {
            $css[] = '.cmd[data-cmd_id="' . $cmd->getId() . '"] .state  /* ' . $eq->getName() . ' : ' . $petites[$logicalId] . ' */';
        }
    }
    // Commandes éventuelles non prévues ci-dessus : placées à la fin, visibilité inchangée
    foreach ($eq->getCmd() as $cmd) {
        if (!in_array($cmd->getLogicalId(), $ordre)) {
            $cmd->setOrder($i++);
            $cmd->save();
        }
    }
    $eq->refreshWidget();
    echo $eq->getHumanName() . " : mise en page appliquée\n";
}

if (count($css) > 0) {
    echo "\nCSS à coller dans Réglages > Système > Personnalisation avancée (CSS bureau), puis Ctrl+F5 :\n\n";
    echo "/* Poêle : Etat, Erreur, Alerte en plus petit */\n";
    echo implode(",\n", $css) . " {\n    font-size: 1.2em !important;\n}\n";
}
