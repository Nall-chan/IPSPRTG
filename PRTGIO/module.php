<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/ConstHelper.php';
require_once __DIR__ . '/../libs/BufferHelper.php';
require_once __DIR__ . '/../libs/DebugHelper.php';
require_once __DIR__ . '/../libs/WebhookHelper.php';

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
 * PRTGIO Klasse für die Kommunikation mit PRTG.
 * Erweitert IPSModule.
 *
 * @author        Michael Tröger <micha@nall-chan.net>
 * @copyright     2018 Michael Tröger
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 *
 * @version       1.0
 *
 * @example <b>Ohne</b>
 *
 * @property string $Url
 * @property string $Hash
 * @property self $State
 */
class PRTGIO extends IPSModule
{
    use BufferHelper,
        DebugHelper,
        WebhookHelper;
    const isConnected = IS_ACTIVE;
    const isInActive = IS_INACTIVE;
    const isDisconnected = IS_EBASE + 1;
    const isUnauthorized = IS_EBASE + 2;
    const isURLnotValid = IS_EBASE + 3;

    /**
     * Interne Funktion des SDK.
     */
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyBoolean('Open', false);
        $this->RegisterPropertyString('Host', 'http://');
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('Interval', 0);
        $this->Url = '';
        $this->Hash = '';
        $this->State = self::isInActive;
    }

    /**
     * Interne Funktion des SDK.
     */
    public function ApplyChanges()
    {
        $this->Url = '';
        $this->Hash = '';
        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        parent::ApplyChanges();

        if ($this->CheckHost()) {
            $this->SetSummary($this->Url);
            if (!$this->GetPasswordHash()) {
                return;
            }
        } else {
            $this->SetSummary('');
            return;
        }

        if (IPS_GetKernelRunlevel() == KR_READY) { // IPS läuft dann gleich Daten abholen
            $this->RegisterHook('/hook/PRTG' . $this->InstanceID);
            //$this->RequestState();
        }
    }

    /**
     * Interne Funktion des SDK.
     *
     * @param type $TimeStamp
     * @param type $SenderID
     * @param type $Message
     * @param type $Data
     */
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->ApplyChanges();
                break;
        }
    }

    /**
     * IPS Instanz-Funktion PRTG_RequestState.
     *
     * @return bool True bei Erfolg, False im Fehlerfall
     */
