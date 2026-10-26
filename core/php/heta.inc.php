<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 *
 * Version corrigée PHP 8 (constructeurs, clés de tableaux, robustesse).
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';

class hetaResult {
    const ETAT = array(
        0   => 'Off',
        1   => 'Off (après démarrage à froid)',
        2   => 'Off (bûches)',
        10  => 'Préchauffage',
        20  => 'Allumage',
        21  => 'Pré-combustion',
        30  => 'Combustion',
        40  => 'En attente mode ECO',
        50  => 'Refroidissement',
        60  => 'Hybride : initialisation',
        80  => 'Hybride : démarrage',
        90  => 'Démarrage bûches',
        100 => 'Démarrage à froid',
        110 => 'Combustion bûches'
    );
    const ETATS_ARRET = array(0, 1, 2);

    const ERREURS = array(
        101 => "Échec d'allumage",
        102 => 'Conduit / foyer encrassé',
        105 => 'Défaut sonde T02',
        106 => 'Défaut sonde T03/T05',
        107 => 'Défaut sonde T04',
        108 => 'Sécurité thermique',
        109 => 'Capteur de pression',
        110 => 'Défaut sonde T01/T02',
        111 => 'Défaut sonde T01/T03',
        113 => 'Surchauffe des fumées',
        114 => "Délai d'allumage dépassé",
        115 => 'Erreur générale',
        239 => 'Alarme porte (MF)',
        240 => 'Erreur feu',
        241 => 'Alarme conduit',
        243 => 'Erreur grille',
        244 => 'Alarme NTC2',
        245 => 'Alarme NTC3',
        247 => 'Alarme porte',
        248 => 'Alarme pression',
        249 => 'Alarme NTC1',
        250 => 'Alarme TC1',
        252 => 'Alarme gaz',
        253 => 'Plus de pellets'
    );

    const ALERTES = array(
        1 => 'Pellets bas',
        2 => 'Entretien à prévoir',
        3 => 'Avertissement fumées',
        4 => 'Pile faible',
        5 => 'Défaut capteur de vitesse',
        6 => 'Porte ouverte',
        7 => 'Défaut circulation d\'air'
    );

    public $valid = false;
    public $message;
    public $code;
    public $etat;
    public $etatId;
    public $data;
    public $temperature;          // température constatée
    public $consigne;             // consigne thermostat
    public $pellet;               // niveau réservoir (%)
    public $puissance;
    public $puissanceConsigne;    // puissance demandée (1 à 5)
    public $puissanceKw;          // puissance de chauffe instantanée (kW)
    public $ventilation;
    public $statistic = array();  // statistiques de fonctionnement
    public $temperatureInterne;   // température foyer
    public $actif;
    public $erreurCode;
    public $erreur;
    public $alerteCode;
    public $alerte;
    public $extracteur;           // vitesse de l'extracteur de fumées (variable 34, tr/min)
    public $depression;           // capteur de dépression / débit d'air (variable 35)
    public $timerActif;           // programmation hebdomadaire activée
    public $dernierContact;       // secondes depuis le dernier contact du module
    public $signalWifi;           // %
    public $ecartHorloge;         // minutes (horloge du poêle - heure Jeedom)

