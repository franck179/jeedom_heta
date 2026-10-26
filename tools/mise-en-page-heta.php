<?php
/*
 * mise-en-page-heta.php - Réorganise le widget du poêle (plugin heta).
 * À lancer une fois (facultatif), après l'installation ou la mise à jour du plugin :
 *     sudo -u www-data php /var/www/html/plugins/heta/tools/mise-en-page-heta.php
 * Ne modifie que l'ordre et la visibilité des commandes (rien d'irréversible).
 */
require_once __DIR__ . '/../../../core/php/core.inc.php';

// Commandes affichées sur le widget, dans cet ordre
$visibles = array(
    'etat',          // état en clair (Off, Allumage, Combustion...)
    'temperature',   // température ambiante
    'thermostat',    // consigne réglable
    'pellet',        // niveau de pellets (%)
    'on', 'off',
    'setPuissance',  // curseur de puissance 1 à 5
    'puissance',     // puissance réellement appliquée
    'erreur', 'alerte',
    'refresh',
);

// Commandes masquées (toujours disponibles pour les scénarios et l'historique), dans cet ordre
$masquees = array(
    'consigne', 'actif', 'puissanceConsigne', 'extracteur', 'depression', 'foyer', 'ventilation',
    'timeToService', 'allumage', 'tChauffe', 'secteur', 'surchauffes', 'allumagesRates',
    'signalWifi', 'dernierContact', 'ecartHorloge', 'syncHorloge',
    'timerActif', 'timerOn', 'timerOff',
    'erreurCode', 'alerteCode', 'etatId', 'data',
);

$ordre = array_merge($visibles, $masquees);
$eqLogics = eqLogic::byType('heta');
if (count($eqLogics) == 0) {
    echo "Aucun équipement heta trouvé.\n";
}
foreach ($eqLogics as $eq) {
    $i = 1;
    foreach ($ordre as $logicalId) {
        $cmd = $eq->getCmd(null, $logicalId);
        if (!is_object($cmd)) {
            continue;
        }
        $cmd->setOrder($i++);
        $cmd->setIsVisible(in_array($logicalId, $visibles) ? 1 : 0);
        $cmd->save();
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
