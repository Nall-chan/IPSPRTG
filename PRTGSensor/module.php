<?php

declare(strict_types = 1);

require_once __DIR__ . '/../libs/ConstHelper.php';
require_once __DIR__ . '/../libs/VariableHelper.php';
require_once __DIR__ . '/../libs/DebugHelper.php';
require_once __DIR__ . '/../libs/BufferHelper.php';
require_once __DIR__ . '/../libs/PRTGHelper.php';

/*
 * @addtogroup prtg
 * @{
 *
 * @package       PRTG
 * @file          module.php
 * @author        Michael Tröger <micha@nall-chan.net>
 * @copyright     2018 Michael Tröger
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 * @version       1.0
 *
 */

/**
 * PRTGSensor Klasse für ein Sensor von PRTG.
 * Erweitert IPSModule.
 * 
 * @package       PRTG
 * @author        Michael Tröger <micha@nall-chan.net>
 * @copyright     2018 Michael Tröger
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 * @version       1.0 
 * @example <b>Ohne</b>
 * @property int $Interval
 */
class PRTGSensor extends IPSModule
{

    use VariableHelper,
        VariableProfile,
        DebugHelper,
        BufferHelper,
        PRTGPause;
    /**
     * Interne Funktion des SDK.
     *
     * @access public
     */
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyBoolean('AutoRename', true);
        $this->RegisterPropertyBoolean('ShowActionButton', true);
        $this->RegisterPropertyBoolean('ShowAckButton', true);
        $this->RegisterPropertyBoolean('AutoRenameChannels', true);
        $this->RegisterPropertyBoolean('ReadableState', true);
        $this->RegisterPropertyBoolean('UseInterval', false);
        $this->RegisterPropertyInteger('Interval', 60);
        $this->RegisterPropertyInteger('id', 0);
        $this->RegisterTimer('RequestState', 0, 'PRTG_RequestState($_IPS[\'TARGET\']);');
        $this->ConnectParent('{67470842-FB5E-485B-92A2-4401E371E6FC}');
    }

    /**
     * Interne Funktion des SDK.
     *
     * @access public
     */
    public function ApplyChanges()
    {
        $this->RegisterProfileBooleanEx('PRTG.Action', 'Gear', '', '', [
            [true, $this->Translate('Active'), '', 0x00ff00],
            [false, $this->Translate('Pause'), '', 0x000090]
        ]);
        $this->RegisterProfileIntegerEx('PRTG.Ack', '', '', '', [
            [0, $this->Translate('Acknowledge alarm'), 'Gear', 0x555555],
        ]);
        $this->RegisterProfileIntegerEx('PRTG.Sensor', 'Information', '', '', [
            [1, $this->Translate('Unknown'), '', 0x555555],
            [2, $this->Translate('Scanning'), '', 0x555555],
            [3, $this->Translate('Up'), '', 0x00ff00],
            [4, $this->Translate('Warning'), 'Warning', 0x808000],
            [5, $this->Translate('Down'), 'Warning', 0xff0000],
            [6, $this->Translate('No Probe'), '', 0x555555],
            [7, $this->Translate('Paused'), 'Sleep', 0x000090],
            [8, $this->Translate('Paused by Dependency'), 'Sleep', 0x000090],
            [9, $this->Translate('Paused by Schedule'), 'Sleep', 0x000090],
            [10, $this->Translate('Unusual'), 'Warning', 0x808000],
            [11, $this->Translate('Not Licensed'), 'Sleep', 0x000090],
            [12, $this->Translate('Paused Until'), 'Sleep', 0x000090],
            [13, $this->Translate('Down Acknowledged'), 'Warning', 0xff0000],
            [14, $this->Translate('Down Partial'), 'Warning', 0xff0000],
        ]);
        $this->RegisterProfileFloat('PRTG.ms', '', '', ' ms', 0, 0, 0, 2);
        $this->RegisterProfileFloat('PRTG.Intensity', 'Intensity', '', ' %', 0, 100, 0, 2);
        $this->RegisterProfileInteger('PRTG.No', '', '', ' #', 0, 0, 0);
        $this->RegisterProfileFloat('PRTG.MByte', '', '', ' MByte', 0, 0, 0, 2);
        $this->RegisterProfileInteger('PRTG.Sec', '', '', $this->Translate(' sec'), 0, 0, 0);
        $this->RegisterProfileInteger('PRTG.MBitSec', '', '', $this->Translate(' Mbit/sec'), 0, 0, 0);
        $this->RegisterProfileInteger('PRTG.kBitSec', '', '', $this->Translate(' kbit/sec'), 0, 0, 0);
        $this->RegisterProfileInteger('PRTG.IpS', '', '', $this->Translate(' Items/sec'), 0, 0, 0);
        $this->RegisterProfileInteger('PRTG.IpM', '', '', $this->Translate(' Items/min'), 0, 0, 0);
        $this->RegisterProfileInteger('PRTG.Items', '', '', ' Items', 0, 0, 0);

        parent::ApplyChanges();
        $this->SetReceiveDataFilter('.*"objid":' . $this->ReadPropertyInteger('id') . '.*');

        if (!@$this->GetIDForIdent('State')) {
            $this->MaintainVariable('State', $this->Translate('State'), vtInteger, 'PRTG.Sensor', -2, true);
            $this->SetValue('State', 6);
        }

        if ($this->ReadPropertyBoolean('ReadableState')) {
            $this->MaintainVariable('ReadableState', $this->Translate('Readable State'), vtString, '', -2, true);
        } else {
            $this->UnregisterVariable('ReadableState');
        }
        if ($this->ReadPropertyBoolean('ShowActionButton')) {
            $this->MaintainVariable('ActionButton', $this->Translate('Control'), vtBoolean, 'PRTG.Action', -4, true);
            $this->EnableAction('ActionButton');
        } else {
            $this->UnregisterVariable('ActionButton');
        }
        if ($this->ReadPropertyBoolean('ShowAckButton')) {
            $this->MaintainVariable('AckButton', $this->Translate('Alarm Control'), vtInteger, 'PRTG.Ack', -3, true);
            $this->EnableAction('AckButton');
        } else {
            $this->UnregisterVariable('AckButton');
        }
        if ($this->ReadPropertyInteger('id') > 0) {
            $this->SetStatus(IS_ACTIVE);
            if (IPS_GetKernelRunlevel() == KR_READY) { // IPS läuft dann gleich Daten abholen
                $this->RequestSensorState();
                $this->RequestChannelState();
            }
            $this->SetTimer(true);
        } else {
            $this->SetStatus(IS_INACTIVE);
            $this->SetTimer(false);
        }
    }

    /**
     * Setzt den Intervall-Timer
     */
    private function SetTimer(bool $Active)
    {
        if ($Active) {
            if ($this->ReadPropertyBoolean('UseInterval')) {
                $Sec = $this->ReadPropertyInteger('Interval');
            } else {
                $Sec = $this->Interval;
            }
            $Interval = ($Sec < 5) ? 0 : $Sec * 1000;
        } else {
            $Interval = 0;
        }

        $this->SetTimerInterval('RequestState', $Interval);
    }

    /**
     * IPS Instanz-Funktion PRTG_RequestState
     * @access public
     * @return bool True bei Erfolg, False im Fehlerfall
     */
    public function RequestState(): bool
    {
        if ($this->RequestSensorState()) {
            return $this->RequestChannelState();
        }
        return false;
    }

    /**
     * Fragt den Zustand des Sensors aus PRTG ab.
     * @return bool True bei Erfolg, False im Fehlerfall
     */
    private function RequestSensorState(): bool
    {
        $Result = $this->SendData('api/table.json', [
            'content'      => 'sensors',
            'columns'      => 'objid,lastvalue,device,status,name,interval,active',
            'filter_objid' => $this->ReadPropertyInteger('id')
        ]);
        if (!array_key_exists('sensors', $Result)) {
            return false;
        }
        if (sizeof($Result['sensors']) != 1) {
            return false;
        }
        $Data = $Result['sensors'][0];
        if ($Data['name'] == '') {
            return false;
        }
        $this->SetSummary($Data['device']);

        $this->SetValue('State', $Data['status_raw']);
        if ($this->ReadPropertyBoolean('ReadableState')) {
            $this->SetValue('ReadableState', $Data['status']);
        }
        if ($this->ReadPropertyBoolean('ShowActionButton')) {
            $this->SetValue('ActionButton', (bool) $Data['active_raw']);
        }
        if ($this->ReadPropertyBoolean('AutoRename')) {
            IPS_SetName($this->InstanceID, $Data['name']);
        }
        if ($this->Interval != (int) $Data['interval_raw']) {
            $this->Interval = (int) $Data['interval_raw'];
            $this->SetTimer(true);
        }

        return true;
    }

    /**
     * Fragt den Zustand aller Kanäle dieses Sensors aus PRTG ab.
     * @return bool True bei Erfolg, False im Fehlerfall
     */
    private function RequestChannelState(): bool
    {
        $Result = $this->SendData('api/table.json', [
            'content' => 'channels',
            'columns' => 'objid,lastvalue,name',
            'id'      => $this->ReadPropertyInteger('id')
        ]);
        if (!array_key_exists('channels', $Result)) {
            return false;
        }
        $this->DecodeChannelData($Result['channels']);
        return true;
    }

    /**
     * Dekodiert die Daten der Kanäle und schreibt diese in Statusvariablen.
     * @param array $Channels
     */
    private function DecodeChannelData(array $Channels)
    {
        foreach ($Channels as $Channel) {
            if ($Channel['objid'] < -3) {
                continue;
            }
            if ($Channel['objid'] < 0) {
                $Ident = $Channel['objid'] + 255;
            } else {
                $Ident = $Channel['objid'];
            }
            $Data = $this->ConvertValue($Channel);
            if ($Data === false) {
                continue;
            }
            if (array_key_exists('name_raw', $Channel)) {
                $Channel['name'] = $Channel['name_raw'];
            }

            $this->MaintainVariable($Ident, $Channel['name'], $Data['VarType'], $Data['Profile'], $Channel['objid'], true);
            $vid = $this->GetIDForIdent($Ident);

            if ($this->ReadPropertyBoolean('AutoRenameChannels') and ( IPS_GetName($vid)) != $Channel['name']) {
                IPS_SetName($vid, $Channel['name']);
            }
            $this->SetValue($Ident, $Data['Data']);
        }
    }

    /**
     * Sendet Eine Anfrage an den IO und liefert die Antwort
     * 
     * @param string $Uri URI der Anfrage
     * @param array $QueryData Alle mit Allen GET-Parametern
     * @param string $PostData String mit POST Daten
     * @return array Antwort ale Array
     */
    private function SendData(string $Uri, array $QueryData = [], string $PostData = ''): array
    {
        $this->SendDebug('Request Uri:', $Uri, 0);
        $this->SendDebug('Request QueryData:', $QueryData, 0);
        $this->SendDebug('Request PostData:', $PostData, 0);
        $Data['DataID'] = '{963B49EF-64E6-4C70-8DA4-6699EF9B8CC5}';
        $Data['Uri'] = $Uri;
        $Data['QueryData'] = $QueryData;
        $Data['PostData'] = $PostData;
        $ResultString = $this->SendDataToParent(json_encode($Data));
        if ($ResultString === null) {
            trigger_error($this->Translate('No answer'), E_USER_NOTICE);
            return [];
        }
        $Result = unserialize($ResultString);
        if ($Result['Error'] != 200) {
            $this->SendDebug('Result Error', $Result, 0);
            trigger_error('Error: ' . $Result['Error'], E_USER_NOTICE);
            return [];
        }
        unset($Result['Error']);
        $this->SendDebug('Request Result', $Result, 0);
        return $Result;
    }

    /**
     * Verarbeitet empfangene Events des IO.
     * @access public
     * @param string $JSONString
     */
    public function ReceiveData($JSONString)
    {
        $Data = json_decode($JSONString, true);
        $this->SendDebug('Got Event', $Data, 0);
        $this->RequestState();
        $this->SendDebug('End Event', $Data, 0);
    }

    /**
     * Interne Funktion des SDK.
     *
     * @access public
     * @return string Konfigurationsform
     */
    public function GetConfigurationForm(): string
    {
        $Form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        if (!$this->ReadPropertyBoolean('UseInterval')) {
            unset($Form['elements'][7]);
        }
        return json_encode($Form);
    }

    /**
     * Interne Funktion des SDK.
     *
     * @access public
     * @return bool True bei Erfolg, False im Fehlerfall
     */
    public function RequestAction($Ident, $Value): bool
    {
        switch ($Ident) {
            case 'ActionButton':
                if ($Value) {
                    return $this->SetResume();
                } else {
                    return $this->SetPause();
                }
            case 'AckButton':
                return $this->AcknowledgeAlarm();
        }
        trigger_error('Invalid Ident', E_USER_NOTICE);
        return false;
    }

    /**
     * Bestätigt einen Alarm in PRTG.
     * @access public
     * @return bool True bei Erfolg, False im Fehlerfall
     */
    public function AcknowledgeAlarm(): bool
    {
        return $this->AcknowledgeAlarmEx('');
    }

    /**
     * Bestätigt einen Alarm in PRT mit der in $Message übergebenen Nachricht.
     * @param string $Message Nachricht für PTRG.
     * @return bool True bei Erfolg, False im Fehlerfall
     */
    public function AcknowledgeAlarmEx(string $Message): bool
    {

        if (!is_string($Message)) {
            trigger_error($this->Translate('Message must be string.'), E_USER_NOTICE);
            return false;
        }
        $QueryData = [
            'action' => 0,
            'id'     => $this->ReadPropertyInteger('id')
        ];

        if ($Message != '') {
            $QueryData['ackmsg'] = $Message;
        }

        $Result = $this->SendData('api/acknowledgealarm.htm', $QueryData);

        if (array_key_exists('Payload', $Result)) {
            return $this->RequestState();
        }
        return false;
    }

}

/** @} */