    public function __construct($pData) {
        if (!is_object($pData) || !isset($pData->controller)) {
            $this->message = is_object($pData) ? ($pData->message ?? 'Réponse Fumis invalide') : 'Pas de réponse Fumis';
            return;
        }
        $this->data    = $pData;
        $this->valid   = true;
        $this->message = $pData->message ?? null;
        $this->code    = $pData->code ?? null;

        $status = $pData->controller->status ?? null;
        $this->etatId = $status;
        $this->etat   = self::ETAT[$status] ?? ('Inconnu (' . $status . ')');

        $temp = $this->getTemperature(1);
        $this->temperature = $temp->actual ?? null;
        $this->consigne    = $temp->set ?? null;

        $fuel = $this->getFuel(1);
        $this->pellet = isset($fuel->quantity) ? round($fuel->quantity * 100) : null;

        $this->puissance = $pData->controller->power->actualPower ?? null;
        $this->puissanceConsigne = $pData->controller->power->setPower ?? null;
        $this->puissanceKw = isset($pData->controller->power->kw) ? round((float) $pData->controller->power->kw, 1) : null;

        $fan = $this->getFan(1);
        $this->ventilation = $fan->speed ?? null;

        $this->temperatureInterne = $this->getFoyer();
        $this->statistic = $this->getStatistic();

        $c = $pData->controller;
        $this->actif = in_array($status, self::ETATS_ARRET) ? 0 : 1;
        // La valeur kW n'est significative qu'en combustion : à l'arrêt, en attente éco, en
        // refroidissement..., le contrôleur renvoie une valeur figée (2 ou 3 kW) sans chauffe réelle.
        if ($status != 30 && $this->puissanceKw !== null) {
            $this->puissanceKw = 0;
        }
        $this->erreurCode = $c->error ?? null;
        $this->erreur = self::libelle($this->erreurCode, self::ERREURS, 'E');
        $this->alerteCode = $c->alert ?? null;
        $this->alerte = self::libelle($this->alerteCode, self::ALERTES, 'A');
        $this->extracteur = $this->getVariableValue(34);
        $this->depression = $this->getVariableValue(35);
        $this->timerActif = isset($c->timerEnable) ? ($c->timerEnable ? 1 : 0) : null;
        if (isset($c->currentTime, $c->stoveLastAvailability)) {
            $this->dernierContact = max(0, $c->currentTime - $c->stoveLastAvailability);
        }
        $rssi = isset($pData->unit->rssi) ? (int) $pData->unit->rssi : null;
        $this->signalWifi = ($rssi === null) ? null : max(0, min(100, 2 * ($rssi + 100)));
        $this->ecartHorloge = $this->getEcartHorloge();
        $this->data = null;
    }

    protected static function libelle($code, $table, $prefixe) {
        if ($code === null) {
            return null;
        }
        if ($code == 0) {
            return 'Aucune';
        }
        return $prefixe . str_pad($code, 3, '0', STR_PAD_LEFT) . ' ' . ($table[$code] ?? 'code inconnu');
    }

    // Horloge interne du poêle (variables 23 à 29) comparée à l'heure de Jeedom
    protected function getEcartHorloge() {
        $v = array();
        foreach (array(23, 24, 25, 27, 28, 29) as $id) {
            $v[$id] = $this->getVariableValue($id);
            if ($v[$id] === null) {
                return null;
            }
        }
        $t = mktime($v[25], $v[24], $v[23], $v[28], $v[27], 2000 + $v[29]);
        return ($t === false) ? null : round(($t - time()) / 60);
    }

    protected function getFoyer() {
        $sonde7 = $this->getTemperature(7);
        if ($sonde7 !== null && isset($sonde7->actual)) {
            return $sonde7->actual;
        }
        return $this->getVariableValue(11);
    }

    protected function getStatistic() {
        $c = $this->data->controller;
        $s = $c->statistic ?? null;
        return array(
            'tMaintenance'  => isset($c->timeToService) ? $c->timeToService + 0 : null,
            'nbAllumages'   => $s->igniterStarts ?? null,
            'tSecteur'      => isset($s->uptime) ? round($s->uptime / 3600) : null,
            'tChauffage'    => isset($s->heatingTime) ? round($s->heatingTime / 3600) : null,
            'tService'      => isset($s->serviceTime) ? round($s->serviceTime / 3600) : null,
            'nbSurchauffe'  => $s->overheatings ?? null,
            'nbErrAllumage' => $s->misfires ?? null
        );
    }

    protected function getTemperature($id) {
        return $this->getElement($this->data->controller->temperatures ?? null, $id);
    }

    protected function getFuel($id) {
        return $this->getElement($this->data->controller->fuels ?? null, $id);
    }

    protected function getFan($id) {
        return $this->getElement($this->data->controller->fans ?? null, $id);
    }

    protected function getVariableValue($id) {
        $elt = $this->getElement($this->data->controller->diagnostic->variables ?? null, $id);
        return $elt->value ?? null;
    }

    protected function getElement($elts, $id) {
        if (!is_array($elts)) {
            return null;
        }
        foreach ($elts as $elt) {
            if (isset($elt->id) && $elt->id == $id) {
                return $elt;
            }
        }
        return null;
    }
}

class fumis {
    const FUMISURL   = 'https://api.fumis.si/v1/status/';
    const APPNAME    = 'heta';
    const APIVERSION = '1';
    const TIMEOUT    = 45;

    private $_mac;
    private $_pin;

    public function __construct($pMac = null, $pPin = null) {
        $this->setMac($pMac);
        $this->setPin($pPin);
    }

    public function getStatus() {
        $context = $this->_createContext('GET');
        return $this->_createResult($this->_launch(self::FUMISURL, $context));
    }

