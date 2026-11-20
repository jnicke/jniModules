<?php

declare(strict_types=1);

/**
 * jniPegelstand (jniModules)
 *
 * Liest Wasserstand, Abfluss, Status und Tendenz einer Messstation von
 * PEGELONLINE (https://www.pegelonline.wsv.de) aus.
 *
 * Fork von SymconPegelstand (Mike Fröhlich, https://github.com/nik78476/SymconPegelstand)
 * Lizenz: CC BY-NC-SA 4.0
 */
class jniPegelstand extends IPSModule
{
    private const API_BASE = 'https://www.pegelonline.wsv.de/webservices/rest-api/v2/';
    private const HTTP_TIMEOUT = 15;
    private const STATION_CACHE_TTL = 86400; // Stationsliste 1x täglich neu laden

    // Statuscodes
    private const STATUS_ACTIVE = 102;
    private const STATUS_INACTIVE = 104;
    private const STATUS_API_ERROR = 201;
    private const STATUS_STATION_UNKNOWN = 202;

    // Status-Werte von PEGELONLINE -> Profilwert PGL.Status
    private const STATE_MAP = [
        'low'    => 1,
        'normal' => 2,
        'high'   => 3,
    ];

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        // Eigenschaften (PEGELSTATION bleibt aus Kompatibilitätsgründen gleich benannt)
        $this->RegisterPropertyString('PEGELSTATION', 'DRESDEN');
        $this->RegisterPropertyInteger('UpdateMinutes', 15);
        $this->RegisterPropertyInteger('TrendHours', 3);
        $this->RegisterPropertyFloat('TrendThreshold', 2.0);
        $this->RegisterPropertyInteger('Alarmstufe1', 0);
        $this->RegisterPropertyInteger('Alarmstufe2', 0);
        $this->RegisterPropertyInteger('Alarmstufe3', 0);
        $this->RegisterPropertyInteger('Alarmstufe4', 0);

        // Cache für die Stationsliste
        $this->RegisterAttributeString('StationCache', '');
        $this->RegisterAttributeInteger('StationCacheTime', 0);

