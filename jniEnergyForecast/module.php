<?php

declare(strict_types=1);

/**
 * jniEnergyForecast
 *
 * Verbrauchsprognose + Kostenrechnung für einen archivierten Zähler (Strom, Gas, Wasser ...).
 * Idee und Konzept: "RS Energy Forecast" von Raketenschnecke
 * (https://www.raketenschnecke.net/rs-projekte/rs-ips-energy-forecast/) – vielen Dank!
 * Dies ist eine eigenständige Neuimplementierung ohne Code aus dem Original.
 *
 * Prognose-Logik (Standard):
 *  - Heute:        Ist bis jetzt + Stundenprofil (gleicher Wochentag, letzte n Wochen) für den Rest des Tages
 *  - Folgetage:    7-Tage-Mittel um den gleichen Kalendertag 1..3 Jahre zurück
 *                  x Niveaufaktor (letzte 28 Tage ggü. gleichem Zeitraum im Vergleichsjahr, begrenzt 0,75..1,25)
 *                  x Wochentagsfaktor (aus den letzten n Wochen)
 *                  Fallback ohne Historie: Wochentags-Median der letzten n Wochen
 *  - Plan:         Planverbrauch saisonal verteilt nach dem Verlauf der Vorperiode
 *
 * Witterungsbereinigte Prognose (optional, sobald eine archivierte Außentemperatur gewählt ist):
 *  - Heizgradtage (HGT) = max(0, Heizgrenze − Tagesmittel der Außentemperatur), Heizgrenze Standard 15 °C
 *  - Tagesverbrauch = Grundlast + Faktor × HGT, per linearer Regression aus den letzten 365 Tagen
 *    (nur Heiztage mit HGT ≥ 1 und Verbrauch > 0, ein Durchlauf Ausreißer-Bereinigung bei 2,5 σ)
 *  - optional getrennt nach Werktag und Wochenende/Feiertag (Feiertage je Bundesland)
 *  - Tage ohne Heizbedarf (HGT = 0): Median der Sommertage (Warmwasser / Heizung aus)
 *  - Temperatur der nächsten Tage aus Open-Meteo (falls aktiviert), danach Mittel der Vorjahre
 *  - Kennzahlen: witterungsbereinigter Verbrauch ggü. Vorjahr, Verbrauch je Heizgradtag, Modellgüte R²
 *
 * Gas:
 *  - Zähler in m³, Umrechnung in kWh = m³ × Brennwert × Zustandszahl (Werte von der Gasrechnung)
 *  - Arbeitspreis und vertraglicher Jahresverbrauch in kWh, Anzeige in m³ und kWh
 *  - "Grundlast" als Verbrauch je Tag (statt W)
 *
 * Zählerwechsel:
 *  - Frühere Zähler werden mit Ausbautag und eigenem Faktor angegeben. Bis einschließlich Ausbautag zählt der
 *    alte Zähler, ab Ausbautag der Nachfolger (am Wechseltag werden beide addiert – jeder liefert nur in
 *    seiner Laufzeit Zuwächse).
 *  - Optional wird die Lücke zwischen letztem Wert des alten und erstem Wert des neuen Zählers geschätzt:
 *    (erster Wert Nachfolger − Anfangsstand) × Faktor des Nachfolgers, gleichmäßig auf die Stunden verteilt.
 *
 * Ausgabe:
 *  - einfache Float-Variablen mit klassischen Profilen (IPSView-tauglich)
 *  - Diagramme (Chart.js) über WebHook /hook/efc<InstanzID>?chart=dashboard|today|week|months|period|model
 *    Parameter: theme=dark|light, bg=<hex>|transparent, refresh=<Sekunden>, header=0|1
 */
class jniEnergyForecast extends IPSModule
{
    private const ARCHIVE_GUID  = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const WEBHOOK_GUID  = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';
    private const LOCATION_GUID = '{45E97A63-F870-408A-B259-2933F7EABF74}';
    private const CHARTJS_URL   = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
    private const OPENMETEO_URL = 'https://api.open-meteo.com/v1/forecast';

    private const STATUS_NO_COUNTER     = 200;
    private const STATUS_NOT_COUNTER    = 201;
    private const STATUS_TOO_FEW_DATA   = 202;
    private const STATUS_BAD_PERIOD     = 203;
    private const STATUS_BAD_CHANGE     = 204;
    private const STATUS_BAD_TEMP       = 205;
    private const STATUS_BAD_TEMP_FROM  = 206;
    private const STATUS_BAD_GAS        = 207;

    private const METER_DEFAULT = 0;
    private const METER_GAS     = 1;