    public function setOrder($pOrder) {
        return $this->_sendCommand($this->_prepareCommand('order', $pOrder * 1));
    }

    public function start() {
        return $this->_sendCommand($this->_prepareCommand('on'));
    }

    public function stop() {
        return $this->_sendCommand($this->_prepareCommand('off'));
    }

    public function setPower($pPower) {
        return $this->_sendCommand($this->_prepareCommand('power', $pPower));
    }

    public function setTimer($pEnable) {
        return $this->_sendCommand(array('timerEnable' => (bool) $pEnable));
    }

    // Met l'horloge interne du poêle à l'heure de Jeedom (variables 23 à 29)
    public function syncClock() {
        $t = time();
        $vars = array(
            array('id' => 23, 'value' => (int) date('s', $t)),
            array('id' => 24, 'value' => (int) date('i', $t)),
            array('id' => 25, 'value' => (int) date('G', $t)),
            array('id' => 26, 'value' => (int) date('N', $t)),
            array('id' => 27, 'value' => (int) date('j', $t)),
            array('id' => 28, 'value' => (int) date('n', $t)),
            array('id' => 29, 'value' => (int) date('y', $t))
        );
        return $this->_sendCommand(array('diagnostic' => array('variables' => $vars)));
    }

    protected function _createResult($strData) {
        $inData = ($strData === null) ? null : json_decode($strData);
        return new hetaResult($inData);
    }

    // Ne renvoie que la partie "controller" ; l'enveloppe est ajoutée par _sendCommand
    protected function _prepareCommand($pCmd, $pValue = null) {
        switch ($pCmd) {
            case 'order':
                return array(
                    'temperatures' => array(array('set' => $pValue, 'id' => 1)),
                    'type' => 0
                );
            case 'on':
                return array('command' => 2, 'type' => 0);
            case 'off':
                return array('command' => 1, 'type' => 0);
            case 'power':
                return array('power' => array('setPower' => max(1, min(5, (int) round($pValue)))));
        }
        return array();
    }

    protected function _sendCommand($pCommand) {
        $content = array(
            'unit' => array(
                'id'   => $this->_mac,
                'type' => 0,
                'pin'  => $this->_pin
            ),
            'apiVersion' => self::APIVERSION,
            'controller' => $pCommand
        );
        $context = $this->_createContext('POST', $content);
        return $this->_createResult($this->_launch(self::FUMISURL, $context));
    }

    protected function _createContext($pMethod, $pContent = null) {
        $headers = array(
            'Content-type: application/json',
            'password: ' . $this->_pin,
            'username: ' . $this->_mac,
            'appname: ' . self::APPNAME,
            'Accept-Language: fr-fr'
        );
        $opts = array(
            'http' => array(
                'method'        => $pMethod,
                'header'        => $headers,
                'timeout'       => self::TIMEOUT,
                'ignore_errors' => true
            )
        );
        if ($pContent !== null) {
            $opts['http']['content'] = json_encode($pContent);
        }
        // Log sans le PIN
        $logOpts = $opts;
        $logOpts['http']['header'][1] = 'password: ****';
        if (isset($logOpts['http']['content'])) {
            $logOpts['http']['content'] = str_replace('"' . $this->_pin . '"', '"****"', $logOpts['http']['content']);
        }
        log::add('heta', 'debug', 'Fumis request = ' . json_encode($logOpts));
        return stream_context_create($opts);
    }

    protected function _launch($pUrl, $pContext) {
        $stream = @file_get_contents($pUrl, false, $pContext);
        if (function_exists('http_get_last_response_headers')) {
            $respHeaders = http_get_last_response_headers() ?? array();
        } else {
            $respHeaders = $http_response_header ?? array();
        }
        $statusLine = $respHeaders[0] ?? '';

        if ($stream === false) {
            $err = error_get_last();
            log::add('heta', 'error', 'Fumis Error : ' . ($err['message'] ?? 'appel impossible'));
            return null;
        }
        if (!preg_match('#^HTTP/\S+\s+2\d\d#', $statusLine)) {
            log::add('heta', 'error', 'Fumis Error : ' . $statusLine . ' ' . substr($stream, 0, 300));
            return null;
        }
        return $stream;
    }

    public function setMac($pMac) {
        $this->_mac = trim((string) $pMac);
    }

    public function setPin($pPin) {
        $this->_pin = trim((string) $pPin);
    }
}