        $this->RegisterTimer('UpdatePegelstand', 0, 'PGL_Update($_IPS[\'TARGET\']);');

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
    }

    public function Destroy()
    {
        //Never delete this line!!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        $this->CreateProfiles();
        $this->MaintainVariables();

        $station = $this->GetStation();
        if ($station === '') {
            $this->SetTimerInterval('UpdatePegelstand', 0);
            $this->SetStatus(self::STATUS_INACTIVE);
            return;
        }

        $this->SetTimerInterval('UpdatePegelstand', max(1, $this->ReadPropertyInteger('UpdateMinutes')) * 60 * 1000);

        // Beim Systemstart erst nach KR_READY abrufen
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->Update();
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    /**
     * Aktualisiert alle Werte (PGL_Update).
     */
    public function Update(): bool
    {
        $station = $this->GetStation();
        if ($station === '') {
            $this->SetStatus(self::STATUS_INACTIVE);
            return false;
        }

        $enc = rawurlencode($station);
        $stationData = $this->ApiGet('stations/' . $enc . '.json?includeTimeseries=true&includeCurrentMeasurement=true', $httpCode);
        if ($stationData === null) {
            $this->SetStatus($httpCode === 404 ? self::STATUS_STATION_UNKNOWN : self::STATUS_API_ERROR);
            return false;
        }

        $w = $this->FindTimeseries($stationData, 'W');
        if ($w === null || !isset($w['currentMeasurement']['value'])) {
            $this->SendDebug('Update', 'Station liefert keinen Wasserstand (W)', 0);
            $this->SetStatus(self::STATUS_STATION_UNKNOWN);
            return false;
        }

        // Wasserstand + Messzeitpunkt
        $current = (float) $w['currentMeasurement']['value'];
        $this->SetValue('Pegelaktuell', $current);

        $ts = strtotime((string) ($w['currentMeasurement']['timestamp'] ?? ''));
        if ($ts !== false) {
            $this->SetValue('Messzeitpunkt', $ts);
        }

        // Status MNW/MHW und NSW/HSW
        $this->SetValue('StatusMnwMhw', $this->MapState($w['currentMeasurement']['stateMnwMhw'] ?? null));
        $this->SetValue('StatusNswHsw', $this->MapState($w['currentMeasurement']['stateNswHsw'] ?? null));

        // Abfluss (nicht jede Station hat Q)
        $q = $this->FindTimeseries($stationData, 'Q');
        $hasQ = $q !== null && isset($q['currentMeasurement']['value']);
        $this->MaintainVariable('Abfluss', $this->Translate('Abfluss'), VARIABLETYPE_FLOAT, 'PGL.Abfluss', 3, $hasQ);
        if ($hasQ) {
            $this->SetValue('Abfluss', (float) $q['currentMeasurement']['value']);
        }

        // Alarmstufe
        if ($this->HasAlarmLevels()) {
            $this->SetValue('Alarmstufe', $this->CalculateAlarmLevel($current));
        }

        // Tendenz aus der Zeitreihe der letzten Stunden
        $hours = max(1, $this->ReadPropertyInteger('TrendHours'));
        $measurements = $this->ApiGet('stations/' . $enc . '/W/measurements.json?start=PT' . $hours . 'H', $httpCode);
        if (is_array($measurements)) {
            $rate = $this->CalculateRate($measurements);
            if ($rate !== null) {
                $this->SetValue('Aenderung', round($rate, 1));
                $this->SetValue('Tendenz', $this->RateToTrend($rate, $hours));
            }
        } else {
            $this->SendDebug('Update', 'Messwerte für Tendenz nicht abrufbar', 0);
        }

        $this->SetStatus(self::STATUS_ACTIVE);
        return true;
    }

    /**
     * Lädt die Stationsliste neu und aktualisiert das Konfigurationsformular (PGL_ReloadStations).
     */
    public function ReloadStations(): void
    {
        $this->WriteAttributeInteger('StationCacheTime', 0);
        $this->GetStations();
        $this->ReloadForm();
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $options = [];
        $current = $this->GetStation();
        $found = false;
        foreach ($this->GetStations() as $s) {
            $options[] = ['caption' => $s['caption'], 'value' => $s['value']];
            if ($s['value'] === $current) {
                $found = true;
            }
        }
        // Aktuelle Station immer anbieten, auch wenn die Liste nicht geladen werden konnte
        if (!$found && $current !== '') {
            array_unshift($options, ['caption' => $current, 'value' => $current]);
        }
        if (count($options) === 0) {
            $options[] = ['caption' => '- Stationsliste nicht verfügbar -', 'value' => ''];
        }

        $this->SetFormOptions($form['elements'], 'PEGELSTATION', $options);

        return json_encode($form);
    }

    // ------------------------------------------------------------------
    // Berechnungen
    // ------------------------------------------------------------------

    /**
     * Steigung der Regressionsgeraden in cm/h.
     * Erwartet [['timestamp' => ISO8601, 'value' => float], ...]
     */
    private function CalculateRate(array $measurements): ?float
    {
        $points = [];
        foreach ($measurements as $m) {
            if (!isset($m['timestamp'], $m['value'])) {
                continue;
            }
            $t = strtotime((string) $m['timestamp']);
            if ($t === false) {
                continue;
            }
            $points[] = [$t / 3600.0, (float) $m['value']];
        }

        $n = count($points);
        if ($n < 2) {
            return null;
        }

        $sx = $sy = $sxx = $sxy = 0.0;
        foreach ($points as [$x, $y]) {
            $sx += $x;
            $sy += $y;
            $sxx += $x * $x;
            $sxy += $x * $y;
        }
        $den = $n * $sxx - $sx * $sx;
        if (abs($den) < 1e-9) {
            return null;
        }
        return ($n * $sxy - $sx * $sy) / $den;
    }

    private function RateToTrend(float $rate, int $hours): int
    {
        $threshold = abs($this->ReadPropertyFloat('TrendThreshold'));
        $delta = $rate * $hours;
        if ($delta >= $threshold) {
            return 1;
        }
        if ($delta <= -$threshold) {
            return -1;
        }
        return 0;
    }

    private function CalculateAlarmLevel(float $value): int
    {
        $level = 0;
        for ($i = 1; $i <= 4; $i++) {
            $limit = $this->ReadPropertyInteger('Alarmstufe' . $i);
            if ($limit > 0 && $value >= $limit) {
                $level = $i;
            }
        }
        return $level;
    }

    private function HasAlarmLevels(): bool
    {
        for ($i = 1; $i <= 4; $i++) {
            if ($this->ReadPropertyInteger('Alarmstufe' . $i) > 0) {
                return true;
            }
        }
        return false;
    }

    private function MapState($state): int
    {
        return self::STATE_MAP[strtolower((string) $state)] ?? 0;
    }

    private function FindTimeseries(array $station, string $shortname): ?array
    {
        foreach ($station['timeseries'] ?? [] as $ts) {
            if (strtoupper((string) ($ts['shortname'] ?? '')) === $shortname) {
                return $ts;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Stationen
    // ------------------------------------------------------------------

    /**
     * Stationsname aus der Eigenschaft; alte URL-kodierte Werte (z. B. "ALSLEBEN%20OP") werden dekodiert.
     */
    private function GetStation(): string
    {
        return trim(rawurldecode($this->ReadPropertyString('PEGELSTATION')));
    }

    private function GetStations(): array
    {
        $cache = json_decode($this->ReadAttributeString('StationCache'), true);
        $age = time() - $this->ReadAttributeInteger('StationCacheTime');
        if (is_array($cache) && count($cache) > 0 && $age < self::STATION_CACHE_TTL) {
            return $cache;
        }

        $data = $this->ApiGet('stations.json', $httpCode);
        if (!is_array($data)) {
            return is_array($cache) ? $cache : [];
        }

        $list = [];
        foreach ($data as $s) {
            $name = (string) ($s['shortname'] ?? '');
            if ($name === '') {
                continue;
            }
            $water = (string) ($s['water']['longname'] ?? $s['water']['shortname'] ?? '');
            $caption = $water !== '' ? $water . ' – ' . $name : $name;
            if (isset($s['km'])) {
                $caption .= ' (km ' . number_format((float) $s['km'], 1, ',', '') . ')';
            }
            $list[] = ['caption' => $caption, 'value' => $name];
        }
        usort($list, static function ($a, $b) {
            return strnatcasecmp($a['caption'], $b['caption']);
        });

        $this->WriteAttributeString('StationCache', json_encode($list));
        $this->WriteAttributeInteger('StationCacheTime', time());
        return $list;
    }

    private function SetFormOptions(array &$elements, string $name, array $options): bool
    {
        foreach ($elements as &$el) {
            if (($el['name'] ?? '') === $name) {
                $el['options'] = $options;
                return true;
            }
            if (isset($el['items']) && is_array($el['items']) && $this->SetFormOptions($el['items'], $name, $options)) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------
    // HTTP
    // ------------------------------------------------------------------

    private function ApiGet(string $path, ?int &$httpCode = null)
    {
        $url = self::API_BASE . $path;
        $this->SendDebug('HTTP', $url, 0);

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => self::HTTP_TIMEOUT,
                'ignore_errors' => true,
                'header'        => "Accept: application/json\r\nUser-Agent: IP-Symcon jniPegelstand\r\n",
            ],
        ]);

        $body = @file_get_contents($url, false, $ctx);
        $httpCode = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                    $httpCode = (int) $m[1];
                }
            }
        }

        if ($body === false || $httpCode !== 200) {
            $this->SendDebug('HTTP', 'Fehler, HTTP ' . $httpCode, 0);
            if ($httpCode !== 404) {
                $this->LogMessage('PEGELONLINE nicht erreichbar (HTTP ' . $httpCode . '): ' . $url, KL_WARNING);
            }
            return null;
        }

        $this->SendDebug('HTTP', $body, 0);
        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }

    // ------------------------------------------------------------------
    // Variablen & Profile
    // ------------------------------------------------------------------

    private function MaintainVariables(): void
    {
        // Idents "Pegelaktuell" und "Tendenz" bleiben gleich -> bestehende Variablen/Archive bleiben erhalten
        $this->RegisterVariableFloat('Pegelaktuell', $this->Translate('Pegelstand aktuell'), 'PGL.Pegelstand', 0);
        $this->RegisterVariableInteger('Tendenz', $this->Translate('Tendenz'), 'PGL.Tendenz', 1);
        $this->RegisterVariableFloat('Aenderung', $this->Translate('Änderung'), 'PGL.Rate', 2);
        $this->RegisterVariableInteger('Messzeitpunkt', $this->Translate('Messzeitpunkt'), '~UnixTimestamp', 4);
        $this->RegisterVariableInteger('StatusMnwMhw', $this->Translate('Status (MNW/MHW)'), 'PGL.Status', 5);
        $this->RegisterVariableInteger('StatusNswHsw', $this->Translate('Status Schifffahrt (NSW/HSW)'), 'PGL.Status', 6);
        $this->MaintainVariable('Alarmstufe', $this->Translate('Alarmstufe'), VARIABLETYPE_INTEGER, 'PGL.Alarmstufe', 7, $this->HasAlarmLevels());
    }

    private function CreateProfiles(): void
    {
        $this->EnsureProfile('PGL.Pegelstand', VARIABLETYPE_FLOAT, 'Wave', '', ' cm', 0, 0, 0, 0, []);
        $this->EnsureProfile('PGL.Abfluss', VARIABLETYPE_FLOAT, 'Drops', '', ' m³/s', 0, 0, 0, 1, []);
        $this->EnsureProfile('PGL.Rate', VARIABLETYPE_FLOAT, 'Graph', '', ' cm/h', 0, 0, 0, 1, []);
        $this->EnsureProfile('PGL.Tendenz', VARIABLETYPE_INTEGER, 'Graph', '', '', -1, 1, 1, 0, [
            [-1, $this->Translate('fallend'), 'HollowArrowDown', -1],
            [0, $this->Translate('gleichbleibend'), 'HollowArrowRight', -1],
            [1, $this->Translate('steigend'), 'HollowArrowUp', -1],
        ]);
        $this->EnsureProfile('PGL.Status', VARIABLETYPE_INTEGER, 'Information', '', '', 0, 3, 1, 0, [
            [0, $this->Translate('unbekannt'), '', -1],
            [1, $this->Translate('niedrig'), '', 0x3399FF],
            [2, $this->Translate('normal'), '', 0x00CC00],
            [3, $this->Translate('hoch'), '', 0xFF6600],
        ]);
        $this->EnsureProfile('PGL.Alarmstufe', VARIABLETYPE_INTEGER, 'Warning', '', '', 0, 4, 1, 0, [
            [0, $this->Translate('keine'), '', 0x00CC00],
            [1, $this->Translate('Alarmstufe 1'), '', 0xFFD700],
            [2, $this->Translate('Alarmstufe 2'), '', 0xFF9900],
            [3, $this->Translate('Alarmstufe 3'), '', 0xFF3300],
            [4, $this->Translate('Alarmstufe 4'), '', 0x990000],
        ]);
    }

    /**
     * Legt ein Profil an bzw. bringt ein bestehendes (auch das alte, fehlerhafte PGL.Pegelstand) auf Sollstand.
     */
    private function EnsureProfile(string $name, int $type, string $icon, string $prefix, string $suffix,
                                   float $min, float $max, float $step, int $digits, array $associations): void
    {
        if (IPS_VariableProfileExists($name)) {
            $p = IPS_GetVariableProfile($name);
            if ($p['ProfileType'] !== $type) {
                IPS_DeleteVariableProfile($name);
            }
        }
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, $type);
        }

        IPS_SetVariableProfileIcon($name, $icon);
        IPS_SetVariableProfileText($name, $prefix, $suffix);
        IPS_SetVariableProfileValues($name, $min, $max, $step);
        if ($type === VARIABLETYPE_FLOAT) {
            IPS_SetVariableProfileDigits($name, $digits);
        }

        // Nicht gewünschte Assoziationen entfernen (Name und Icon leer = löschen)
        $wanted = array_map(static function ($a) {
            return (float) $a[0];
        }, $associations);
        foreach (IPS_GetVariableProfile($name)['Associations'] as $a) {
            if (!in_array((float) $a['Value'], $wanted, true)) {
                IPS_SetVariableProfileAssociation($name, $a['Value'], '', '', -1);
            }
        }
        foreach ($associations as [$value, $text, $aIcon, $color]) {
            IPS_SetVariableProfileAssociation($name, $value, $text, $aIcon, $color);
        }
    }
}