    private const MONTH_NAMES   = [1 => 'Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
    private const WEEKDAY_NAMES = [1 => 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
    private const WEEKDAY_LONG  = [1 => 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];

    /** Variablen, die bei aktivierter Option archiviert werden (für Verlaufsdiagramme in IPSView) */
    private const LOGGED_IDENTS = [
        'TodayForecast', 'PeriodActual', 'PeriodForecast', 'PeriodBalance', 'BaseLoad', 'BaseLoadDay',
        'HddYesterday', 'WeatherAdjDeviation', 'PerHdd', 'PerHdd30',
    ];

    // Laufzeit-Caches während einer Berechnung
    private array $daily = [];
    private array $levelCache = [];
    private array $weekdayFactor = [];
    private array $weekdayMedian = [];
    private int $todayTs = 0;
    private array $segments = [];
    private array $gapHours = [];
    private array $temps = [];          // 'Y-m-d' => Tagesmittel Außentemperatur (Archiv)
    private array $fcTemps = [];        // 'Y-m-d' => Tagesmittel Vorhersage (Open-Meteo)
    private ?array $model = null;       // Heizgradtag-Modell
    private array $hddCache = [];
    private array $tempCache = [];
    private array $holidayCache = [];

    public function Create()
    {
        parent::Create();

        // Zähler
        $this->RegisterPropertyInteger('CounterVariableID', 0);
        $this->RegisterPropertyFloat('ScaleFactor', 1.0);
        $this->RegisterPropertyString('Unit', 'kWh');
        $this->RegisterPropertyString('MeterChanges', '[]');
        $this->RegisterPropertyInteger('MeterType', self::METER_DEFAULT);   // 0 = Strom/allgemein, 1 = Gas
        $this->RegisterPropertyFloat('CalorificValue', 11.0);               // Brennwert kWh/m³ (Gasrechnung)
        $this->RegisterPropertyFloat('ZNumber', 0.95);                      // Zustandszahl (Gasrechnung)

        // Vertrag
        $this->RegisterPropertyString('PeriodStart', '01.01.');
        $this->RegisterPropertyFloat('PricePerUnit', 0.0);      // ct je Einheit (Gas: je kWh), brutto
        $this->RegisterPropertyFloat('BasePrice', 0.0);         // € je Monat, brutto
        $this->RegisterPropertyFloat('MonthlyAdvance', 0.0);    // Abschlag € je Monat
        $this->RegisterPropertyFloat('PlannedAnnual', 0.0);     // vertraglicher Jahresverbrauch (Gas: kWh)
        $this->RegisterPropertyFloat('Bonus', 0.0);             // € je Periode
        $this->RegisterPropertyString('ManualBookings', '[]');

        // Witterung
        $this->RegisterPropertyInteger('TemperatureVariableID', 0);
        $this->RegisterPropertyFloat('HeatingLimit', 15.0);
        $this->RegisterPropertyString('TemperatureValidFrom', '');
        $this->RegisterPropertyBoolean('SplitDayType', false);
        $this->RegisterPropertyString('HolidayState', 'SN');
        $this->RegisterPropertyBoolean('UseOpenMeteo', true);
        $this->RegisterPropertyFloat('Latitude', 0.0);
        $this->RegisterPropertyFloat('Longitude', 0.0);

        // Berechnung
        $this->RegisterPropertyInteger('ProfileWeeks', 7);
        $this->RegisterPropertyInteger('UpdateInterval', 15);
        $this->RegisterPropertyBoolean('LogVariables', true);
        $this->RegisterPropertyBoolean('MonthVariables', true);

        $this->RegisterAttributeString('WeatherCache', '');

        $this->RegisterTimer('UpdateTimer', 0, 'EFC_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        $gas     = $this->IsGas();
        $weather = $this->ReadPropertyInteger('TemperatureVariableID') > 0;
        $energy  = $this->EnsureProfiles();

        // ---------- Variablen ----------
        $pos = 0;
        $this->RegisterVariableFloat('TodayActual', 'Heute Ist', $energy, $pos += 10);
        $this->RegisterVariableFloat('TodayForecast', 'Heute Prognose', $energy, $pos += 10);
        $this->RegisterVariableFloat('TodayPercent', 'Heute Prognose (% vom Ø)', 'EFC.Percent', $pos += 10);
        $this->RegisterVariableFloat('WeekActual', 'Woche Ist', $energy, $pos += 10);
        $this->RegisterVariableFloat('MonthActual', 'Monat Ist', $energy, $pos += 10);
        $this->RegisterVariableFloat('MonthForecast', 'Monat Prognose', $energy, $pos += 10);
        $this->RegisterVariableFloat('PeriodActual', 'Periode Ist', $energy, $pos += 10);
        $this->RegisterVariableFloat('PeriodForecast', 'Periode Prognose', $energy, $pos += 10);
        $this->RegisterVariableFloat('PeriodPlanDeviation', 'Periode Abweichung zum Plan', 'EFC.Deviation', $pos += 10);
        $this->RegisterVariableFloat('PeriodCost', 'Periode Kosten (Prognose)', 'EFC.Euro', $pos += 10);
        $this->RegisterVariableFloat('PeriodPaid', 'Periode Abschläge + Buchungen', 'EFC.Euro', $pos += 10);
        $this->RegisterVariableFloat('PeriodBalance', 'Periode Saldo (+ Guthaben / − Nachzahlung)', 'EFC.Euro', $pos += 10);
        $this->RegisterVariableFloat('NextConsumption', 'Folgeperiode Verbrauch (Prognose)', $energy, $pos += 10);
        $this->RegisterVariableFloat('NextCost', 'Folgeperiode Kosten (Prognose)', 'EFC.Euro', $pos += 10);
        $this->RegisterVariableFloat('NextAdvance', 'Folgeperiode empfohlener Abschlag', 'EFC.Euro', $pos += 10);
        if ($gas) {
            $this->UnregisterVariable('BaseLoad');
            $this->RegisterVariableFloat('BaseLoadDay', 'Grundlast je Tag', $energy, $pos += 10);
        } else {
            $this->UnregisterVariable('BaseLoadDay');
            $this->RegisterVariableFloat('BaseLoad', 'Grundlast (Ø 7 Tage)', 'EFC.Watt', $pos += 10);
        }
        $this->RegisterVariableInteger('LastUpdate', 'Letzte Berechnung', '~UnixTimestamp', $pos += 10);

        for ($m = 1; $m <= 12; $m++) {
            $ident = sprintf('M%02d', $m);
            if ($this->ReadPropertyBoolean('MonthVariables')) {
                $this->RegisterVariableFloat($ident, sprintf('Monat %02d', $m), $energy, 200 + $m);
            } else {
                $this->UnregisterVariable($ident);
            }
        }

        // Gas: Werte zusätzlich in kWh
        $kwhVars = [
            'TodayForecastKWh'   => 'Heute Prognose (kWh)',
            'MonthForecastKWh'   => 'Monat Prognose (kWh)',
            'PeriodActualKWh'    => 'Periode Ist (kWh)',
            'PeriodForecastKWh'  => 'Periode Prognose (kWh)',
            'NextConsumptionKWh' => 'Folgeperiode Verbrauch (kWh)',
            'BaseLoadDayKWh'     => 'Grundlast je Tag (kWh)',
        ];
        $pos = 300;
        foreach ($kwhVars as $ident => $name) {
            if ($gas) {
                $this->RegisterVariableFloat($ident, $name, 'EFC.Energy.kWh', $pos += 10);
            } else {
                $this->UnregisterVariable($ident);
            }
        }

        // Witterung
        $perUnit = $gas ? 'kWh' : trim($this->ReadPropertyString('Unit'));
        $weatherVars = [
            'HddYesterday'        => ['Heizgradtage gestern', 'EFC.HDD'],
            'HddPeriod'           => ['Heizgradtage Periode bisher', 'EFC.HDD'],
            'HddPeriodPrev'       => ['Heizgradtage Vorjahr (gleicher Zeitraum)', 'EFC.HDD'],
            'WeatherAdjDeviation' => ['Witterungsbereinigt ggü. Vorjahr', 'EFC.Deviation'],
            'PerHdd'              => [$perUnit . ' je Heizgradtag (Modell)', $this->PerHddProfile()],
            'PerHdd30'            => [$perUnit . ' je Heizgradtag (30 Tage)', $this->PerHddProfile()],
            'ModelR2'             => ['Modellgüte R²', 'EFC.R2'],
        ];
        $pos = 400;
        foreach ($weatherVars as $ident => [$name, $profile]) {
            if ($weather) {
                $this->RegisterVariableFloat($ident, $name, $profile, $pos += 10);
            } else {
                $this->UnregisterVariable($ident);
            }
        }

        // ---------- Archivierung ----------
        $ac = $this->ArchiveID();
        if ($ac > 0) {
            $log = $this->ReadPropertyBoolean('LogVariables');
            $changed = false;
            foreach (self::LOGGED_IDENTS as $ident) {
                $vid = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
                if ($vid === false) {
                    continue;
                }
                if (AC_GetLoggingStatus($ac, $vid) !== $log) {
                    AC_SetLoggingStatus($ac, $vid, $log);
                    $changed = true;
                }
            }
            if ($changed) {
                IPS_ApplyChanges($ac);
            }
        }

        // ---------- Referenzen ----------
        foreach ($this->GetReferenceList() as $ref) {
            $this->UnregisterReference($ref);
        }
        foreach ([$this->ReadPropertyInteger('CounterVariableID'), $this->ReadPropertyInteger('TemperatureVariableID')] as $rid) {
            if ($rid > 0 && IPS_VariableExists($rid)) {
                $this->RegisterReference($rid);
            }
        }
        foreach ($this->ParseMeterChanges() ?? [] as $c) {
            if (IPS_VariableExists($c['vid'])) {
                $this->RegisterReference($c['vid']);
            }
        }

        // Wettervorhersage nach Konfigurationsänderung neu holen
        $this->WriteAttributeString('WeatherCache', '');

        // ---------- WebHook ----------
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->RegisterHook($this->HookPath());
        }

        // ---------- Prüfung + Timer ----------
        $status = $this->CheckConfiguration();
        $this->SetStatus($status);
        if ($status !== 102) {
            $this->SetTimerInterval('UpdateTimer', 0);
            return;
        }
        $this->SetTimerInterval('UpdateTimer', max(1, $this->ReadPropertyInteger('UpdateInterval')) * 60 * 1000);

        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->Update();
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->RegisterHook($this->HookPath());
            if ($this->GetStatus() === 102) {
                $this->Update();
            }
        }
    }

    public function GetConfigurationForm()
    {
        $form = file_get_contents(__DIR__ . '/form.json');
        return str_replace('%HOOK%', $this->HookPath(), $form);
    }

    /**
     * Berechnet alle Werte neu und schreibt die Variablen. Aufruf per Timer oder EFC_Update($id).
     */
    public function Update(): bool
    {
        $d = $this->Compute();
        if ($d === null) {
            return false;
        }
        $k  = $d['kpi'];
        $kf = $d['kwhFactor'];

        $this->SetValue('TodayActual', round($k['todayActual'], 2));
        $this->SetValue('TodayForecast', round($k['todayForecast'], 2));
        $this->SetValue('TodayPercent', round($k['todayPercent'], 0));
        $this->SetValue('WeekActual', round($k['weekActual'], 2));
        $this->SetValue('MonthActual', round($k['monthActual'], 2));
        $this->SetValue('MonthForecast', round($k['monthForecast'], 1));
        $this->SetValue('PeriodActual', round($k['periodActual'], 2));
        $this->SetValue('PeriodForecast', round($k['periodForecast'], 1));
        $this->SetValue('PeriodPlanDeviation', round($k['planDeviation'], 1));
        $this->SetValue('PeriodCost', round($k['periodCost'], 2));
        $this->SetValue('PeriodPaid', round($k['periodPaid'], 2));
        $this->SetValue('PeriodBalance', round($k['balance'], 2));
        $this->SetValue('NextConsumption', round($k['nextCons'], 0));
        $this->SetValue('NextCost', round($k['nextCost'], 2));
        $this->SetValue('NextAdvance', (float) $k['nextAdvance']);
        $this->SetValue('LastUpdate', time());

        if ($d['gas']) {
            $this->SetIf('BaseLoadDay', round($k['baseLoadDay'], 2));
            $this->SetIf('TodayForecastKWh', round($k['todayForecast'] * $kf, 1));
            $this->SetIf('MonthForecastKWh', round($k['monthForecast'] * $kf, 0));
            $this->SetIf('PeriodActualKWh', round($k['periodActual'] * $kf, 0));
            $this->SetIf('PeriodForecastKWh', round($k['periodForecast'] * $kf, 0));
            $this->SetIf('NextConsumptionKWh', round($k['nextCons'] * $kf, 0));
            $this->SetIf('BaseLoadDayKWh', round($k['baseLoadDay'] * $kf, 1));
        } else {
            $this->SetIf('BaseLoad', round($k['baseLoad'], 0));
        }

        $w = $d['weather'];
        if ($w !== null) {
            $this->SetIf('HddYesterday', $w['hddYesterday']);
            $this->SetIf('HddPeriod', $w['hddPeriod']);
            $this->SetIf('HddPeriodPrev', $w['hddPeriodPrev']);
            $this->SetIf('WeatherAdjDeviation', $w['adjDeviation']);
            $this->SetIf('PerHdd', $w['perHdd']);
            $this->SetIf('PerHdd30', $w['perHdd30']);
            $this->SetIf('ModelR2', $w['r2']);
        }

        if ($this->ReadPropertyBoolean('MonthVariables')) {
            foreach ($d['months']['keys'] as $i => $ym) {
                $ident = sprintf('M%02d', $i + 1);
                $total = ($d['months']['act'][$i] ?? 0) + ($d['months']['fc'][$i] ?? 0);
                $this->SetValue($ident, round($total, 1));
                [$y, $m] = explode('-', $ym);
                $label = sprintf('Monat %02d: %s %s', $i + 1, self::MONTH_NAMES[(int) $m], $y);
                $vid = $this->GetIDForIdent($ident);
                if (IPS_GetName($vid) !== $label) {
                    IPS_SetName($vid, $label);
                }
            }
        }

        $this->SendDebug('Update', sprintf(
            'Periode %s | Ist %.1f | Prognose %.1f | heute %.2f (%.0f %%) | Niveau %.3f | Folgeperiode %.0f | Saldo %.2f €',
            $d['periodLabel'], $k['periodActual'], $k['periodForecast'], $k['todayForecast'], $k['todayPercent'],
            $d['level'], $k['nextCons'], $k['balance']
        ), 0);
        if ($w !== null) {
            $this->SendDebug('Witterung', $this->ModelText($d), 0);
        }

        return true;
    }

    /** Kurzbeschreibung des Heizgradtag-Modells (Button im Konfigurationsformular) */
    public function GetModelInfo(): string
    {
        $d = $this->Compute();
        if ($d === null) {
            return 'Keine Berechnung möglich – Status der Instanz prüfen.';
        }
        if ($d['weather'] === null) {
            return 'Keine Außentemperatur gewählt – witterungsbereinigte Prognose ist aus.';
        }
        return $this->ModelText($d);
    }

    // =====================================================================================
    // Berechnung
    // =====================================================================================

    private function Compute(): ?array
    {
        $status = $this->CheckConfiguration();
        if ($status !== 102) {
            $this->SetStatus($status);
            return null;
        }

        $ac    = $this->ArchiveID();
        $weeks = max(2, $this->ReadPropertyInteger('ProfileWeeks'));
        $now   = time();
        $today = mktime(0, 0, 0, (int) date('n'), (int) date('j'), (int) date('Y'));
        $this->todayTs    = $today;
        $this->levelCache = [];
        $this->hddCache   = [];
        $this->tempCache  = [];
        $this->PrepareCounter($ac);

        $gas       = $this->IsGas();
        $kf        = $this->KwhFactor();
        $weatherOn = $this->ReadPropertyInteger('TemperatureVariableID') > 0;

        [$pStart, $pEnd] = $this->PeriodBounds($today);
        $nStart = $this->AddDays($pEnd, 1);
        $nEnd   = $this->AddDays(mktime(0, 0, 0, (int) date('n', $nStart), (int) date('j', $nStart), (int) date('Y', $nStart) + 1), -1);

        // ---------- Tageswerte (bis gestern) ----------
        $histStart = min($this->AddDays($pStart, -400), mktime(0, 0, 0, (int) date('n'), (int) date('j') - 7, (int) date('Y') - 3));
        $this->daily = [];
        foreach ($this->CounterValues(1, $histStart, $today - 1) as $row) {
            $v = (float) $row['Avg'];
            if ($v >= 0) {
                $this->daily[date('Y-m-d', (int) $row['TimeStamp'])] = $v;
            }
        }
        if (count($this->daily) < 14) {
            $this->SetStatus(self::STATUS_TOO_FEW_DATA);
            return null;
        }
        $this->SetStatus(102);

        // ---------- Witterung: Temperaturen, Vorhersage, Modell ----------
        $this->temps   = [];
        $this->fcTemps = [];
        $this->model   = null;
        if ($weatherOn) {
            $this->LoadTemperatures($ac, $histStart, $now);
            $this->LoadForecast($today);
            $this->model = $this->FitModel($today);
        }

        // ---------- Heute Ist (gesamt + je Stunde) ----------
        $todayActual = 0.0;
        foreach ($this->CounterValues(1, $today, $now) as $row) {
            $todayActual += max(0.0, (float) $row['Avg']);
        }
        $todayH = array_fill(0, 24, 0.0);
        foreach ($this->CounterValues(0, $today, $now) as $row) {
            $todayH[(int) date('G', (int) $row['TimeStamp'])] += max(0.0, (float) $row['Avg']);
        }

        // ---------- Wochentagsstatistik der letzten n Wochen ----------
        $wdValues = [];
        for ($i = 1; $i <= $weeks * 7; $i++) {
            $ts  = $this->AddDays($today, -$i);
            $key = date('Y-m-d', $ts);
            if (isset($this->daily[$key])) {
                $wdValues[(int) date('N', $ts)][] = $this->daily[$key];
            }
        }
        $wdMean = [];
        $wdMin = [];
        $wdMax = [];
        for ($n = 1; $n <= 7; $n++) {
            $vals = $wdValues[$n] ?? [];
            $wdMean[$n] = count($vals) ? array_sum($vals) / count($vals) : null;
            $wdMin[$n]  = count($vals) ? min($vals) : null;
            $wdMax[$n]  = count($vals) ? max($vals) : null;
            $this->weekdayMedian[$n] = count($vals) ? $this->Median($vals) : 0.0;
        }
        $known = array_filter($wdMean, fn($v) => $v !== null);
        $overall = count($known) ? array_sum($known) / count($known) : 0.0;
        for ($n = 1; $n <= 7; $n++) {
            $this->weekdayFactor[$n] = ($overall > 0 && $wdMean[$n] !== null) ? $wdMean[$n] / $overall : 1.0;
        }

        // ---------- Stundenprofil, gestern, Grundlast ----------
        $profile   = [];
        $minHour   = [];
        $yesterday = $this->AddDays($today, -1);
        $yestH     = array_fill(0, 24, null);
        $wdToday   = (int) date('N', $today);
        foreach ($this->CounterValues(0, $this->AddDays($today, -$weeks * 7), $today - 1) as $row) {
            $ts = (int) $row['TimeStamp'];
            $v  = (float) $row['Avg'];
            if ($v < 0) {
                continue;
            }
            $h = (int) date('G', $ts);
            if ((int) date('N', $ts) === $wdToday) {
                $profile[$h][] = $v;
            }
            if ($ts >= $yesterday) {
                $yestH[$h] = ($yestH[$h] ?? 0) + $v;
            }
            if ($ts >= $this->AddDays($today, -7)) {
                $day = date('Y-m-d', $ts);
                $minHour[$day] = min($minHour[$day] ?? PHP_FLOAT_MAX, $v);
            }
        }
        $hourAvg = [];
        for ($h = 0; $h < 24; $h++) {
            $hourAvg[$h] = isset($profile[$h]) ? array_sum($profile[$h]) / count($profile[$h]) : 0.0;
        }
        $profileDay = array_sum($hourAvg);
        $baseLoad = count($minHour) ? array_sum($minHour) / count($minHour) * 1000 : 0.0;

        // Grundlast je Tag (Gas): Verbrauch an Tagen ohne Heizbedarf (Warmwasser/Sommer) aus dem Modell,
        // sonst Ø der 3 niedrigsten Tage der letzten 28 Tage
        $baseLoadDay = 0.0;
        if ($this->model !== null) {
            $baseLoadDay = $this->BaseDay();
        } else {
            $last = [];
            for ($i = 1; $i <= 28; $i++) {
                $key = date('Y-m-d', $this->AddDays($today, -$i));
                if (isset($this->daily[$key])) {
                    $last[] = $this->daily[$key];
                }
            }
            sort($last, SORT_NUMERIC);
            $low = array_slice($last, 0, 3);
            $baseLoadDay = count($low) ? array_sum($low) / count($low) : 0.0;
        }

        // ---------- Prognose heute (je Stunde) ----------
        // Mit Witterungsmodell: Tagessumme aus dem Modell, verteilt nach dem Stundenprofil
        $scale = 1.0;
        $uniform = null;
        if ($this->model !== null) {
            $hddToday = $this->ExpectedHdd($today);
            if ($hddToday !== null) {
                $modelToday = $this->ModelValue($today, $hddToday);
                if ($profileDay > 0) {
                    $scale = $modelToday / $profileDay;
                } else {
                    $uniform = $modelToday / 24;
                }
            }
        }
        $curHour  = (int) date('G', $now);
        $fracLeft = 1 - ((int) date('i', $now) * 60 + (int) date('s', $now)) / 3600;
        $actH = [];
        $fcH  = [];   // Prognose gesamt der Stunde (Ist + Rest) für die laufende und kommende Stunden
        $restToday = 0.0;
        for ($h = 0; $h < 24; $h++) {
            $hv = $uniform ?? $hourAvg[$h] * $scale;
            if ($h < $curHour) {
                $actH[$h] = round($todayH[$h], 3);
                $fcH[$h]  = null;
            } elseif ($h === $curHour) {
                $rest = $hv * $fracLeft;
                $restToday += $rest;
                $actH[$h] = round($todayH[$h], 3);
                $fcH[$h]  = round($todayH[$h] + $rest, 3);
            } else {
                $restToday += $hv;
                $actH[$h] = null;
                $fcH[$h]  = round($hv, 3);
            }
        }
        $todayForecast = $todayActual + $restToday;
        $todayPercent  = $profileDay > 0 ? $todayForecast / $profileDay * 100 : 0.0;

        // Außentemperatur heute je Stunde (Diagramm)
        $todayTemp = null;
        if ($weatherOn) {
            $todayTemp = array_fill(0, 24, null);
            foreach (AC_GetAggregatedValues($ac, $this->ReadPropertyInteger('TemperatureVariableID'), 0, $today, $now, 0) as $row) {
                $todayTemp[(int) date('G', (int) $row['TimeStamp'])] = round((float) $row['Avg'], 1);
            }
        }

        // ---------- Periode: Tagesreihe ----------
        $days = [];
        for ($ts = $pStart; $ts <= $pEnd; $ts = $this->AddDays($ts, 1)) {
            $prevTs  = mktime(0, 0, 0, (int) date('n', $ts), (int) date('j', $ts), (int) date('Y', $ts) - 1);
            $key     = date('Y-m-d', $ts);
            $prevKey = date('Y-m-d', $prevTs);
            $row = ['ts' => $ts, 'act' => null, 'fc' => 0.0, 'prev' => $this->daily[$prevKey] ?? null, 'hdd' => null, 'hddPrev' => null];
            if ($ts < $today) {
                $row['act'] = $this->daily[$key] ?? 0.0;
            } elseif ($ts === $today) {
                $row['act'] = $todayActual;
                $row['fc']  = $restToday;
            } else {
                $row['fc'] = $this->DayForecast($ts);
            }
            if ($weatherOn) {
                $row['hdd'] = $ts < $today ? (isset($this->temps[$key]) ? $this->Hdd($this->temps[$key]) : null) : $this->ExpectedHdd($ts);
                $row['hddPrev'] = isset($this->temps[$prevKey]) ? $this->Hdd($this->temps[$prevKey]) : null;
            }
            $days[] = $row;
        }
        $nDays = count($days);

        // Plan-Gewichte (saisonal nach Vorperiode, sonst linear)
        $prevVals = array_filter(array_column($days, 'prev'), fn($v) => $v !== null);
        $usePrev  = count($prevVals) >= $nDays * 0.9 && array_sum($prevVals) > 0;
        $prevMean = count($prevVals) ? array_sum($prevVals) / count($prevVals) : 0.0;
        $weights  = [];
        foreach ($days as $i => $r) {
            $weights[$i] = $usePrev ? ($r['prev'] ?? $prevMean) : 1.0;
        }
        $wSum = array_sum($weights);
        // Gas: vertraglicher Jahresverbrauch in kWh -> Zählereinheit
        $planned = $this->ReadPropertyFloat('PlannedAnnual') / $kf;

        // Kumuliert + Monate
        $months = ['keys' => [], 'labels' => [], 'act' => [], 'fc' => [], 'prev' => [], 'plan' => [], 'hdd' => [], 'hddPrev' => []];
        for ($i = 0; $i < 12; $i++) {
            $mts = mktime(0, 0, 0, (int) date('n', $pStart) + $i, 1, (int) date('Y', $pStart));
            $months['keys'][]    = date('Y-m', $mts);
            $months['labels'][]  = self::MONTH_NAMES[(int) date('n', $mts)] . ' ' . date('y', $mts);
            $months['act'][]     = 0.0;
            $months['fc'][]      = 0.0;
            $months['prev'][]    = 0.0;
            $months['plan'][]    = 0.0;
            $months['hdd'][]     = 0.0;
            $months['hddPrev'][] = 0.0;
        }
        $mIndex = array_flip($months['keys']);
        $prevMonthCount = array_fill(0, 12, 0);
        $hddCount       = array_fill(0, 12, 0);
        $hddPrevCount   = array_fill(0, 12, 0);

        $cum = ['labels' => [], 'ticks' => [], 'act' => [], 'fc' => [], 'prev' => [], 'plan' => []];
        $runAct = 0.0;
        $runAll = 0.0;
        $runPrev = 0.0;
        $runPlan = 0.0;
        $planToDate = 0.0;
        $periodActual = 0.0;
        $todayIdx = null;
        foreach ($days as $i => $r) {
            $mi = $mIndex[date('Y-m', $r['ts'])] ?? null;
            $act = $r['act'] ?? 0.0;
            $runAll += $act + $r['fc'];
            $runPlan += $planned > 0 && $wSum > 0 ? $planned * $weights[$i] / $wSum : 0;
            if ($r['prev'] !== null) {
                $runPrev += $r['prev'];
            }
            if ($r['act'] !== null) {
                $runAct += $act;
                $periodActual += $act;
            }
            if ($r['ts'] < $today && $planned > 0 && $wSum > 0) {
                $planToDate += $planned * $weights[$i] / $wSum;
            }
            if ($r['ts'] === $today) {
                $todayIdx = $i;
            }
            if ($mi !== null) {
                $months['act'][$mi] += $act;
                $months['fc'][$mi]  += $r['fc'];
                if ($r['prev'] !== null) {
                    $months['prev'][$mi] += $r['prev'];
                    $prevMonthCount[$mi]++;
                }
                $months['plan'][$mi] += $planned > 0 && $wSum > 0 ? $planned * $weights[$i] / $wSum : 0;
                if ($r['hdd'] !== null) {
                    $months['hdd'][$mi] += $r['hdd'];
                    $hddCount[$mi]++;
                }
                if ($r['hddPrev'] !== null) {
                    $months['hddPrev'][$mi] += $r['hddPrev'];
                    $hddPrevCount[$mi]++;
                }
            }
            $cum['labels'][] = date('d.m.Y', $r['ts']);
            $cum['ticks'][]  = (int) date('j', $r['ts']) === 1 ? self::MONTH_NAMES[(int) date('n', $r['ts'])] : '';
            $cum['act'][]    = $r['ts'] <= $today ? round($runAct, 1) : null;
            $cum['fc'][]     = $r['ts'] >= $today ? round($runAll, 1) : null;
            $cum['prev'][]   = round($runPrev, 1);
            $cum['plan'][]   = $planned > 0 ? round($runPlan, 1) : null;
        }
        // Prognoselinie an den Ist-Verlauf anschließen
        if ($todayIdx !== null && $todayIdx > 0) {
            $cum['fc'][$todayIdx - 1] = $cum['act'][$todayIdx - 1];
        }
        if (count($prevVals) === 0) {
            $cum['prev'] = [];
        }
        $periodForecast = $runAll;

        // Monate aufbereiten (Rundung, leere Werte -> null)
        for ($i = 0; $i < 12; $i++) {
            $mStart = strtotime($months['keys'][$i] . '-01');
            $months['act'][$i]     = $mStart <= $today ? round($months['act'][$i], 1) : null;
            $months['fc'][$i]      = $months['fc'][$i] > 0 ? round($months['fc'][$i], 1) : null;
            $months['prev'][$i]    = $prevMonthCount[$i] >= 20 ? round($months['prev'][$i], 1) : null;
            $months['plan'][$i]    = $planned > 0 ? round($months['plan'][$i], 1) : null;
            $months['hdd'][$i]     = $hddCount[$i] >= 20 ? round($months['hdd'][$i], 0) : null;
            $months['hddPrev'][$i] = $hddPrevCount[$i] >= 20 ? round($months['hddPrev'][$i], 0) : null;
        }
        if (!$weatherOn) {
            $months['hdd'] = [];
            $months['hddPrev'] = [];
        }
        $curMi = $mIndex[date('Y-m', $today)] ?? null;
        $monthActual   = $curMi !== null ? (float) $months['act'][$curMi] : 0.0;
        $monthForecast = $curMi !== null ? (float) $months['act'][$curMi] + (float) $months['fc'][$curMi] : 0.0;

        // ---------- Woche ----------
        $monday = $this->AddDays($today, -((int) date('N', $today) - 1));
        $week = ['labels' => [], 'act' => [], 'fc' => [], 'avg' => [], 'range' => [], 'tempAct' => [], 'tempFc' => []];
        $weekActual = 0.0;
        for ($n = 1; $n <= 7; $n++) {
            $ts  = $this->AddDays($monday, $n - 1);
            $key = date('Y-m-d', $ts);
            $week['labels'][] = self::WEEKDAY_NAMES[$n] . ' ' . date('d.m.', $ts);
            if ($ts < $today) {
                $v = $this->daily[$key] ?? 0.0;
                $week['act'][] = round($v, 2);
                $week['fc'][]  = null;
                $weekActual += $v;
            } elseif ($ts === $today) {
                $week['act'][] = round($todayActual, 2);
                $week['fc'][]  = round($todayForecast, 2);
                $weekActual += $todayActual;
            } else {
                $week['act'][] = null;
                $week['fc'][]  = round($this->DayForecast($ts), 2);
            }
            $week['avg'][]   = $wdMean[$n] !== null ? round($wdMean[$n], 2) : null;
            $week['range'][] = $wdMin[$n] !== null ? [round($wdMin[$n], 2), round($wdMax[$n], 2)] : null;
            if ($weatherOn) {
                $tAct = $ts < $today && isset($this->temps[$key]) ? round($this->temps[$key], 1) : null;
                $tFc  = $ts >= $today ? $this->ExpectedTemp($ts) : null;
                $week['tempAct'][] = $tAct;
                $week['tempFc'][]  = $tFc === null ? null : round($tFc, 1);
            }
        }
        // Temperaturlinie Prognose an Ist anschließen
        if ($weatherOn) {
            $ti = (int) date('N', $today) - 1;
            if ($ti > 0 && $week['tempAct'][$ti - 1] !== null) {
                $week['tempFc'][$ti - 1] = $week['tempAct'][$ti - 1];
            }
        } else {
            $week['tempAct'] = [];
            $week['tempFc']  = [];
        }

        // ---------- Plan-Abweichung ----------
        $planDeviation = 0.0;
        if ($planned > 0 && $planToDate > 0) {
            $planDeviation = (($periodActual - $todayActual) / $planToDate - 1) * 100;
        }

        // ---------- Kosten ----------
        // Gas: Arbeitspreis je kWh -> Preis je Zählereinheit
        $price   = $this->ReadPropertyFloat('PricePerUnit') / 100 * $kf;
        $base    = $this->ReadPropertyFloat('BasePrice');
        $advance = $this->ReadPropertyFloat('MonthlyAdvance');
        $bonus   = $this->ReadPropertyFloat('Bonus');

        $periodCost = 12 * $base + $periodForecast * $price;
        $periodPaid = 12 * $advance + $this->BookingsSum($pStart, $pEnd);
        $balance    = $periodPaid + $bonus - $periodCost;

        // ---------- Folgeperiode ----------
        $nextCons = 0.0;
        for ($ts = $nStart; $ts <= $nEnd; $ts = $this->AddDays($ts, 1)) {
            $nextCons += $this->DayForecast($ts);
        }
        $nextCost    = 12 * $base + $nextCons * $price;
        $nextAdvance = ceil($nextCost / 12);

        // ---------- Witterungs-Kennzahlen ----------
        $weatherData = $weatherOn ? $this->WeatherKpis($pStart, $today, $kf) : null;

        $hourLabels = [];
        for ($h = 0; $h < 24; $h++) {
            $hourLabels[] = sprintf('%02d', $h);
        }

        return [
            'unit'        => $this->ReadPropertyString('Unit'),
            'gas'         => $gas,
            'kwhFactor'   => $kf,
            'perUnit'     => $gas ? 'kWh' : $this->ReadPropertyString('Unit'),
            'price'       => $price,
            'basePrice'   => $base,
            'weeks'       => $weeks,
            'level'       => $this->LevelFactor(1),
            'periodLabel' => date('d.m.Y', $pStart) . ' – ' . date('d.m.Y', $pEnd),
            'nextLabel'   => date('d.m.Y', $nStart) . ' – ' . date('d.m.Y', $nEnd),
            'updated'     => date('d.m.Y H:i', $now),
            'kpi'         => [
                'todayActual'   => $todayActual,
                'todayForecast' => $todayForecast,
                'todayPercent'  => $todayPercent,
                'weekActual'    => $weekActual,
                'monthActual'   => $monthActual,
                'monthForecast' => $monthForecast,
                'periodActual'  => $periodActual,
                'periodForecast' => $periodForecast,
                'planDeviation' => $planDeviation,
                'periodCost'    => $periodCost,
                'periodPaid'    => $periodPaid,
                'balance'       => $balance,
                'nextCons'      => $nextCons,
                'nextCost'      => $nextCost,
                'nextAdvance'   => $nextAdvance,
                'baseLoad'      => $baseLoad,
                'baseLoadDay'   => $baseLoadDay,
            ],
            'today' => [
                'labels'  => $hourLabels,
                'wdName'  => self::WEEKDAY_LONG[$wdToday],
                'act'     => $actH,
                'fc'      => $fcH,
                'avg'     => array_map(fn($v) => round($v, 3), $hourAvg),
                'yest'    => array_map(fn($v) => $v === null ? null : round($v, 3), $yestH),
                'temp'    => $todayTemp,
                'curHour' => $curHour,
            ],
            'week'    => $week,
            'months'  => $months,
            'cum'     => $cum,
            'weather' => $weatherData,
        ];
    }

    /** Prognose für einen kompletten zukünftigen Tag */
    private function DayForecast(int $ts): float
    {
        // witterungsbereinigt über das Heizgradtag-Modell
        if ($this->model !== null) {
            $hdd = $this->ExpectedHdd($ts);
            if ($hdd !== null) {
                return $this->ModelValue($ts, $hdd);
            }
        }

        $wd = (int) date('N', $ts);
        for ($k = 1; $k <= 3; $k++) {
            $center = mktime(0, 0, 0, (int) date('n', $ts), (int) date('j', $ts), (int) date('Y', $ts) - $k);
            $vals = [];
            for ($o = -3; $o <= 3; $o++) {
                $d = $this->AddDays($center, $o);
                if ($d >= $this->todayTs) {
                    continue;
                }
                $key = date('Y-m-d', $d);
                if (isset($this->daily[$key])) {
                    $vals[] = $this->daily[$key];
                }
            }
            if (count($vals) >= 5) {
                return array_sum($vals) / count($vals) * $this->LevelFactor($k) * ($this->weekdayFactor[$wd] ?? 1.0);
            }
        }
        return $this->weekdayMedian[$wd] ?? 0.0;
    }

    /** Verbrauch der letzten 28 Tage ggü. gleichem Zeitraum k Jahre früher, begrenzt auf 0,75..1,25 */
    private function LevelFactor(int $k): float
    {
        if (isset($this->levelCache[$k])) {
            return $this->levelCache[$k];
        }
        $recent = 0.0;
        $prev = 0.0;
        $n = 0;
        for ($i = 1; $i <= 28; $i++) {
            $d  = $this->AddDays($this->todayTs, -$i);
            $dp = mktime(0, 0, 0, (int) date('n', $d), (int) date('j', $d), (int) date('Y', $d) - $k);
            $a = $this->daily[date('Y-m-d', $d)] ?? null;
            $b = $this->daily[date('Y-m-d', $dp)] ?? null;
            if ($a !== null && $b !== null) {
                $recent += $a;
                $prev += $b;
                $n++;
            }
        }
        $f = ($n >= 21 && $prev > 0) ? max(0.75, min(1.25, $recent / $prev)) : 1.0;
        return $this->levelCache[$k] = $f;
    }

    private function BookingsSum(int $from, int $to): float
    {
        $sum = 0.0;
        $list = json_decode($this->ReadPropertyString('ManualBookings'), true) ?: [];
        foreach ($list as $b) {
            $ts = $this->ParseDate((string) ($b['Date'] ?? ''));
            if ($ts !== null && $ts >= $from && $ts <= $to) {
                $sum += (float) ($b['Amount'] ?? 0);
            }
        }
        return $sum;
    }

    // =====================================================================================
    // Witterung / Heizgradtage
    // =====================================================================================

    private function Hdd(float $temp): float
    {
        return max(0.0, $this->ReadPropertyFloat('HeatingLimit') - $temp);
    }

    /** Tagesmittel der Außentemperatur aus dem Archiv (ab "gültig ab", plausibel −40..45 °C) */
    private function LoadTemperatures(int $ac, int $from, int $to): void
    {
        $tid = $this->ReadPropertyInteger('TemperatureVariableID');
        $validFrom = $this->ParseDate($this->ReadPropertyString('TemperatureValidFrom')) ?? 0;
        $from = max($from, $validFrom);
        if ($tid <= 0 || $to < $from) {
            return;
        }
        foreach (AC_GetAggregatedValues($ac, $tid, 1, $from, $to, 0) as $row) {
            $v = (float) $row['Avg'];
            if ($v < -40 || $v > 45) {
                continue;
            }
            $this->temps[date('Y-m-d', $this->BucketStart(1, (int) $row['TimeStamp']))] = $v;
        }
    }

    /** Tagesmittel der nächsten Tage von Open-Meteo, zwischengespeichert für eine Stunde */
    private function LoadForecast(int $today): void
    {
        if (!$this->ReadPropertyBoolean('UseOpenMeteo')) {
            return;
        }
        $cache = json_decode($this->ReadAttributeString('WeatherCache'), true);
        if (!is_array($cache) || ($cache['fetched'] ?? 0) < time() - 3600) {
            $coord = $this->Coordinates();
            if ($coord === null) {
                $this->SendDebug('Open-Meteo', 'Keine Koordinaten (Standort-Instanz oder Konfiguration)', 0);
                return;
            }
            $url = sprintf(
                '%s?latitude=%.4F&longitude=%.4F&daily=temperature_2m_mean,temperature_2m_max,temperature_2m_min&timezone=%s&forecast_days=8',
                self::OPENMETEO_URL, $coord[0], $coord[1], rawurlencode(date_default_timezone_get())
            );
            $raw  = @Sys_GetURLContentEx($url, ['Timeout' => 8000]);
            $json = is_string($raw) ? json_decode($raw, true) : null;
            if (isset($json['daily']['time']) && is_array($json['daily']['time'])) {
                $days = [];
                foreach ($json['daily']['time'] as $i => $date) {
                    $mean = $json['daily']['temperature_2m_mean'][$i] ?? null;
                    $max  = $json['daily']['temperature_2m_max'][$i] ?? null;
                    $min  = $json['daily']['temperature_2m_min'][$i] ?? null;
                    if ($mean === null && $max !== null && $min !== null) {
                        $mean = ($max + $min) / 2;
                    }
                    if ($mean !== null) {
                        $days[(string) $date] = (float) $mean;
                    }
                }
                $cache = ['fetched' => time(), 'days' => $days];
                $this->SendDebug('Open-Meteo', sprintf('%d Tage geladen', count($days)), 0);
            } else {
                // bei Fehler in ca. 10 Minuten erneut versuchen, alte Werte behalten
                $this->SendDebug('Open-Meteo', 'Abruf fehlgeschlagen: ' . $url, 0);
                $cache = ['fetched' => time() - 3000, 'days' => is_array($cache) ? ($cache['days'] ?? []) : []];
            }
            $this->WriteAttributeString('WeatherCache', json_encode($cache));
        }
        $todayKey = date('Y-m-d', $today);
        foreach ($cache['days'] ?? [] as $date => $temp) {
            if ($date >= $todayKey) {
                $this->fcTemps[$date] = (float) $temp;
            }
        }
    }

    /** @return array{0:float,1:float}|null Breite, Länge (Konfiguration, sonst Standort-Instanz) */
    private function Coordinates(): ?array
    {
        $lat = $this->ReadPropertyFloat('Latitude');
        $lon = $this->ReadPropertyFloat('Longitude');
        if ($lat != 0.0 || $lon != 0.0) {
            return [$lat, $lon];
        }
        $ids = IPS_GetInstanceListByModuleID(self::LOCATION_GUID);
        if (count($ids) > 0) {
            $loc = json_decode((string) @IPS_GetProperty($ids[0], 'Location'), true);
            if (isset($loc['latitude'], $loc['longitude'])) {
                return [(float) $loc['latitude'], (float) $loc['longitude']];
            }
        }
        return null;
    }

    /** Erwartete Außentemperatur eines Tages: Vorhersage, sonst Archiv (vergangen), sonst Mittel der Vorjahre */
    private function ExpectedTemp(int $ts): ?float
    {
        $key = date('Y-m-d', $ts);
        if (array_key_exists($key, $this->tempCache)) {
            return $this->tempCache[$key];
        }
        $v = $this->fcTemps[$key] ?? null;
        if ($v === null && $ts <= $this->todayTs) {
            $v = $this->temps[$key] ?? null;
        }
        if ($v === null) {
            $v = $this->PreviousYearsMean($ts, fn(float $t) => $t);
        }
        return $this->tempCache[$key] = $v;
    }

    /** Erwartete Heizgradtage eines Tages (Vorhersage / heute bisher / Mittel der Vorjahre) */
    private function ExpectedHdd(int $ts): ?float
    {
        $key = date('Y-m-d', $ts);
        if (array_key_exists($key, $this->hddCache)) {
            return $this->hddCache[$key];
        }
        $v = null;
        if (isset($this->fcTemps[$key])) {
            $v = $this->Hdd($this->fcTemps[$key]);
        } elseif ($ts <= $this->todayTs && isset($this->temps[$key])) {
            $v = $this->Hdd($this->temps[$key]);
        } else {
            // HGT je Tag mitteln (nicht die Temperatur), da HGT nicht linear ist
            $v = $this->PreviousYearsMean($ts, fn(float $t) => $this->Hdd($t));
        }
        return $this->hddCache[$key] = $v;
    }

    /** Mittel einer Funktion der Tagestemperatur über ±3 Tage um den gleichen Kalendertag, 1..3 Jahre zurück */
    private function PreviousYearsMean(int $ts, callable $fn): ?float
    {
        $sum = 0.0;
        $years = 0;
        for ($k = 1; $k <= 3; $k++) {
            $center = mktime(0, 0, 0, (int) date('n', $ts), (int) date('j', $ts), (int) date('Y', $ts) - $k);
            $vals = [];
            for ($o = -3; $o <= 3; $o++) {
                $d = $this->AddDays($center, $o);
                $key = date('Y-m-d', $d);
                if ($d < $this->todayTs && isset($this->temps[$key])) {
                    $vals[] = $fn($this->temps[$key]);
                }
            }
            if (count($vals) >= 4) {
                $sum += array_sum($vals) / count($vals);
                $years++;
            }
        }
        return $years > 0 ? $sum / $years : null;
    }

    /**
     * Regression Tagesverbrauch = b + a × HGT über die letzten 365 Tage.
     * Getrennt nach Werktag ('wt') und Wochenende/Feiertag ('we'), falls aktiviert; 'all' immer.
     */
    private function FitModel(int $today): ?array
    {
        $split = $this->ReadPropertyBoolean('SplitDayType');
        $from  = $this->AddDays($today, -365);
        $pts    = ['all' => [], 'wt' => [], 'we' => []];
        $summer = ['all' => [], 'wt' => [], 'we' => []];
        for ($ts = $from; $ts < $today; $ts = $this->AddDays($ts, 1)) {
            $key = date('Y-m-d', $ts);
            if (!isset($this->daily[$key], $this->temps[$key])) {
                continue;
            }
            $y    = $this->daily[$key];
            $hdd  = $this->Hdd($this->temps[$key]);
            $type = $this->IsFreeDay($ts) ? 'we' : 'wt';
            if ($hdd >= 1.0 && $y > 0) {
                $p = [round($hdd, 2), round($y, 3), date('d.m.y', $ts)];
                $pts['all'][] = $p;
                $pts[$type][] = $p;
            } elseif ($hdd <= 0.0) {
                $summer['all'][] = $y;
                $summer[$type][] = $y;
            }
        }

        $models = [];
        if (count($pts['all']) >= 20) {
            $models['all'] = $this->Regress($pts['all']);
        }
        if (!isset($models['all']) || $models['all'] === null) {
            $this->SendDebug('Witterung', sprintf('Zu wenig Heiztage für das Modell (%d, mind. 20)', count($pts['all'])), 0);
            return null;
        }
        if ($split) {
            foreach (['wt', 'we'] as $t) {
                if (count($pts[$t]) >= 15) {
                    $r = $this->Regress($pts[$t]);
                    if ($r !== null) {
                        $models[$t] = $r;
                    }
                }
            }
        }
        $summerBase = [];
        foreach ($summer as $t => $vals) {
            if (count($vals) >= 10) {
                $summerBase[$t] = $this->Median($vals);
            }
        }

        $split = $split && isset($models['wt'], $models['we']);
        if (!$split) {
            unset($models['wt'], $models['we']);
        }
        return [
            'split'  => $split,
            'models' => $models,
            'summer' => $summerBase,
            'points' => $split ? ['wt' => $pts['wt'], 'we' => $pts['we']] : ['all' => $pts['all']],
        ];
    }

    /** Lineare Regression mit einem Durchlauf Ausreißer-Bereinigung (|Residuum| > 2,5 σ) */
    private function Regress(array $pts): ?array
    {
        $fit = function (array $p): ?array {
            $n = count($p);
            if ($n < 3) {
                return null;
            }
            $sx = $sy = $sxx = $sxy = 0.0;
            foreach ($p as [$x, $y]) {
                $sx += $x;
                $sy += $y;
                $sxx += $x * $x;
                $sxy += $x * $y;
            }
            $den = $n * $sxx - $sx * $sx;
            if (abs($den) < 1e-9) {
                return null;
            }
            $a = ($n * $sxy - $sx * $sy) / $den;
            $b = ($sy - $a * $sx) / $n;
            $mean = $sy / $n;
            $ssRes = $ssTot = 0.0;
            foreach ($p as [$x, $y]) {
                $ssRes += ($y - ($b + $a * $x)) ** 2;
                $ssTot += ($y - $mean) ** 2;
            }
            return [
                'a'  => $a,
                'b'  => $b,
                'r2' => $ssTot > 0 ? 1 - $ssRes / $ssTot : 0.0,
                'sd' => sqrt($ssRes / max(1, $n - 2)),
                'n'  => $n,
            ];
        };

        $r = $fit($pts);
        if ($r === null) {
            return null;
        }
        $keep = array_values(array_filter($pts, fn($p) => abs($p[1] - ($r['b'] + $r['a'] * $p[0])) <= 2.5 * $r['sd']));
        if (count($keep) >= 10 && count($keep) < count($pts)) {
            $r2 = $fit($keep);
            if ($r2 !== null) {
                $r2['outliers'] = count($pts) - count($keep);
                return $r2;
            }
        }
        $r['outliers'] = 0;
        return $r;
    }

    /** Modelltyp für einen Tag ('wt', 'we' oder 'all') */
    private function ModelType(int $ts): string
    {
        if ($this->model !== null && $this->model['split']) {
            return $this->IsFreeDay($ts) ? 'we' : 'wt';
        }
        return 'all';
    }

    /** Erwarteter Tagesverbrauch aus dem Modell */
    private function ModelValue(int $ts, float $hdd): float
    {
        $type = $this->ModelType($ts);
        $m = $this->model['models'][$type] ?? $this->model['models']['all'];
        if ($hdd <= 0.0) {
            return max(0.0, $this->model['summer'][$type] ?? $this->model['summer']['all'] ?? $m['b']);
        }
        return max(0.0, $m['b'] + $m['a'] * $hdd);
    }

    /** Mittelwert eines Modellparameters über die Woche (5 Werktage + 2 freie Tage) */
    private function ModelAvg(string $param): float
    {
        if ($this->model === null) {
            return 0.0;
        }
        $m = $this->model['models'];
        $v = $this->model['split'] ? (5 * $m['wt'][$param] + 2 * $m['we'][$param]) / 7 : $m['all'][$param];
        return max(0.0, $v);
    }

    /**
     * Grundlast je Tag ohne Heizbedarf: Median der Sommertage (HGT = 0) der letzten 365 Tage,
     * ohne genügend Sommertage der (mittlere) Achsenabschnitt des Modells, mindestens 0
     */
    private function BaseDay(): float
    {
        if ($this->model === null) {
            return 0.0;
        }
        $s = $this->model['summer'];
        if ($this->model['split'] && isset($s['wt'], $s['we'])) {
            return max(0.0, (5 * $s['wt'] + 2 * $s['we']) / 7);
        }
        if (isset($s['all'])) {
            return max(0.0, $s['all']);
        }
        return $this->ModelAvg('b');
    }

    /** Kennzahlen der Witterungsbereinigung */
    private function WeatherKpis(int $pStart, int $today, float $kf): array
    {
        $yest = $this->AddDays($today, -1);
        $yKey = date('Y-m-d', $yest);

        // Periode bisher (bis gestern) gegen gleichen Zeitraum im Vorjahr, nur Tage mit Daten in beiden Jahren
        $hddCur = $hddPrev = $yCur = $yPrev = 0.0;
        $pairs = 0;
        $span = 0;
        $hddPeriod = $hddPeriodPrev = 0.0;
        for ($ts = $pStart; $ts < $today; $ts = $this->AddDays($ts, 1)) {
            $span++;
            $k  = date('Y-m-d', $ts);
            $kp = date('Y-m-d', mktime(0, 0, 0, (int) date('n', $ts), (int) date('j', $ts), (int) date('Y', $ts) - 1));
            if (isset($this->temps[$k])) {
                $hddPeriod += $this->Hdd($this->temps[$k]);
            }
            if (isset($this->temps[$kp])) {
                $hddPeriodPrev += $this->Hdd($this->temps[$kp]);
            }
            if (isset($this->daily[$k], $this->daily[$kp], $this->temps[$k], $this->temps[$kp])) {
                $pairs++;
                $yCur += $this->daily[$k];
                $yPrev += $this->daily[$kp];
                $hddCur += $this->Hdd($this->temps[$k]);
                $hddPrev += $this->Hdd($this->temps[$kp]);
            }
        }
        // Vorjahr auf das Wetter dieses Jahres umrechnen: Grundlast bleibt, Heizanteil skaliert mit den HGT
        $adj = null;
        if ($span > 0 && $pairs >= max(14, 0.8 * $span) && $hddPrev >= 20 && $hddCur > 0) {
            $baseSum = $pairs * $this->BaseDay();
            $prevAdj = $baseSum + max(0.0, $yPrev - $baseSum) * $hddCur / $hddPrev;
            if ($prevAdj > 0) {
                $adj = round(($yCur / $prevAdj - 1) * 100, 1);
            }
        }

        // Verbrauch je HGT der letzten 30 Tage (ohne Grundlast)
        $sumHeat = $sumHdd = 0.0;
        for ($i = 1; $i <= 30; $i++) {
            $ts = $this->AddDays($today, -$i);
            $k  = date('Y-m-d', $ts);
            if (!isset($this->daily[$k], $this->temps[$k])) {
                continue;
            }
            $hdd = $this->Hdd($this->temps[$k]);
            if ($hdd >= 1.0 && $this->daily[$k] > 0) {
                $sumHeat += $this->daily[$k] - $this->BaseDay();
                $sumHdd += $hdd;
            }
        }
        $perHdd30 = $sumHdd >= 30 ? round($sumHeat / $sumHdd * $kf, 2) : null;

        // Modellgüte (bei getrennten Modellen nach Anzahl gewichtet)
        $r2 = null;
        $models = null;
        $points = [];
        if ($this->model !== null) {
            $m = $this->model['models'];
            $r2 = $this->model['split']
                ? ($m['wt']['r2'] * $m['wt']['n'] + $m['we']['r2'] * $m['we']['n']) / ($m['wt']['n'] + $m['we']['n'])
                : $m['all']['r2'];
            $r2 = round($r2, 2);
            foreach ($m as $t => $p) {
                $models[$t] = [
                    'a' => round($p['a'], 4), 'b' => round($p['b'], 3), 'r2' => round($p['r2'], 3),
                    'n' => $p['n'], 'outliers' => $p['outliers'], 'summer' => $this->model['summer'][$t] ?? null,
                ];
            }
            $points = $this->model['points'];
        }

        return [
            'limit'         => $this->ReadPropertyFloat('HeatingLimit'),
            'split'         => $this->model !== null && $this->model['split'],
            'models'        => $models,
            'points'        => $points,
            'r2'            => $r2,
            'hddYesterday'  => isset($this->temps[$yKey]) ? round($this->Hdd($this->temps[$yKey]), 1) : null,
            'hddPeriod'     => round($hddPeriod, 0),
            'hddPeriodPrev' => round($hddPeriodPrev, 0),
            'adjDeviation'  => $adj,
            'pairs'         => $pairs,
            'span'          => $span,
            'perHdd'        => $this->model !== null ? round($this->ModelAvg('a') * $kf, 2) : null,
            'perHdd30'      => $perHdd30,
            'forecastDays'  => count($this->fcTemps),
        ];
    }

    private function ModelText(array $d): string
    {
        $w = $d['weather'];
        $u = $d['unit'];
        if ($w['models'] === null) {
            return 'Witterung aktiv, aber zu wenig Heiztage für ein Modell (mind. 20 Tage mit HGT ≥ 1 und Verbrauch in den letzten 365 Tagen).';
        }
        $names = ['all' => 'Alle Tage', 'wt' => 'Werktage', 'we' => 'Wochenende/Feiertage'];
        $lines = [sprintf('Heizgrenze %.1f °C, Regression über die letzten 365 Tage', $w['limit'])];
        foreach ($w['models'] as $t => $m) {
            $lines[] = sprintf(
                '%s: %.2f %s + %.3f %s × HGT (R² %.2f, %d Tage, %d Ausreißer)%s',
                $names[$t], $m['b'], $u, $m['a'], $u, $m['r2'], $m['n'], $m['outliers'],
                $m['summer'] !== null ? sprintf(', Sommer-Grundlast %.2f %s/Tag', $m['summer'], $u) : ''
            );
        }
        $lines[] = $w['split'] ? 'Prognose getrennt nach Werktag / Wochenende+Feiertag' : 'Prognose mit einem Modell für alle Tage';
        $lines[] = sprintf('%s je Heizgradtag: %s (Modell) / %s (30 Tage)', $d['perUnit'],
            $w['perHdd'] ?? '–', $w['perHdd30'] ?? '–');
        $lines[] = sprintf('Heizgradtage Periode bisher %s Kd, Vorjahr %s Kd; witterungsbereinigt ggü. Vorjahr: %s',
            $w['hddPeriod'], $w['hddPeriodPrev'], $w['adjDeviation'] === null ? '– (zu wenig Vergleichsdaten)' : sprintf('%+.1f %%', $w['adjDeviation']));
        $lines[] = sprintf('Wettervorhersage: %d Tage (Open-Meteo)', $w['forecastDays']);
        return implode("\n", $lines);
    }

    // =====================================================================================
    // Feiertage
    // =====================================================================================

    private function IsFreeDay(int $ts): bool
    {
        if ((int) date('N', $ts) >= 6) {
            return true;
        }
        $y = (int) date('Y', $ts);
        if (!isset($this->holidayCache[$y])) {
            $this->holidayCache[$y] = $this->Holidays($y, strtoupper(trim($this->ReadPropertyString('HolidayState'))));
        }
        return isset($this->holidayCache[$y][date('m-d', $ts)]);
    }

    /** Gesetzliche Feiertage in Deutschland je Bundesland, Schlüssel 'MM-TT' */
    private function Holidays(int $y, string $state): array
    {
        $in = fn(array $states) => in_array($state, $states, true);

        // Ostersonntag (Gauß / Meeus)
        $a = $y % 19;
        $b = intdiv($y, 100);
        $c = $y % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        $easter = mktime(0, 0, 0, $month, $day, $y);
        $rel = fn(int $days) => date('m-d', $this->AddDays($easter, $days));

        $list = ['01-01', $rel(-2), $rel(1), '05-01', $rel(39), $rel(50), '10-03', '12-25', '12-26'];
        if ($in(['BW', 'BY', 'ST'])) {
            $list[] = '01-06';
        }
        if ($in(['BE', 'MV'])) {
            $list[] = '03-08';
        }
        if ($in(['BW', 'BY', 'HE', 'NW', 'RP', 'SL'])) {
            $list[] = $rel(60);
        }
        if ($in(['SL'])) {
            $list[] = '08-15';
        }
        if ($in(['TH'])) {
            $list[] = '09-20';
        }
        if ($in(['BB', 'HB', 'HH', 'MV', 'NI', 'SH', 'SN', 'ST', 'TH'])) {
            $list[] = '10-31';
        }
        if ($in(['BW', 'BY', 'NW', 'RP', 'SL'])) {
            $list[] = '11-01';
        }
        if ($in(['SN'])) {
            // Buß- und Bettag: Mittwoch vor dem 23. November
            $bb = mktime(0, 0, 0, 11, 22, $y);
            while ((int) date('N', $bb) !== 3) {
                $bb = $this->AddDays($bb, -1);
            }
            $list[] = date('m-d', $bb);
        }
        return array_fill_keys($list, true);
    }

    // =====================================================================================
    // Zähler / Zählerwechsel
    // =====================================================================================

    /**
     * Zählerwechsel aus der Konfiguration, sortiert nach Ausbaudatum (älteste zuerst).
     * @return array<int, array{vid:int, date:int, factor:float, fill:bool, start:float}>|null  null = ungültige Zeile
     */
    private function ParseMeterChanges(): ?array
    {
        $list = json_decode($this->ReadPropertyString('MeterChanges'), true) ?: [];
        $changes = [];
        foreach ($list as $row) {
            $vid  = (int) ($row['VariableID'] ?? 0);
            $date = $this->ParseDate((string) ($row['ChangeDate'] ?? ''));
            if ($vid <= 0 || $date === null) {
                return null;
            }
            $factor = (float) ($row['Factor'] ?? 1.0);
            $changes[] = [
                'vid'    => $vid,
                'date'   => $date,
                'factor' => $factor != 0.0 ? $factor : 1.0,
                'fill'   => (bool) ($row['FillGap'] ?? false),
                'start'  => (float) ($row['StartReading'] ?? 0.0),
            ];
        }
        usort($changes, fn($a, $b) => $a['date'] <=> $b['date']);
        return $changes;
    }

    /** Baut die Zeitabschnitte je Zählervariable und die geschätzten Lücken für die aktuelle Berechnung */
    private function PrepareCounter(int $ac): void
    {
        $this->segments = [];
        $this->gapHours = [];
        $changes = $this->ParseMeterChanges() ?? [];

        $from = null;
        foreach ($changes as $c) {
            // alter Zähler: bis einschließlich Ausbautag
            $this->segments[] = ['vid' => $c['vid'], 'factor' => $c['factor'], 'from' => $from, 'to' => $this->AddDays($c['date'], 1)];
            // Nachfolger: ab Ausbautag (beide liefern nur in ihrer Laufzeit Zuwächse)
            $from = $c['date'];
        }
        $this->segments[] = [
            'vid'    => $this->ReadPropertyInteger('CounterVariableID'),
            'factor' => $this->ReadPropertyFloat('ScaleFactor'),
            'from'   => $from,
            'to'     => null,
        ];

        foreach ($changes as $i => $c) {
            if ($c['fill']) {
                $this->AddGap($ac, $c, $this->segments[$i + 1]);
            }
        }
    }

    /** Verbrauch zwischen letztem Wert des alten und erstem Wert des neuen Zählers gleichmäßig auf Stunden verteilen */
    private function AddGap(int $ac, array $change, array $next): void
    {
        $last = @AC_GetLoggedValues($ac, $change['vid'], 0, $this->AddDays($change['date'], 1) - 1, 1);
        if (!is_array($last) || count($last) === 0) {
            return;
        }
        $gapStart = (int) $last[0]['TimeStamp'];

        // ersten Wert des Nachfolgers ab Ausbautag tageweise suchen (je Tag max. 10.000 Werte)
        $first = null;
        for ($d = 0; $d < 62 && $first === null; $d++) {
            $ds   = $this->AddDays($change['date'], $d);
            $rows = @AC_GetLoggedValues($ac, $next['vid'], $ds, $this->AddDays($ds, 1) - 1, 0);
            if (is_array($rows) && count($rows) > 0) {
                $first = end($rows);
            }
        }
        if ($first === null) {
            return;
        }
        $gapEnd = (int) $first['TimeStamp'];
        $amount = ((float) $first['Value'] - $change['start']) * $next['factor'];
        if ($gapEnd <= $gapStart || $amount <= 0) {
            return;
        }

        $dur = $gapEnd - $gapStart;
        for ($h = $gapStart - ($gapStart % 3600); $h < $gapEnd; $h += 3600) {
            $a = max($h, $gapStart);
            $b = min($h + 3600, $gapEnd);
            if ($b > $a) {
                $this->gapHours[$h] = ($this->gapHours[$h] ?? 0.0) + $amount * ($b - $a) / $dur;
            }
        }
        $this->SendDebug('Zählerwechsel', sprintf(
            'Lücke %s – %s: %.3f %s geschätzt',
            date('d.m.Y H:i', $gapStart), date('d.m.Y H:i', $gapEnd), $amount, $this->ReadPropertyString('Unit')
        ), 0);
    }

    /**
     * Aggregierte Zuwächse über alle Zähler (inkl. Faktor und geschätzter Lücken).
     * @param int $level 0 = Stunde, 1 = Tag
     * @return array<int, array{TimeStamp:int, Avg:float}> neueste zuerst (wie AC_GetAggregatedValues)
     */
    private function CounterValues(int $level, int $start, int $end): array
    {
        $ac  = $this->ArchiveID();
        $sum = [];
        foreach ($this->segments as $s) {
            $qs = $s['from'] === null ? $start : max($start, $s['from']);
            $qe = $s['to'] === null ? $end : min($end, $s['to'] - 1);
            if ($qe < $qs) {
                continue;
            }
            foreach (AC_GetAggregatedValues($ac, $s['vid'], $level, $qs, $qe, 0) as $row) {
                // erster Wert eines Zählers kommt mit Ist-Zeit (z. B. 09:18) statt Intervallbeginn -> normieren
                $ts = $this->BucketStart($level, (int) $row['TimeStamp']);
                $sum[$ts] = ($sum[$ts] ?? 0.0) + (float) $row['Avg'] * $s['factor'];
            }
        }
        foreach ($this->gapHours as $h => $v) {
            $key = $this->BucketStart($level, $h);
            if ($key >= $this->BucketStart($level, $start) && $key <= $end) {
                $sum[$key] = ($sum[$key] ?? 0.0) + $v;
            }
        }
        krsort($sum);
        $out = [];
        foreach ($sum as $ts => $v) {
            $out[] = ['TimeStamp' => $ts, 'Avg' => $v];
        }
        return $out;
    }

    /** Beginn des Aggregationsintervalls (0 = Stunde, 1 = Tag) */
    private function BucketStart(int $level, int $ts): int
    {
        if ($level === 0) {
            return mktime((int) date('G', $ts), 0, 0, (int) date('n', $ts), (int) date('j', $ts), (int) date('Y', $ts));
        }
        return mktime(0, 0, 0, (int) date('n', $ts), (int) date('j', $ts), (int) date('Y', $ts));
    }

    // =====================================================================================
    // WebHook / Diagramme
    // =====================================================================================

    protected function ProcessHookData()
    {
        if (($_GET['lib'] ?? '') === 'chartjs') {
            $path = $this->ChartJsPath();
            if (!$this->EnsureChartJs()) {
                http_response_code(404);
                echo '// Chart.js nicht verfügbar';
                return;
            }
            header('Content-Type: application/javascript; charset=utf-8');
            header('Cache-Control: max-age=604800');
            echo file_get_contents($path);
            return;
        }

        $chart = preg_replace('/[^a-z]/', '', strtolower((string) ($_GET['chart'] ?? 'dashboard')));
        if (!in_array($chart, ['dashboard', 'today', 'week', 'months', 'period', 'model'], true)) {
            $chart = 'dashboard';
        }
        $theme   = (($_GET['theme'] ?? 'dark') === 'light') ? 'light' : 'dark';
        $bg      = (string) ($_GET['bg'] ?? '');
        $refresh = max(30, (int) ($_GET['refresh'] ?? 300));
        $header  = (($_GET['header'] ?? '1') !== '0');

        $this->EnsureChartJs();
        $data = $this->Compute();

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo $this->RenderPage($chart, $theme, $bg, $refresh, $header, $data);
    }

    private function RenderPage(string $chart, string $theme, string $bg, int $refresh, bool $header, ?array $data): string
    {
        $themes = [
            'dark' => [
                'bg' => '#16191d', 'card' => 'rgba(255,255,255,0.035)', 'border' => 'rgba(255,255,255,0.07)',
                'text' => '#d9dee4', 'muted' => '#8b949e', 'grid' => 'rgba(255,255,255,0.07)',
                'ist' => '#4ea8de', 'fc' => 'rgba(78,168,222,0.10)', 'hatch' => 'rgba(78,168,222,0.80)', 'istFill' => 'rgba(78,168,222,0.14)',
                'avg' => '#e9ecef', 'prev' => '#f4a261', 'plan' => '#8b949e', 'range' => 'rgba(255,255,255,0.09)',
                'pos' => '#56d364', 'neg' => '#f85149', 'posA' => 'rgba(86,211,100,0.16)', 'negA' => 'rgba(248,81,73,0.16)',
                'temp' => '#c792ea', 'tempA' => 'rgba(199,146,234,0.55)', 'dotA' => 'rgba(78,168,222,0.55)', 'dotB' => 'rgba(244,162,97,0.60)',
            ],
            'light' => [
                'bg' => '#f6f8fa', 'card' => '#ffffff', 'border' => 'rgba(0,0,0,0.08)',
                'text' => '#24292f', 'muted' => '#57606a', 'grid' => 'rgba(0,0,0,0.07)',
                'ist' => '#1f78b4', 'fc' => 'rgba(31,120,180,0.08)', 'hatch' => 'rgba(31,120,180,0.75)', 'istFill' => 'rgba(31,120,180,0.10)',
                'avg' => '#343a40', 'prev' => '#e76f51', 'plan' => '#6e7781', 'range' => 'rgba(0,0,0,0.07)',
                'pos' => '#1a7f37', 'neg' => '#cf222e', 'posA' => 'rgba(26,127,55,0.14)', 'negA' => 'rgba(207,34,46,0.14)',
                'temp' => '#8250df', 'tempA' => 'rgba(130,80,223,0.50)', 'dotA' => 'rgba(31,120,180,0.50)', 'dotB' => 'rgba(231,111,81,0.55)',
            ],
        ];
        $t = $themes[$theme];
        if ($bg === 'transparent') {
            $t['bg'] = 'transparent';
        } elseif (preg_match('/^[0-9a-fA-F]{3,8}$/', $bg)) {
            $t['bg'] = '#' . $bg;
        }

        $hook  = $this->HookPath();
        $json  = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $tJson = json_encode($t);

        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Energie Forecast</title>
<style>
  :root { --bg:__BG__; --card:__CARD__; --border:__BORDER__; --text:__TEXT__; --muted:__MUTED__; --pos:__POS__; --neg:__NEG__; }
  * { box-sizing: border-box; }
  html, body { margin:0; height:100%; background:var(--bg); color:var(--text);
               font: 13px/1.4 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
  body { padding:10px; }
  .grid { display:grid; gap:10px; grid-template-columns:repeat(auto-fit, minmax(420px, 1fr)); }
  .kpis { display:grid; gap:10px; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); margin-bottom:10px; }
  .kpi, .card { background:var(--card); border:1px solid var(--border); border-radius:10px; }
  .kpi { padding:10px 12px; }
  .kpi .l { color:var(--muted); font-size:11px; text-transform:uppercase; letter-spacing:.04em; }
  .kpi .v { font-size:22px; font-weight:600; margin-top:2px; font-variant-numeric:tabular-nums; }
  .kpi .s { color:var(--muted); font-size:12px; margin-top:1px; }
  .card { padding:10px 12px 8px; display:flex; flex-direction:column; }
  .card h3 { margin:0; font-size:14px; font-weight:600; }
  .card .sub { color:var(--muted); font-size:12px; margin:1px 0 6px; }
  .chart { position:relative; flex:1; min-height:260px; }
  .single { height:100%; }
  .single .card { height:100%; }
  .foot { color:var(--muted); font-size:11px; text-align:right; margin-top:8px; }
  .pos { color:var(--pos); } .neg { color:var(--neg); }
  .err { padding:20px; color:var(--neg); }
</style>
<script src="__HOOK__?lib=chartjs"></script>
<script>if(!window.Chart){document.write('<scr'+'ipt src="__CDN__"></scr'+'ipt>');}</script>
</head>
<body>
<div id="root"></div>
<script>
const D = __DATA__;
const T = __THEME__;
const VIEW = "__VIEW__";
const HEADER = __HEADER__;
setTimeout(() => location.reload(), __REFRESH__ * 1000);

const root = document.getElementById('root');
if (!D) {
  root.innerHTML = '<div class="err">Keine Daten – Instanz prüfen (Zählervariable / Archiv).</div>';
} else if (!window.Chart) {
  root.innerHTML = '<div class="err">Chart.js konnte nicht geladen werden.</div>';
} else {
  const U = D.unit;
  const W = D.weather;
  const hasModel = !!(W && W.models);
  const nf = (v, d = 1) => v == null ? '–' : Number(v).toLocaleString('de-DE', { minimumFractionDigits: d, maximumFractionDigits: d });
  const sgn = v => v == null ? '–' : (v > 0 ? '+' : '') + nf(v, 1);
  const eur = v => nf(v, 2) + ' €';
  const KW = v => D.kwhFactor !== 1 ? ` · ${nf(v * D.kwhFactor, 0)} kWh` : '';
  const K = D.kpi;

  Chart.defaults.color = T.muted;
  Chart.defaults.borderColor = T.grid;
  Chart.defaults.font.family = 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif';
  Chart.defaults.font.size = 11;
  Chart.defaults.animation = false;
  Chart.defaults.maintainAspectRatio = false;
  Chart.defaults.interaction = { mode: 'index', intersect: false };
  Chart.defaults.plugins.legend.position = 'bottom';
  Chart.defaults.plugins.legend.labels.boxWidth = 10;
  Chart.defaults.plugins.legend.labels.boxHeight = 10;
  Chart.defaults.plugins.legend.labels.color = T.text;
  Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(20,22,26,0.92)';
  Chart.defaults.plugins.tooltip.padding = 8;
  Chart.defaults.elements.bar.borderRadius = 3;

  // Datasets mit eigener Einheit (°C, Kd) bekommen dataset.unit, Verbrauch zusätzlich in kWh (Gas)
  const tipLabel = c => {
    if (Array.isArray(c.raw)) return ` ${c.dataset.label}: ${nf(c.raw[0])} – ${nf(c.raw[1])} ${U}`;
    if (c.raw == null) return null;
    const u = c.dataset.unit ?? U;
    let s = ` ${c.dataset.label}: ${nf(c.parsed.y, c.dataset.digits ?? 1)} ${u}`;
    if (!c.dataset.unit && D.kwhFactor !== 1) s += ` (${nf(c.parsed.y * D.kwhFactor, 0)} kWh)`;
    return s;
  };
  const yAxis = (extra = {}) => Object.assign({ beginAtZero: true, grid: { color: T.grid }, border: { display: false },
                                                ticks: { callback: v => nf(v, 0) } }, extra);
  const xAxis = (extra = {}) => Object.assign({ grid: { display: false }, border: { color: T.grid } }, extra);
  const sideAxis = (suffix, extra = {}) => Object.assign({ position: 'right', grid: { display: false }, border: { display: false },
                                                          ticks: { maxTicksLimit: 6, callback: v => nf(v, 0) + suffix } }, extra);
  const tempLine = (label, data, dashed = false) => ({ type: 'line', label, data, yAxisID: 'yT', borderColor: T.temp, backgroundColor: T.temp,
    borderWidth: 1.5, borderDash: dashed ? [4, 3] : [], pointRadius: 2, tension: .3, order: 0, unit: '°C', digits: 1, spanGaps: true });
  const hatch = (line, fill) => {
    const c = document.createElement('canvas'); c.width = c.height = 8;
    const x = c.getContext('2d'); x.fillStyle = fill; x.fillRect(0, 0, 8, 8);
    x.strokeStyle = line; x.lineWidth = 1.6; x.beginPath();
    x.moveTo(0, 8); x.lineTo(8, 0); x.moveTo(-2, 2); x.lineTo(2, -2); x.moveTo(6, 10); x.lineTo(10, 6); x.stroke();
    return x.createPattern(c, 'repeat');
  };
  const FC = hatch(T.hatch, T.fc);
  const modelFormula = m => `${nf(m.b, 2)} + ${nf(m.a, 2)} × HGT`;

  const charts = {
    today: {
      title: 'Heute',
      sub: () => `Prognose ${nf(K.todayForecast, 1)} ${U}${KW(K.todayForecast)} · bisher ${nf(K.todayActual, 1)} ${U} · ${nf(K.todayPercent, 0)} % vom Ø ${D.today.wdName}`,
      cfg: () => {
        const ds = [
          { type: 'line', label: 'Ø ' + D.today.wdName, data: D.today.avg, borderColor: T.avg, borderWidth: 2, pointRadius: 0, tension: .35, order: 1, digits: 2 },
          { type: 'line', label: 'Gestern', data: D.today.yest, borderColor: T.prev, borderWidth: 1.5, borderDash: [4, 4], pointRadius: 0, tension: .35, order: 2, digits: 2 },
          { label: 'Ist', data: D.today.act, backgroundColor: T.ist, grouped: false, barPercentage: .8, order: 3, digits: 2 },
          { label: 'Prognose', data: D.today.fc, backgroundColor: FC, grouped: false, barPercentage: .8, order: 4, digits: 2 },
        ];
        const scales = { x: xAxis(), y: yAxis({ ticks: { callback: v => nf(v, 1) } }) };
        if (D.today.temp) {
          ds.push(Object.assign(tempLine('Außentemperatur', D.today.temp), { pointRadius: 0 }));
          scales.yT = sideAxis(' °C');
        }
        return { type: 'bar', data: { labels: D.today.labels, datasets: ds },
                 options: { scales, plugins: { tooltip: { callbacks: { label: tipLabel } } } } };
      }
    },
    week: {
      title: 'Aktuelle Woche',
      sub: () => `bisher ${nf(K.weekActual, 1)} ${U}${KW(K.weekActual)} · Spanne und Ø der letzten ${D.weeks} Wochen je Wochentag`,
      cfg: () => {
        const ds = [
          { type: 'line', label: 'Ø', data: D.week.avg, borderColor: T.avg, backgroundColor: T.avg, showLine: false, pointStyle: 'line', pointRadius: 16, pointHoverRadius: 16, pointBorderWidth: 2.5, order: 1 },
          { label: 'Ist', data: D.week.act, backgroundColor: T.ist, grouped: false, barPercentage: .45, order: 2 },
          { label: 'Prognose', data: D.week.fc, backgroundColor: FC, grouped: false, barPercentage: .45, order: 3 },
          { label: `Spanne ${D.weeks} Wo.`, data: D.week.range, backgroundColor: T.range, grouped: false, barPercentage: .85, borderRadius: 5, order: 4 },
        ];
        const scales = { x: xAxis(), y: yAxis() };
        if (D.week.tempAct.length) {
          ds.push(tempLine('Ø Temp.', D.week.tempAct));
          ds.push(tempLine('Ø Temp. erwartet', D.week.tempFc, true));
          scales.yT = sideAxis(' °C');
        }
        return { type: 'bar', data: { labels: D.week.labels, datasets: ds },
                 options: { scales, plugins: { tooltip: { callbacks: { label: tipLabel } } } } };
      }
    },
    months: {
      title: 'Monate der Periode',
      sub: () => `${D.periodLabel} · Ist + Prognose ggü. Vorjahr und Plan` + (D.months.hdd.length ? ' · Heizgradtage' : ''),
      cfg: () => {
        const total = D.months.fc.map((f, i) => f == null ? null : Math.round(((D.months.act[i] || 0) + f) * 10) / 10);
        const ds = [
          { type: 'line', label: 'Vorjahr', data: D.months.prev, borderColor: T.prev, backgroundColor: T.prev, borderWidth: 2, pointRadius: 3, tension: .3, order: 1 },
          { type: 'line', label: 'Plan', data: D.months.plan, borderColor: T.plan, backgroundColor: T.plan, showLine: false, pointStyle: 'line', pointRadius: 18, pointHoverRadius: 18, pointBorderWidth: 2, order: 2 },
          { label: 'Ist', data: D.months.act, backgroundColor: T.ist, grouped: false, barPercentage: .65, order: 3 },
          { label: 'Prognose', data: total, backgroundColor: FC, grouped: false, barPercentage: .65, order: 4 },
        ];
        const scales = { x: xAxis(), y: yAxis() };
        if (D.months.hdd.length) {
          ds.push({ type: 'line', label: 'Heizgradtage', data: D.months.hdd, yAxisID: 'yH', borderColor: T.temp, backgroundColor: T.temp,
                    borderWidth: 1.5, pointRadius: 2.5, tension: .3, order: 0, unit: 'Kd', digits: 0 });
          ds.push({ type: 'line', label: 'HGT Vorjahr', data: D.months.hddPrev, yAxisID: 'yH', borderColor: T.tempA, backgroundColor: T.tempA,
                    borderWidth: 1.2, borderDash: [4, 3], pointRadius: 0, tension: .3, order: 0, unit: 'Kd', digits: 0 });
          scales.yH = sideAxis(' Kd', { beginAtZero: true });
        }
        return {
          type: 'bar',
          data: { labels: D.months.labels, datasets: ds },
          options: { scales,
                     plugins: { tooltip: { callbacks: {
                       label: tipLabel,
                       footer: items => {
                         const i = items[0].dataIndex;
                         const v = total[i] ?? D.months.act[i];
                         return v == null ? '' : `Kosten ≈ ${eur(v * D.price + D.basePrice)}`;
                       } } } } }
        };
      }
    },
    period: {
      title: 'Periodenverlauf (kumuliert)',
      sub: () => `Prognose ${nf(K.periodForecast, 0)} ${U}${KW(K.periodForecast)} · Plan-Abw. ${K.planDeviation >= 0 ? '+' : ''}${nf(K.planDeviation, 1)} %`,
      cfg: () => {
        const diff = D.cum.labels.map((_, i) => {
          const v = D.cum.act[i] ?? D.cum.fc[i];
          const p = D.cum.prev.length ? D.cum.prev[i] : null;
          return (v == null || p == null) ? null : Math.round((v - p) * 10) / 10;
        });
        const dMax = Math.max(10, ...diff.filter(v => v != null).map(Math.abs)) * 1.15;
        return {
          type: 'line',
          data: { labels: D.cum.labels, datasets: [
            { label: 'Ist', data: D.cum.act, borderColor: T.ist, borderWidth: 2.5, pointRadius: 0, order: 1 },
            { label: 'Prognose', data: D.cum.fc, borderColor: T.ist, borderWidth: 2, borderDash: [6, 4], pointRadius: 0, order: 2 },
            { label: 'Vorperiode', data: D.cum.prev, borderColor: T.prev, borderWidth: 1.5, pointRadius: 0, order: 3 },
            { label: 'Plan', data: D.cum.plan, borderColor: T.plan, borderWidth: 1.5, borderDash: [2, 3], pointRadius: 0, order: 4 },
            { label: 'Δ zur Vorperiode', data: diff, yAxisID: 'y2', borderColor: 'transparent', backgroundColor: T.negA,
              fill: { target: 'origin', above: T.negA, below: T.posA }, pointRadius: 0, order: 5, delta: true },
          ] },
          options: { scales: {
                       x: xAxis({ ticks: { autoSkip: false, maxRotation: 0, callback: (v, i) => D.cum.ticks[i] || null } }),
                       y: yAxis(),
                       y2: { position: 'right', min: -dMax, max: dMax, grid: { display: false }, border: { display: false },
                             ticks: { maxTicksLimit: 5, callback: v => (v > 0 ? '+' : '') + nf(v, 0) },
                             title: { display: true, text: 'Δ ' + U, color: T.muted } } },
                     plugins: { tooltip: { callbacks: { label: c => c.dataset.delta
                       ? (c.raw == null ? null : ` ${c.dataset.label}: ${c.raw > 0 ? '+' : ''}${nf(c.raw, 1)} ${U}`)
                       : tipLabel(c) } } } }
        };
      }
    },
    model: {
      title: 'Heizgradtag-Modell',
      sub: () => {
        const M = W.models;
        const txt = W.split
          ? `Werktag ${modelFormula(M.wt)} (R² ${nf(M.wt.r2, 2)}) · frei ${modelFormula(M.we)} (R² ${nf(M.we.r2, 2)})`
          : `${modelFormula(M.all)} (R² ${nf(M.all.r2, 2)})`;
        return `${txt} · ${U}/Tag, Heizgrenze ${nf(W.limit, 0)} °C, letzte 365 Tage`;
      },
      cfg: () => {
        const M = W.models;
        const sets = W.split
          ? [['wt', 'Werktage', T.dotA, T.ist], ['we', 'Wochenende/Feiertag', T.dotB, T.prev]]
          : [['all', 'Heiztage', T.dotA, T.ist]];
        const all = Object.values(W.points).flat();
        const xMax = Math.ceil(Math.max(20, ...all.map(p => p[0])) / 5) * 5;
        const ds = [];
        sets.forEach(([k, label, dot, line]) => {
          const m = M[k];
          ds.push({ type: 'scatter', label, data: (W.points[k] || []).map(p => ({ x: p[0], y: p[1], d: p[2] })),
                    backgroundColor: dot, borderColor: dot, pointRadius: 3, pointHoverRadius: 5, order: 2 });
          ds.push({ type: 'line', label: 'Modell ' + label, data: [{ x: 1, y: m.b + m.a }, { x: xMax, y: m.b + m.a * xMax }],
                    borderColor: line, borderWidth: 2, pointRadius: 0, order: 1, isModel: true });
        });
        return {
          type: 'scatter',
          data: { datasets: ds },
          options: {
            interaction: { mode: 'nearest', intersect: true },
            scales: {
              x: { type: 'linear', min: 0, max: xMax, grid: { color: T.grid }, border: { display: false },
                   title: { display: true, text: 'Heizgradtage (Kd)', color: T.muted } },
              y: yAxis({ title: { display: true, text: U + ' / Tag', color: T.muted } }),
            },
            plugins: { tooltip: { callbacks: { label: c => c.dataset.isModel
              ? ` ${c.dataset.label}: ${nf(c.parsed.y, 1)} ${U} bei ${nf(c.parsed.x, 0)} Kd`
              : ` ${c.raw.d}: ${nf(c.raw.x, 1)} Kd → ${nf(c.raw.y, 1)} ${U}` + (D.kwhFactor !== 1 ? ` (${nf(c.raw.y * D.kwhFactor, 0)} kWh)` : '') } } }
          }
        };
      }
    },
  };

  const card = (key, single) => {
    const c = charts[key];
    const head = HEADER ? `<h3>${c.title}</h3><div class="sub">${c.sub()}</div>` : '';
    return `<div class="card">${head}<div class="chart"><canvas id="c_${key}"></canvas></div></div>`;
  };
  const kpi = (l, v, s = '', cls = '') => `<div class="kpi"><div class="l">${l}</div><div class="v ${cls}">${v}</div><div class="s">${s}</div></div>`;

  let keys;
  if (VIEW === 'dashboard') {
    keys = ['today', 'week', 'months', 'period'];
    if (hasModel) keys.push('model');
    const bal = K.balance;
    const base = D.gas
      ? kpi('Grundlast', `${nf(K.baseLoadDay, 1)} ${U}/Tag`, (hasModel ? 'Tage ohne Heizbedarf' : '3 niedrigste Tage') + KW(K.baseLoadDay))
      : kpi('Grundlast', `${nf(K.baseLoad, 0)} W`, 'Ø 7 Tage');
    let wx = '';
    if (W) {
      const adj = W.adjDeviation;
      wx = kpi('Witterungsbereinigt', adj == null ? '–' : `${sgn(adj)} %`,
               `ggü. Vorjahr · HGT ${nf(W.hddPeriod, 0)} / Vj ${nf(W.hddPeriodPrev, 0)}`, adj == null ? '' : (adj > 0 ? 'neg' : 'pos')) +
           kpi('Je Heizgradtag', W.perHdd == null ? '–' : `${nf(W.perHdd, 1)} ${D.perUnit}`,
               `30 Tage: ${nf(W.perHdd30, 1)} · R² ${nf(W.r2, 2)}`);
    }
    root.innerHTML =
      '<div class="kpis">' +
        kpi('Heute', `${nf(K.todayForecast, 1)} ${U}`, `${nf(K.todayPercent, 0)} % vom Ø${KW(K.todayForecast)}`) +
        kpi('Monat', `${nf(K.monthForecast, 0)} ${U}`, `bisher ${nf(K.monthActual, 0)} ${U}${KW(K.monthForecast)}`) +
        kpi('Periode', `${nf(K.periodForecast, 0)} ${U}`, `bisher ${nf(K.periodActual, 0)} ${U}${KW(K.periodForecast)}`) +
        kpi('Plan-Abweichung', `${K.planDeviation >= 0 ? '+' : ''}${nf(K.planDeviation, 1)} %`, 'saisonal bis gestern', K.planDeviation > 0 ? 'neg' : 'pos') +
        kpi('Saldo Periode', eur(bal), bal >= 0 ? 'Guthaben' : 'Nachzahlung', bal >= 0 ? 'pos' : 'neg') +
        kpi('Abschlag neu', eur(K.nextAdvance), `${nf(K.nextCons, 0)} ${U} / Jahr${KW(K.nextCons)}`) +
        base + wx +
      '</div>' +
      '<div class="grid">' + keys.map(k => card(k)).join('') + '</div>' +
      `<div class="foot">Stand ${D.updated} · Periode ${D.periodLabel}` +
        (W ? ` · Wettervorhersage ${W.forecastDays} Tage (Open-Meteo)` : '') + '</div>';
  } else if (VIEW === 'model' && !hasModel) {
    keys = [];
    root.innerHTML = '<div class="err">Kein Heizgradtag-Modell – Außentemperatur wählen bzw. mehr Heiztage abwarten.</div>';
  } else {
    keys = [VIEW];
    document.body.classList.add('single');
    root.className = 'single';
    root.innerHTML = card(VIEW, true);
  }
  keys.forEach(k => new Chart(document.getElementById('c_' + k), charts[k].cfg()));
}
</script>
</body>
</html>
HTML;

        return strtr($html, [
            '__BG__'      => $t['bg'],
            '__CARD__'    => $t['card'],
            '__BORDER__'  => $t['border'],
            '__TEXT__'    => $t['text'],
            '__MUTED__'   => $t['muted'],
            '__POS__'     => $t['pos'],
            '__NEG__'     => $t['neg'],
            '__HOOK__'    => $hook,
            '__CDN__'     => self::CHARTJS_URL,
            '__DATA__'    => $json === false ? 'null' : $json,
            '__THEME__'   => $tJson,
            '__VIEW__'    => $chart,
            '__HEADER__'  => $header ? 'true' : 'false',
            '__REFRESH__' => (string) $refresh,
        ]);
    }

    private function HookPath(): string
    {
        return '/hook/efc' . $this->InstanceID;
    }

    private function RegisterHook(string $hook): void
    {
        $ids = IPS_GetInstanceListByModuleID(self::WEBHOOK_GUID);
        if (count($ids) === 0) {
            return;
        }
        $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true) ?: [];
        foreach ($hooks as $index => $h) {
            if ($h['Hook'] === $hook) {
                if ($h['TargetID'] === $this->InstanceID) {
                    return;
                }
                $hooks[$index]['TargetID'] = $this->InstanceID;
                IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
                IPS_ApplyChanges($ids[0]);
                return;
            }
        }
        $hooks[] = ['Hook' => $hook, 'TargetID' => $this->InstanceID];
        IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($ids[0]);
    }