//    public function RequestState(): bool
//    {
//        $Result = $this->SendData('api/table.json', [
//            'content' => 'sensors',
//            'columns' => 'objid'
//        ]);
//        if ($Result['Error'] != 200) {
//            $this->SendDebug('Result Error:', $Result, 0);
//            trigger_error('Error: ' . $Result['Error'], E_USER_NOTICE);
//            return false;
//        }
//        if (!array_key_exists('sensors', $Result)) {
//            return false;
//        }
//        foreach ($Result['sensors'] as $Sensor) {
//            $Sensor = array_merge($Sensor, ['DataID' => '{45829008-026B-401E-829F-8384DD27619A}']);
//            $this->SendDataToChildren(json_encode($Sensor));
//        }
//        return true;
//    }

    /**
     * Liefert JSON-Daten für eine HTTP-Abfrage von PRTG an den IPS-Webhook.
     *
     * @return string JSON-String für PRTG HTTP-Daten-Sensor
     */
    private function FetchIPSSensorData(): string
    {
        //$this->SendDebug('FetchIPSSensorData', '', 0);
        $i = 0;
        $Threads = IPS_GetScriptThreadList();
        foreach ($Threads as $Thread) {
            $Par = IPS_GetScriptThread($Thread);
            if ($Par['Sender']) {
                $i++;
            }
        }
        $Channels = [];
        $Channels[] = ['channel' => 'PHP Threads', 'value' => $i, 'unit' => 'Count', 'limitmaxwarning' => (int) (count($Threads) / 100 * 50), 'limitmaxerror' => (int) (count($Threads) / 100 * 90), 'LimitMode' => 1];
        $Channels[] = ['channel' => 'IPS Objects', 'value' => count(IPS_GetObjectList()), 'unit' => 'Count', 'limitmaxwarning' => 45000, 'limitmaxerror' => 50000, 'LimitMode' => 1];

        $UtilsId = IPS_GetInstanceListByModuleID('{B69010EA-96D5-46DF-B885-24821B8C8DBD}');
        if (count($UtilsId) > 0) {
            $VarId = @IPS_GetObjectIDByIdent('LicenseSubscription', $UtilsId[0]);
            if ($VarId > 0) {
                $Channels[] = ['channel' => 'License Subscription', 'value' => GetValueInteger($VarId) - time(), 'unit' => 'TimeSeconds', 'limitminwarning' => 30 * 24 * 60 * 60, 'limitminerror' => 0, 'LimitMode' => 1];
            }
        }

        $Messages = UC_GetLogMessageStatistics($UtilsId[0]);
        $TimeSpanSec = (time() - $Messages['ResetTimeStamp']);
        if ($TimeSpanSec > 0) {
            unset($Messages['ResetTimeStamp']);
            $TimeSpan = $TimeSpanSec / 60;
            foreach ($Messages as $MessageTyp => $Value) {
                switch ($MessageTyp) {
                    case 'MessageWarningCount':
                        $MessageChannel = [
                            'limitmaxwarning' => 10,
                            'limitmaxerror'   => 20,
                            'LimitMode'       => 1
                        ];
                        break;
                    case 'MessageErrorCount':
                        $MessageChannel = [
                            'limitmaxwarning' => 5,
                            'limitmaxerror'   => 10,
                            'LimitMode'       => 1
                        ];
                        break;
                    default:
                        $MessageChannel = [];
                }
                $MessageTyp = str_split(substr($MessageTyp, 0, -5), 7);
                $MessageChannel = array_merge($MessageChannel, ['channel' => $MessageTyp[0] . ' ' . $MessageTyp[1], 'value' => (int) ($Value / $TimeSpan), 'unit' => 'Custom', 'customunit' => '#/Min.', 'speedtime' => 'Minute']);
                $Channels[] = $MessageChannel;
            }
        }
        $ProcessInfo = Sys_GetProcessInfo();
        $Channels[] = ['channel' => 'Process Handles', 'value' => $ProcessInfo['IPS_HANDLECOUNT'], 'unit' => 'Count'];
        $Channels[] = ['channel' => 'Process Threads', 'value' => $ProcessInfo['IPS_NUMTHREADS'], 'unit' => 'Count'];
        $Channels[] = ['channel' => 'Process Virtualsize', 'value' => $ProcessInfo['IPS_VIRTUALSIZE'], 'unit' => 'BytesMemory'];
        $Channels[] = ['channel' => 'Process Workingsetsize', 'value' => $ProcessInfo['IPS_WORKINGSETSIZE'], 'unit' => 'BytesMemory'];
        $Channels[] = ['channel' => 'Process Pagefile', 'value' => $ProcessInfo['IPS_PAGEFILE'], 'unit' => 'BytesMemory'];
        $Channels[] = ['channel' => 'Process Count', 'value' => $ProcessInfo['PROCESSCOUNT'], 'unit' => 'Count'];
        $MemoryInfo = Sys_GetMemoryInfo();
        $Channels[] = ['channel' => 'System RAM Physical Free', 'value' => $MemoryInfo['AVAILPHYSICAL'] / $MemoryInfo['TOTALPHYSICAL'] * 100, 'float' => 1, 'unit' => 'Percent', 'limitminwarning' => 20, 'limitminerror' => 5, 'LimitMode' => 1];
        $Channels[] = ['channel' => 'System RAM Pagefile Free', 'value' => $MemoryInfo['AVAILPAGEFILE'] / $MemoryInfo['TOTALPAGEFILE'] * 100, 'float' => 1, 'unit' => 'Percent', 'limitminwarning' => 20, 'limitminerror' => 5, 'LimitMode' => 1];
        $Channels[] = ['channel' => 'System RAM Virtual Free', 'value' => $MemoryInfo['AVAILVIRTUAL'] / $MemoryInfo['TOTALVIRTUAL'] * 100, 'float' => 1, 'unit' => 'Percent', 'limitminwarning' => 20, 'limitminerror' => 5, 'LimitMode' => 1];
        $CPUs = Sys_GetCPUInfo();
        foreach ($CPUs as $Key => $Value) {
            $Name = explode('_', $Key);
            $Channels[] = ['channel' => 'System CPU ' . $Name[1], 'value' => $Value, 'float' => 1, 'unit' => 'CPU', 'limitmaxwarning' => 70, 'limitmaxerror' => 90, 'LimitMode' => 1];
        }
        $Drives = Sys_GetHardDiskInfo();
        foreach ($Drives as $Value) {
            if ($Value['LABEL'] == '') {
                $Name = $Value['LETTER'];
            } else {
                $Value['LABEL'] . '(' . $Value['LETTER'] . ')';
            }
            $Channels[] = ['channel' => 'Disk ' . $Name, 'value' => $Value['FREE'] / $Value['TOTAL'] * 100, 'float' => 1, 'unit' => 'Percent', 'limitminwarning' => 20, 'limitminerror' => 5, 'LimitMode' => 1];
            $Channels[] = ['channel' => 'Disk ' . $Name . ' Free', 'value' => (int) $Value['FREE'], 'unit' => 'BytesDisk'];
        }
        $Result = ['prtg' => ['error' => 0, 'result' => $Channels]];
        return json_encode($Result);
    }

    /**
     * Interne Funktion des SDK.
     */
    protected function ProcessHookdata()
    {
        switch ($_SERVER['REQUEST_METHOD']) {
            case 'GET':
                if (isset($_GET['graph']) && ($_GET['graph'] == 'png')) {
                    header('Content-type: image/png');
                    echo $this->GetGraph(1, $_GET['id'], $_GET['graphid'], $_GET['width'], $_GET['height'], $_GET['theme'], $_GET['graphstyling']);
                    return;
                }
                if (isset($_GET['graph']) && ($_GET['graph'] == 'svg')) {
                    header('Content-Type: image/svg+xml');
                    echo $this->GetGraph(2, $_GET['id'], $_GET['graphid'], $_GET['width'], $_GET['height'], $_GET['theme'], $_GET['graphstyling']);
                    return;
                }
                if (isset($_SERVER['HTTP_SENSORID'])) {
                    echo $this->FetchIPSSensorData();
                    return;
                }
                header('HTTP/1.0 404 Not Found');
                echo 'Not Found!';
                return;
            case 'POST':
                $Data = explode("\r\n", rawurldecode(file_get_contents('php://input')));
                $this->SendDebug('PRTG EVENT', $Data, 0);
                foreach ($Data as $ObjId) {
                    $Sensor = ['objid' => (int) $ObjId, 'DataID' => '{45829008-026B-401E-829F-8384DD27619A}'];
                    $this->SendDataToChildren(json_encode($Sensor));
                }
                break;
        }
    }

    /**
     * Sendet Eine Anfrage an PRTG und liefert die Antwort.
     *
     * @param string $Uri       URI der Abrage
     * @param array  $QueryData Alle mit Allen GET-Parametern
     * @param string $PostData  String mit POST Daten
     *
     * @return array Antwort ale Array
     */
    private function SendData(string $Uri, array $QueryData = [], string $PostData = ''): array
    {
        if ($this->State != self::isConnected) {
            return ['Error' => $this->State];
        }
        $url = $this->CreateQueryURL($Uri, $QueryData);
        $HttpCode = 0;
        $ResultString = $this->SendRequest($url, $HttpCode, $PostData);
        if ($HttpCode >= 400) {
            return ['Error' => $HttpCode];
        }
        if (substr($Uri, -4) == '.htm') {
            $this->SendDebug('Request HTML-Result', $ResultString, 0);
            return ['Payload' => $ResultString, 'Error' => $HttpCode];
        }
        $Result = json_decode($ResultString, true);
        if ($Result === null) {
            $Result['Error'] = 405;
        }
        array_walk_recursive($Result, [$this, 'ResultEncode']);
        $Result['Error'] = 200;
        return $Result;
    }

    /**
     * Callback für array_walk_recursive. Dekodiert HTML-Kodierte Strings.
     *
     * @param mixed  $item
     * @param string $key
     */
    private function ResultEncode(&$item, &$key)
    {
        if (is_string($item)) {
            $item = html_entity_decode($item);
        }
    }

    /**
     * Prüft die Konfiguration der URL für PRTG und schreibt die berenigte URL in einen InstanceBuffer.
     *
     * @return bool True wenn Host ok, sonst false.
     */
    private function CheckHost(): bool
    {
        if (!$this->ReadPropertyBoolean('Open')) {
            $this->SetStatus(104);
            $this->State = self::isInActive;
            return false;
        }
        $URL = $this->ReadPropertyString('Host');
        if ($URL == 'http://') {
            $this->SetStatus(104);
            $this->State = self::isInActive;
            return false;
        }
        $Scheme = parse_url($URL, PHP_URL_SCHEME);
        if ($Scheme == null) {
            $Scheme = 'http';
        }
        $Host = parse_url($URL, PHP_URL_HOST);
        if ($Host == null) {
            $this->SetStatus(203);
            $this->State = self::isDisconnected;
            return false;
        } else {
            $HostL = gethostbynamel($Host);
            if ($HostL === false) {
                $this->SetStatus(201);
                $this->State = self::isDisconnected;
                return false;
            } else {
                $Host = $HostL[0];
            }
        }
        $Port = parse_url($URL, PHP_URL_PORT);
        if ($Port != null) {
            $Host .= ':' . $Port;
        }
        $Path = parse_url($URL, PHP_URL_PATH);
        if (is_null($Path)) {
            $Path = '';
        } else {
            if ((strlen($Path) > 0) and (substr($Path, -1) == '/')) {
                $Path = substr($Path, 0, -1);
            }
        }
        $this->Url = $Scheme . '://' . $Host . $Path . '/';
        return true;
    }

    /**
     * Holt einen PAsswordHash von PRTG.
     *
     * @return bool True bei Erfolg, sonst false
     */
    private function GetPasswordHash(): bool
    {
        $User = $this->ReadPropertyString('Username');
        $Password = $this->ReadPropertyString('Password');
        $QueryData = [
            'username' => $User,
            'password' => $Password
        ];
        $QueryURL = $this->CreateQueryURL('api/getpasshash.htm', $QueryData);
        $HttpCode = 0;
        $Result = $this->SendRequest($QueryURL, $HttpCode);
        if ($Result === '') {
            if ($HttpCode == 404) {
                $this->SetStatus(201);
                $this->State = self::isDisconnected;
            } else {
                $this->SetStatus(202);
                $this->State = self::isUnauthorized;
            }
            $this->Hash = '';
            return false;
        }
        $this->Hash = $Result;
        $this->SetStatus(102);
        $this->State = self::isConnected;
        return true;
    }

    /**
     * IPS Instanz-Funktion PRTG_GetGraph
     * Liefert einen Graphen aus PRTG.
     *
     * @param int  $Type         Typ des Graphen
     *                           enum[1=PNG, 2=SVG]
     * @param int  $SensorId     Objekt-ID des Sensors
     * @param int  $GraphId      Zeitbereich des Graphen
     *                           enum[0=live, 1=last 48 hours, 2=30 days, 3=365 days]
     * @param int  $Width        Höhe des Graphen in Pixel.
     * @param int  $Height       Höhe des Graphen in Pixel.
     * @param int  $Theme        Darstellung
     *                           enum[0,1,2,3]
     * @param int  $BaseFontSize Schriftgröße, 10 ist Standard
     * @param bool $ShowLegend   Legende Anzeigen
     *
     * @return string
     */
    public function GetGraph(int $Type, int $SensorId, int $GraphId, int $Width, int $Height, int $Theme, int $BaseFontSize, bool $ShowLegend)
    {
        if ($this->State != self::isConnected) {
            return false;
        }
        //'showLegend%3D%271%27+baseFontSize%3D%275%27'
        $QueryData = ['type'         => 'graph',
            'graphid'                => $GraphId,
            'width'                  => $Width,
            'height'                 => $Height,
            'theme'                  => $Theme,
            'refreshable'            => 'true',
            'graphstyling'           => "showLegend='" . (int) $ShowLegend . "' baseFontSize=" . $BaseFontSize . "'",
            'id'                     => $SensorId
        ];
        if ($Type == 1) {
            $URL = $this->CreateQueryURL('chart.png', $QueryData);
        } elseif ($Type == 2) {
            $URL = $this->CreateQueryURL('chart.svg', $QueryData);
        }
        $Timeout = [
            'Timeout' => 5000
        ];
        $this->SendDebug('PRTG Graph URL', $URL, 0);
        return @Sys_GetURLContentEx($URL, $Timeout);
    }

    /**
     * Erstellt eine komplette URL für die Anfrage an den PRTG-Server.
     *
     * @param string $Uri       URI für die URL
     * @param array  $QueryData Array mit allen GET-Parametern
     *
     * @return string Die fertige URL
     */
    private function CreateQueryURL(string $Uri, array $QueryData): string
    {
        $Hash = $this->Hash;
        if ($Hash != '') {
            $QueryData['username'] = $this->ReadPropertyString('Username');
            $QueryData['passhash'] = $Hash;
        }
        return $this->Url . $Uri . '?' . http_build_query($QueryData);
    }

    /**
     * Sendet Eine Anfrage an PRTG.
     *
     * @param string $Url      URL der Abrage
     * @param int    $HttpCode Enthält den HTTP-Code der Antwort
     * @param string $PostData String mit POST Daten
     *
     * @return string Antwort als String
     */
    private function SendRequest(string $Url, int &$HttpCode, string $PostData = ''): string
    {
        $this->SendDebug('Request:', $Url, 0);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $Url);
        if ($PostData != '') {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $PostData);
        }
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 5000);
        $Result = curl_exec($ch);
        $HttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($HttpCode == 400) {
            $this->SendDebug('Bad Request', $HttpCode, 0);
        } elseif ($HttpCode == 401) {
            $this->SendDebug('Unauthorized Error', $HttpCode, 0);
            return '';
        } elseif ($HttpCode == 404) {
            $this->SendDebug('Not Found Error', $HttpCode, 0);
            return '';
        } else {
            $this->SendDebug('Request Result:' . $HttpCode, $Result, 0);
        }
        return $Result;
    }

    /**
     * Interne Funktion des SDK.
     *
     * @param type $InstanceStatus
     */
    protected function SetStatus($InstanceStatus)
    {
        $this->State = $InstanceStatus;
        parent::SetStatus($InstanceStatus);
    }

    /**
     * Interne Funktion des SDK.
     *
     * @param type $JSONString Der IPS-Datenstring
     *
     * @return string Die Antwort an den anfragenden Child
     */
    public function ForwardData($JSONString): string
    {
        $Json = json_decode($JSONString, true);
        $Result = $this->SendData($Json['Uri'], $Json['QueryData'], $Json['PostData']);
        return serialize($Result);
    }

    /**
     * Interne Funktion des SDK.
     *
     * @return string Konfigurationsform
     */
    public function GetConfigurationForm(): string
    {
        $Form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $Form['elements'][4]['label'] = 'PRTG Webhook: http://<IP>:<PORT>/hook/PRTG' . $this->InstanceID;
        return json_encode($Form);
    }
}

/* @} */