    private function ChartJsPath(): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'libs' . DIRECTORY_SEPARATOR . 'chart.umd.min.js';
    }

    /** Chart.js einmalig lokal ablegen, damit die Diagramme ohne Internet laufen */
    private function EnsureChartJs(): bool
    {
        $path = $this->ChartJsPath();
        if (is_file($path) && filesize($path) > 100000) {
            return true;
        }
        $js = @Sys_GetURLContent(self::CHARTJS_URL);
        if (is_string($js) && strlen($js) > 100000 && str_contains($js, 'Chart')) {
            if (!is_dir(dirname($path))) {
                @mkdir(dirname($path), 0777, true);
            }
            return @file_put_contents($path, $js) !== false;
        }
        $this->SendDebug('ChartJS', 'Download fehlgeschlagen, Seite nutzt CDN-Fallback', 0);
        return false;
    }

    // =====================================================================================
    // Allgemeine Hilfen
    // =====================================================================================

    private function CheckConfiguration(): int
    {
        $cid = $this->ReadPropertyInteger('CounterVariableID');
        if ($cid <= 0 || !IPS_VariableExists($cid)) {
            return self::STATUS_NO_COUNTER;
        }
        $ac = $this->ArchiveID();
        if (!$this->IsArchivedCounter($ac, $cid)) {
            return self::STATUS_NOT_COUNTER;
        }
        $changes = $this->ParseMeterChanges();
        if ($changes === null) {
            return self::STATUS_BAD_CHANGE;
        }
        foreach ($changes as $c) {
            if ($c['vid'] === $cid || !IPS_VariableExists($c['vid']) || !$this->IsArchivedCounter($ac, $c['vid'])) {
                return self::STATUS_BAD_CHANGE;
            }
        }
        if ($this->ParsePeriodStart() === null) {
            return self::STATUS_BAD_PERIOD;
        }
        $tid = $this->ReadPropertyInteger('TemperatureVariableID');
        if ($tid > 0 && (!IPS_VariableExists($tid) || $ac <= 0 || !AC_GetLoggingStatus($ac, $tid))) {
            return self::STATUS_BAD_TEMP;
        }
        $from = trim($this->ReadPropertyString('TemperatureValidFrom'));
        if ($from !== '' && $this->ParseDate($from) === null) {
            return self::STATUS_BAD_TEMP_FROM;
        }
        if ($this->IsGas() && $this->KwhFactor() <= 0) {
            return self::STATUS_BAD_GAS;
        }
        return 102;
    }

    private function IsGas(): bool
    {
        return $this->ReadPropertyInteger('MeterType') === self::METER_GAS;
    }

    /** kWh je Zählereinheit: Gas = Brennwert × Zustandszahl, sonst 1 */
    private function KwhFactor(): float
    {
        if (!$this->IsGas()) {
            return 1.0;
        }
        return $this->ReadPropertyFloat('CalorificValue') * $this->ReadPropertyFloat('ZNumber');
    }

    private function SetIf(string $ident, $value): void
    {
        if ($value !== null && @IPS_GetObjectIDByIdent($ident, $this->InstanceID) !== false) {
            $this->SetValue($ident, $value);
        }
    }

    private function IsArchivedCounter(int $ac, int $vid): bool
    {
        return $ac > 0 && AC_GetLoggingStatus($ac, $vid) && AC_GetAggregationType($ac, $vid) === 1;
    }

    private function ArchiveID(): int
    {
        $list = IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID);
        return $list[0] ?? 0;
    }

    /** @return array{0:int,1:int}|null Tag, Monat */
    private function ParsePeriodStart(): ?array
    {
        if (!preg_match('/^\s*(\d{1,2})\.(\d{1,2})\.?\s*$/', $this->ReadPropertyString('PeriodStart'), $m)) {
            return null;
        }
        $d = (int) $m[1];
        $mo = (int) $m[2];
        if (!checkdate($mo, $d, 2001)) {
            return null;
        }
        return [$d, $mo];
    }

    /** @return array{0:int,1:int} Start (00:00 erster Tag) und Ende (00:00 letzter Tag) der laufenden Periode */
    private function PeriodBounds(int $today): array
    {
        [$d, $m] = $this->ParsePeriodStart();
        $y = (int) date('Y', $today);
        $start = mktime(0, 0, 0, $m, $d, $y);
        if ($start > $today) {
            $start = mktime(0, 0, 0, $m, $d, $y - 1);
        }
        $end = mktime(0, 0, 0, $m, $d - 1, (int) date('Y', $start) + 1);
        return [$start, $end];
    }

    private function ParseDate(string $s): ?int
    {
        if (preg_match('/^\s*(\d{1,2})\.(\d{1,2})\.(\d{4})\s*$/', $s, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return mktime(0, 0, 0, (int) $m[2], (int) $m[1], (int) $m[3]);
        }
        return null;
    }

    private function AddDays(int $ts, int $days): int
    {
        return mktime(0, 0, 0, (int) date('n', $ts), (int) date('j', $ts) + $days, (int) date('Y', $ts));
    }

    private function Median(array $values): float
    {
        sort($values, SORT_NUMERIC);
        $c = count($values);
        if ($c === 0) {
            return 0.0;
        }
        $mid = intdiv($c, 2);
        return $c % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /** Legt die klassischen Variablenprofile an (für IPSView) und liefert den Namen des Energie-Profils */
    private function EnsureProfiles(): string
    {
        $unit   = trim($this->ReadPropertyString('Unit'));
        $energy = 'EFC.Energy.' . $this->ProfileSuffix($unit);

        $this->CreateFloatProfile($energy, 1, ' ' . $unit, $this->IsGas() ? 'Flame' : 'Electricity');
        $this->CreateFloatProfile('EFC.Euro', 2, ' €', 'Euro');
        $this->CreateFloatProfile('EFC.Percent', 0, ' %', 'Intensity');
        $this->CreateFloatProfile('EFC.Deviation', 1, ' %', 'Graph');
        $this->CreateFloatProfile('EFC.Watt', 0, ' W', 'Electricity');
        if ($this->IsGas()) {
            $this->CreateFloatProfile('EFC.Energy.kWh', 1, ' kWh', 'Flame');
        }
        if ($this->ReadPropertyInteger('TemperatureVariableID') > 0) {
            $this->CreateFloatProfile('EFC.HDD', 1, ' Kd', 'Temperature');
            $this->CreateFloatProfile('EFC.R2', 2, '', 'Graph');
            $perUnit = $this->IsGas() ? 'kWh' : $unit;
            $this->CreateFloatProfile($this->PerHddProfile(), 2, ' ' . $perUnit . '/Kd', 'Temperature');
        }

        return $energy;
    }

    private function PerHddProfile(): string
    {
        $unit = $this->IsGas() ? 'kWh' : trim($this->ReadPropertyString('Unit'));
        return 'EFC.PerHDD.' . $this->ProfileSuffix($unit);
    }

    private function ProfileSuffix(string $unit): string
    {
        $clean = preg_replace('/[^A-Za-z0-9]/', '', strtr($unit, ['³' => '3', '²' => '2']));
        return $clean !== '' ? $clean : 'Unit';
    }

    private function CreateFloatProfile(string $name, int $digits, string $suffix, string $icon): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, VARIABLETYPE_FLOAT);
        }
        IPS_SetVariableProfileDigits($name, $digits);
        IPS_SetVariableProfileText($name, '', $suffix);
        IPS_SetVariableProfileIcon($name, $icon);
    }
}
