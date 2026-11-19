<?php

declare(strict_types=1);

/**
 * jniDWDWetter – Wettervorhersage direkt vom Deutschen Wetterdienst
 *
 * Quellen
 *  - DWD OpenData MOSMIX_L (Stundenwerte, 10 Tage) für eine MOSMIX-Station
 *  - DWD-Textberichte VHDL50..53 (Heute/Morgen/Übermorgen/Tag 4) je Bundesland
 *  - optional UV-Index (Tagesmaximum) über Open-Meteo, da der DWD keinen UV-Wert in MOSMIX liefert
 *
 * Statusvariablen
 *  - Aktuell: Temperatur, Bewölkung, Luftdruck, Nebel, Niederschlag, Sichtweite,
 *             Sonnenschein, Wind, Böen, UV-Index, Datenstand
 *  - je Tag:  Datum, Wochentag, Max/Min, Wetterzustand (HA-kompatibler Code), Text,
 *             Niederschlagsmenge, Regenwahrscheinlichkeit, Sonnenstunden, Wettersymbol (Medienobjekt)
 *  - Text:    DWD-Textberichte Heute/Morgen/Übermorgen/Tag 4 und Stand
 *
 * Kachel-Visualisierung über das HTML-SDK (module.html).
 */
class jniDWDWetter extends IPSModule
{
    private const MOSMIX_URL = 'https://opendata.dwd.de/weather/local_forecasts/mos/MOSMIX_L/single_stations/%1$s/kml/MOSMIX_L_LATEST_%1$s.kmz';
    private const MOSMIX_NS  = 'https://opendata.dwd.de/weather/lib/pointforecast_dwd_extension_V1_0.xsd';
    private const TEXT_URL   = 'https://opendata.dwd.de/weather/text_forecasts/html/%s_%s_LATEST_html';
    private const UV_URL     = 'https://api.open-meteo.com/v1/forecast?latitude=%s&longitude=%s&daily=uv_index_max&timezone=Europe%%2FBerlin&forecast_days=1';

    private const MAX_TAGE = 10;

    /** DWD-Textberichte: Typ => [Ident, Name, Dateiname für Wetterbericht-Seiten] */
    private const TEXTE = [
        'VHDL50' => ['TextHeute',       'Textvorhersage Heute',      'berichtHeute.txt'],
        'VHDL51' => ['TextMorgen',      'Textvorhersage Morgen',     'berichtMorgen.txt'],
        'VHDL52' => ['TextUebermorgen', 'Textvorhersage Übermorgen', 'berichtUebermorgen.txt'],
        'VHDL53' => ['TextTag4',        'Textvorhersage Tag 4',      'berichtTag4.txt'],
    ];

    /** Wetterzustand => deutscher Text */
    private const ZUSTAND_TEXT = [
        'sunny'           => 'sonnig',
        'clear-night'     => 'Klare Nacht',
        'partlycloudy'    => 'teilweise bewölkt',
        'cloudy'          => 'wolkig',
        'fog'             => 'Nebel',
        'rainy'           => 'regnerisch',
        'pouring'         => 'starker Regen',
        'snowy'           => 'Schnee',
        'snowy-rainy'     => 'Schneeregen',
        'hail'            => 'Hagel',
        'lightning'       => 'Gewitter',
        'lightning-rainy' => 'Gewitter, regnerisch',
        'windy'           => 'windig',
        'windy-variant'   => 'windig, bewölkt',
        'exceptional'     => 'Unwetter',
    ];

    /** Wetterzustand => Symboldatei [Tag, Nacht] im Symbol-Ordner (VClouds-Satz) */
    private const SYMBOLE = [
        'sunny'           => ['32.png', '31.png'],
        'clear-night'     => ['31.png', '31.png'],
        'partlycloudy'    => ['30.png', '29.png'],
        'cloudy'          => ['28.png', '27.png'],
        'fog'             => ['20.png', '20.png'],
        'rainy'           => ['11.png', '45.png'],
        'pouring'         => ['12.png', '12.png'],
        'snowy'           => ['16.png', '46.png'],
        'snowy-rainy'     => ['6.png',  '46.png'],
        'hail'            => ['87.png', '87.png'],
        'lightning'       => ['4.png',  '47.png'],
        'lightning-rainy' => ['4.png',  '47.png'],
        'windy'           => ['23.png', '23.png'],
        'windy-variant'   => ['23.png', '23.png'],
        'exceptional'     => ['0.png',  '0.png'],
    ];

    /**
     * Wetterzustand => Symboldatei [Tag, Nacht] im Modulordner symbole/
     * Meteocons von Bas Milius (MIT-Lizenz, siehe symbole/LICENSE.txt)
     */
    private const SYMBOLE_METEOCONS = [
        'sunny'           => ['clear-day',              'clear-night'],
        'clear-night'     => ['clear-night',            'clear-night'],
        'partlycloudy'    => ['partly-cloudy-day',      'partly-cloudy-night'],
        'cloudy'          => ['overcast',               'overcast'],
        'fog'             => ['fog-day',                'fog-night'],
        'rainy'           => ['rain',                   'rain'],
        'pouring'         => ['extreme-rain',           'extreme-rain'],
        'snowy'           => ['snow',                   'snow'],
        'snowy-rainy'     => ['sleet',                  'sleet'],
        'hail'            => ['hail',                   'hail'],
        'lightning'       => ['thunderstorms-day',      'thunderstorms-night'],
        'lightning-rainy' => ['thunderstorms-day-rain', 'thunderstorms-night-rain'],
        'windy'           => ['wind',                   'wind'],
        'windy-variant'   => ['wind',                   'wind'],
        'exceptional'     => ['tornado',                'tornado'],
    ];

    public function Create()
    {
        parent::Create();

        // Standort / Quelle
        $this->RegisterPropertyString('Station', 'O458');
        $this->RegisterPropertyFloat('Latitude', 51.01);
        $this->RegisterPropertyFloat('Longitude', 13.51);

        // Vorhersage
        $this->RegisterPropertyInteger('Tage', 10);
        $this->RegisterPropertyInteger('Intervall', 5);
        $this->RegisterPropertyInteger('CacheMinuten', 30);
        $this->RegisterPropertyInteger('RegenStunden', 3);
        $this->RegisterPropertyBoolean('UVOpenMeteo', true);

        // Textvorhersage
        $this->RegisterPropertyBoolean('TextAktiv', true);
        $this->RegisterPropertyString('TextKennung', 'DWLG');
        $this->RegisterPropertyString('TextAnhang', '');
        $this->RegisterPropertyBoolean('BerichtDateien', false);
        $this->RegisterPropertyString('BerichtPfad', 'user/WetterFtpDWD/berichte/');

        // Darstellung
        $this->RegisterPropertyInteger('IstTagVariable', 0);
        $this->RegisterPropertyBoolean('Symbole', true);
        $this->RegisterPropertyString('Symbolsatz', 'meteocons');
        $this->RegisterPropertyString('SymbolPfad', 'media/Wettersymbole/');

        $this->RegisterAttributeString('SymbolStand', '{}');
        $this->RegisterAttributeInteger('IstTagRegistriert', 0);
        $this->RegisterAttributeInteger('TextAbruf', 0);
        $this->RegisterAttributeString('Fehler', '');

        $this->RegisterTimer('Update', 0, 'DWDW_Update($_IPS[\'TARGET\']);');

        $this->RegisterProfile('DWDW.Prozent', VARIABLETYPE_FLOAT, '', ' %', 0, 100, 0, 0, 'Cloud');
        $this->RegisterProfile('DWDW.Wahrscheinlichkeit', VARIABLETYPE_INTEGER, '', ' %', 0, 100, 1, 0, 'Drops');
        $this->RegisterProfile('DWDW.Luftdruck', VARIABLETYPE_FLOAT, '', ' hPa', 950, 1050, 0, 1, 'Gauge');
        $this->RegisterProfile('DWDW.Niederschlag', VARIABLETYPE_FLOAT, '', ' mm/h', 0, 50, 0, 1, 'Rainfall');
        $this->RegisterProfile('DWDW.NiederschlagTag', VARIABLETYPE_FLOAT, '', ' mm', 0, 100, 0, 1, 'Rainfall');
        $this->RegisterProfile('DWDW.Sichtweite', VARIABLETYPE_FLOAT, '', ' km', 0, 100, 0, 1, 'Eyes');
        $this->RegisterProfile('DWDW.Sonnenschein', VARIABLETYPE_FLOAT, '', ' s', 0, 3600, 0, 0, 'Sun');
        $this->RegisterProfile('DWDW.Sonnenstunden', VARIABLETYPE_FLOAT, '', ' h', 0, 16, 0, 1, 'Sun');
        $this->RegisterProfile('DWDW.Wind', VARIABLETYPE_FLOAT, '', ' km/h', 0, 200, 0, 0, 'WindSpeed');
        $this->RegisterProfile('DWDW.Windrichtung', VARIABLETYPE_FLOAT, '', '', 0, 360, 0, 0, 'WindDirection');
        $this->RegisterWindrichtungen('DWDW.Windrichtung');

        $this->SetVisualizationType(2);   // HTML-Kachel, auch im Vollbild
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        $this->PflegeVariablen();
        $this->PflegeIstTagNachricht();

        $intervall = max(1, $this->ReadPropertyInteger('Intervall'));
        $this->SetTimerInterval('Update', $intervall * 60 * 1000);

        if (!$this->StationGueltig()) {
            $this->SetStatus(202);
            $this->SetTimerInterval('Update', 0);
            return;
        }
        $this->SetStatus(102);

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->Update();
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->Update();
                break;
            case VM_UPDATE:
                // nur bei echter Änderung von Tag/Nacht ($Data[1] = geändert)
                if ($SenderID == $this->ReadPropertyInteger('IstTagVariable') && !empty($Data[1])) {
                    $this->AktualisiereSymbol(0, $this->GetValue('Tag0_Wetterzustand'));
                    $this->AktualisiereKachel();
                }
                break;
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Update':
                $this->Update();
                break;
            default:
                throw new Exception('Ungültiger Ident: ' . $Ident);
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $fehler = $this->ReadAttributeString('Fehler');
        $stand = (int)$this->Lese('Datenstand', 0);
        $info = $stand ? 'Datenstand MOSMIX: ' . date('d.m.Y H:i', $stand) : 'Noch keine Daten abgerufen';
        if ($fehler !== '') {
            $info .= ' – Fehler: ' . $fehler;
        }
        foreach ($form['actions'] as &$a) {
            if (($a['name'] ?? '') === 'Info') {
                $a['caption'] = $info;
            }
        }
        return json_encode($form);
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        $daten = json_encode($this->KachelDaten(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        return str_replace('/*DATEN*/null', $daten, $html);
    }

    /**
     * Holt Vorhersage und Textberichte und schreibt alle Variablen.
     * DWDW_Update($InstanzID);
     */
    public function Update(): bool
    {
        if (!$this->StationGueltig()) {
            $this->SetStatus(202);
            return false;
        }

        if ($this->ReadPropertyBoolean('TextAktiv')) {
            $this->LadeTextberichte();
        }

        $mos = $this->LadeMosmix();
        if ($mos === null) {
            $this->WriteAttributeString('Fehler', 'MOSMIX-Daten nicht abrufbar');
            $this->SetStatus(201);
            $this->LogMessage('MOSMIX-Daten konnten nicht geladen werden', KL_WARNING);
            return false;
        }
        $this->WriteAttributeString('Fehler', '');
        $this->SetStatus(102);

        $ts = $mos['ts'];
        $v = $mos['werte'];
        $jetzt = time();

        // ---------- aktuelle Werte (linear zwischen den Stundenwerten) ----------
        $aktuell = [
            'Temperatur'              => ['TTT',   fn($x) => round($x - 273.15, 1)],
            'Bewoelkungsgrad'         => ['Neff',  fn($x) => round($x, 0)],
            'Druck'                   => ['PPPP',  fn($x) => round($x / 100, 1)],
            'Nebelwahrscheinlichkeit' => ['wwM',   fn($x) => round($x, 0)],
            'Niederschlag'            => ['RR1c',  fn($x) => round($x, 1)],
            'Sichtweite'              => ['VV',    fn($x) => round($x / 1000, 2)],
            'Sonnenscheindauer'       => ['SunD1', fn($x) => round($x, 0)],
            'Wind'                    => ['FF',    fn($x) => round($x * 3.6, 0)],
            'Windboeen'               => ['FX1',   fn($x) => round($x * 3.6, 0)],
        ];
        foreach ($aktuell as $ident => [$element, $format]) {
            $x = $this->Interpoliere($ts, $v[$element] ?? [], $jetzt);
            if ($x !== null) {
                $this->Setze($ident, $format($x));
            }
        }
        // Windrichtung (DD, Grad, woher der Wind kommt) über den kürzeren Bogen
        $dd = $this->Interpoliere($ts, $v['DD'] ?? [], $jetzt, true);
        if ($dd !== null) {
            $this->Setze('Windrichtung', fmod(round($dd), 360));
        }
        if ($mos['issue'] > 0) {
            $this->Setze('Datenstand', $mos['issue']);
        }
        if ($this->ReadPropertyBoolean('UVOpenMeteo')) {
            $uv = $this->LadeUV();
            if ($uv !== null) {
                $this->Setze('UVIndex', (int)round($uv));
            }
        }

        // ---------- Tageswerte ----------
        $tage = [];
        foreach ($ts as $i => $t) {
            if ($t <= $jetzt - 3600) {
                continue;                       // vergangene Stunden ignorieren
            }
            $tage[date('Y-m-d', $t - 1)][] = $i; // 00:00 gehört zum Vortag
        }
        $anzahl = $this->AnzahlTage();
        foreach (array_slice(array_keys($tage), 0, $anzahl) as $n => $datum) {
            $temps = [];
            $neffs = [];
            $zaehler = [];
            $regen = 0.0;
            $wahrsch = 0;
            $sonne = 0.0;
            foreach ($tage[$datum] as $i) {
                if (($v['TTT'][$i] ?? null) !== null) {
                    $temps[] = $v['TTT'][$i] - 273.15;
                }
                if (($v['Neff'][$i] ?? null) !== null) {
                    $neffs[] = $v['Neff'][$i];
                }
                $regen += (float)($v['RR1c'][$i] ?? 0);
                $wahrsch = max($wahrsch, (int)round((float)($v['R101'][$i] ?? 0)));
                $sonne += (float)($v['SunD1'][$i] ?? 0);
                $c = $this->WwZuZustand($v['ww'][$i] ?? null);
                if ($c !== null) {
                    $zaehler[$c] = ($zaehler[$c] ?? 0) + 1;
                }
            }
            $zustand = $this->TagesZustand($zaehler, count($neffs) ? array_sum($neffs) / count($neffs) : null);

            $p = 'Tag' . $n . '_';
            $this->Setze($p . 'Datum', $datum);
            $this->Setze($p . 'Wochentag', $this->Wochentag($datum));
            if (count($temps)) {
                $this->Setze($p . 'TempMax', round(max($temps), 1));
                $this->Setze($p . 'TempMin', round(min($temps), 1));
            }
            $this->Setze($p . 'Wetterzustand', $zustand);
            $this->Setze($p . 'WetterzustandText', self::ZUSTAND_TEXT[$zustand] ?? '.');
            $this->Setze($p . 'Niederschlag', round($regen, 1));
            $this->Setze($p . 'Regenwahrscheinlichkeit', $wahrsch);
            $this->Setze($p . 'Sonnenstunden', round($sonne / 3600, 1));
            $this->AktualisiereSymbol($n, $zustand);
        }

        $this->AktualisiereKachel();
        return true;
    }

    // =====================================================================
    // Variablen, Medien, Profile
    // =====================================================================

    private function PflegeVariablen(): void
    {
        $pos = 1;
        $this->MaintainVariable('Temperatur', 'Temperatur', VARIABLETYPE_FLOAT, '~Temperature', $pos++, true);
        $this->MaintainVariable('Bewoelkungsgrad', 'Bewölkungsgrad', VARIABLETYPE_FLOAT, 'DWDW.Prozent', $pos++, true);
        $this->MaintainVariable('Druck', 'Luftdruck', VARIABLETYPE_FLOAT, 'DWDW.Luftdruck', $pos++, true);
        $this->MaintainVariable('Nebelwahrscheinlichkeit', 'Nebelwahrscheinlichkeit', VARIABLETYPE_FLOAT, 'DWDW.Prozent', $pos++, true);
        $this->MaintainVariable('Niederschlag', 'Niederschlag', VARIABLETYPE_FLOAT, 'DWDW.Niederschlag', $pos++, true);
        $this->MaintainVariable('Sichtweite', 'Sichtweite', VARIABLETYPE_FLOAT, 'DWDW.Sichtweite', $pos++, true);
        $this->MaintainVariable('Sonnenscheindauer', 'Sonnenschein (laufende Stunde)', VARIABLETYPE_FLOAT, 'DWDW.Sonnenschein', $pos++, true);
        $this->MaintainVariable('Wind', 'Wind', VARIABLETYPE_FLOAT, 'DWDW.Wind', $pos++, true);
        $this->MaintainVariable('Windboeen', 'Windböen', VARIABLETYPE_FLOAT, 'DWDW.Wind', $pos++, true);
        $this->MaintainVariable('Windrichtung', 'Windrichtung', VARIABLETYPE_FLOAT, 'DWDW.Windrichtung', $pos++, true);
        $this->MaintainVariable('UVIndex', 'UV-Index (Tagesmaximum)', VARIABLETYPE_INTEGER, '~UVIndex', $pos++, $this->ReadPropertyBoolean('UVOpenMeteo'));
        $this->MaintainVariable('Datenstand', 'Datenstand MOSMIX', VARIABLETYPE_INTEGER, '~UnixTimestamp', $pos++, true);

        $anzahl = $this->AnzahlTage();
        for ($n = 0; $n < self::MAX_TAGE; $n++) {
            $da = $n < $anzahl;
            $tag = $this->TagName($n);
            $p = 'Tag' . $n . '_';
            $b = 100 + $n * 20;
            $this->MaintainVariable($p . 'Datum', $tag . ': Datum', VARIABLETYPE_STRING, '', $b + 1, $da);
            $this->MaintainVariable($p . 'Wochentag', $tag . ': Wochentag', VARIABLETYPE_STRING, '', $b + 2, $da);
            $this->MaintainVariable($p . 'TempMax', $tag . ': Temperatur Max', VARIABLETYPE_FLOAT, '~Temperature', $b + 3, $da);
            $this->MaintainVariable($p . 'TempMin', $tag . ': Temperatur Min', VARIABLETYPE_FLOAT, '~Temperature', $b + 4, $da);
            $this->MaintainVariable($p . 'Wetterzustand', $tag . ': Wetterzustand', VARIABLETYPE_STRING, '', $b + 5, $da);
            $this->MaintainVariable($p . 'WetterzustandText', $tag . ': Wetterzustand Text', VARIABLETYPE_STRING, '', $b + 6, $da);
            $this->MaintainVariable($p . 'Niederschlag', $tag . ': Niederschlag', VARIABLETYPE_FLOAT, 'DWDW.NiederschlagTag', $b + 7, $da);
            $this->MaintainVariable($p . 'Regenwahrscheinlichkeit', $tag . ': Regenwahrscheinlichkeit', VARIABLETYPE_INTEGER, 'DWDW.Wahrscheinlichkeit', $b + 8, $da);
            $this->MaintainVariable($p . 'Sonnenstunden', $tag . ': Sonnenstunden', VARIABLETYPE_FLOAT, 'DWDW.Sonnenstunden', $b + 9, $da);
            if ($da && $this->ReadPropertyBoolean('Symbole')) {
                $this->PflegeSymbolMedium($n, $tag, $b + 10);
            }
        }

        $text = $this->ReadPropertyBoolean('TextAktiv');
        $pos = 400;
        foreach (self::TEXTE as [$ident, $name]) {
            $this->MaintainVariable($ident, $name, VARIABLETYPE_STRING, '', $pos++, $text);
        }
        $this->MaintainVariable('TextStand', 'Textvorhersage Stand (TTHHMM)', VARIABLETYPE_STRING, '', $pos++, $text);
    }

    /**
     * Medienobjekt für das Wettersymbol eines Tages anlegen.
     * Jedes Medium bekommt eine eigene Datei (media/DWDW_<Instanz>_Tag<n>.png), damit beim
     * Löschen eines Mediums nie eine gemeinsam genutzte Symboldatei verschwindet.
     * Medien werden vom Modul bewusst nie gelöscht.
     */
    private function PflegeSymbolMedium(int $n, string $tag, int $pos): void
    {
        $ident = 'Tag' . $n . '_Symbol';
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id === false) {
            $id = IPS_CreateMedia(MEDIATYPE_IMAGE);
            IPS_SetParent($id, $this->InstanceID);
            IPS_SetIdent($id, $ident);
            IPS_SetName($id, $tag . ': Wettersymbol');
            IPS_SetPosition($id, $pos);
            IPS_SetMediaFile($id, $this->SymbolDatei($n), false);
        }
    }

    private function SymbolDatei(int $n): string
    {
        return 'media/DWDW_' . $this->InstanceID . '_Tag' . $n . '.png';
    }

    /**
     * Bilddatei für einen Wetterzustand ermitteln: [Kennung, Pfad] oder [.., null].
     * Satz "ordner": eigener Symbol-Ordner (VClouds-Nummerierung), fehlt dort ein Bild,
     * wird auf die mitgelieferten Meteocons zurückgegriffen.
     */
    private function SymbolQuelle(string $zustand, bool $istTag): array
    {
        $idx = $istTag ? 0 : 1;
        if ($this->ReadPropertyString('Symbolsatz') === 'ordner') {
            $ordner = IPS_GetKernelDir() . rtrim(str_replace('\\', '/', $this->ReadPropertyString('SymbolPfad')), '/') . '/';
            foreach ([self::SYMBOLE[$zustand][$idx] ?? null, '44.png'] as $datei) {
                if ($datei !== null && file_exists($ordner . $datei)) {
                    return ['ordner:' . $datei, $ordner . $datei];
                }
            }
        }
        foreach ([self::SYMBOLE_METEOCONS[$zustand][$idx] ?? null, 'not-available'] as $name) {
            $datei = __DIR__ . '/symbole/' . $name . '.png';
            if ($name !== null && file_exists($datei)) {
                return ['meteocons:' . $name, $datei];
            }
        }
        return ['', null];
    }

    private function AktualisiereSymbol(int $n, string $zustand): void
    {
        if (!$this->ReadPropertyBoolean('Symbole')) {
            return;
        }
        $id = @IPS_GetObjectIDByIdent('Tag' . $n . '_Symbol', $this->InstanceID);
        if ($id === false) {
            return;
        }
        $istTag = true;
        $tagVar = $this->ReadPropertyInteger('IstTagVariable');
        if ($n == 0 && $tagVar > 0 && IPS_VariableExists($tagVar)) {
            $istTag = (bool)GetValue($tagVar);
        }
        [$bild, $quelle] = $this->SymbolQuelle($zustand, $istTag);
        if ($quelle === null) {
            $this->LogMessage('Kein Wettersymbol für "' . $zustand . '" gefunden', KL_WARNING);
            return;
        }

        // eigene Datei sicherstellen (übernommene Medien zeigten auf gemeinsame Symboldateien)
        $ziel = $this->SymbolDatei($n);
        $aktuell = ltrim(str_replace('\\', '/', IPS_GetMedia($id)['MediaFile']), '/');
        $stand = json_decode($this->ReadAttributeString('SymbolStand'), true) ?: [];
        if ($aktuell !== $ziel) {
            IPS_SetMediaFile($id, $ziel, false);
            unset($stand[$n]);
        }
        if (($stand[$n] ?? '') === $bild) {
            return;
        }
        IPS_SetMediaContent($id, base64_encode(file_get_contents($quelle)));
        $stand[$n] = $bild;
        $this->WriteAttributeString('SymbolStand', json_encode($stand));
    }

    private function PflegeIstTagNachricht(): void
    {
        $alt = $this->ReadAttributeInteger('IstTagRegistriert');
        $neu = $this->ReadPropertyInteger('IstTagVariable');
        if ($alt > 0 && $alt != $neu) {
            $this->UnregisterMessage($alt, VM_UPDATE);
        }
        if ($neu > 0 && IPS_VariableExists($neu)) {
            $this->RegisterMessage($neu, VM_UPDATE);
            $this->RegisterReference($neu);
            $this->WriteAttributeInteger('IstTagRegistriert', $neu);
        } else {
            $this->WriteAttributeInteger('IstTagRegistriert', 0);
        }
    }

    private function RegisterProfile(string $name, int $typ, string $prefix, string $suffix, float $min, float $max, float $schritt, int $stellen, string $icon): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, $typ);
        }
        IPS_SetVariableProfileText($name, $prefix, $suffix);
        IPS_SetVariableProfileValues($name, $min, $max, $schritt);
        if ($typ == VARIABLETYPE_FLOAT) {
            IPS_SetVariableProfileDigits($name, $stellen);
        }
        IPS_SetVariableProfileIcon($name, $icon);
    }

    /** 16-teilige Windrose als Assoziationen (Wert = Grad, woher der Wind kommt) */
    private function RegisterWindrichtungen(string $name): void
    {
        $rose = ['N', 'NNO', 'NO', 'ONO', 'O', 'OSO', 'SO', 'SSO', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];
        $soll = [0.0 => 'N'];
        foreach ($rose as $i => $r) {
            $soll[(string)($i * 22.5 + 11.25)] = $rose[($i + 1) % 16];
        }
        $ist = [];
        foreach (IPS_GetVariableProfile($name)['Associations'] as $a) {
            $ist[(string)(float)$a['Value']] = $a['Name'];
        }
        if ($ist == array_combine(array_map(fn($k) => (string)(float)$k, array_keys($soll)), $soll)) {
            return;
        }
        foreach (array_keys($ist) as $wert) {
            IPS_SetVariableProfileAssociation($name, (float)$wert, '', '', -1);
        }
        foreach ($soll as $wert => $text) {
            IPS_SetVariableProfileAssociation($name, (float)$wert, $text, '', -1);
        }
    }

    /** Wert nur bei Änderung schreiben (schont Archiv und Ereignisse) */
    private function Setze(string $ident, $wert): void
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id === false || !IPS_VariableExists($id)) {
            return;
        }
        switch (IPS_GetVariable($id)['VariableType']) {
            case VARIABLETYPE_BOOLEAN: $wert = (bool)$wert; break;
            case VARIABLETYPE_INTEGER: $wert = (int)round((float)$wert); break;
            case VARIABLETYPE_FLOAT:   $wert = (float)$wert; break;
            default:                   $wert = (string)$wert;
        }
        if (GetValue($id) !== $wert) {
            $this->SetValue($ident, $wert);
        }
    }

    private function Lese(string $ident, $standard = null)
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        return ($id !== false && IPS_VariableExists($id)) ? GetValue($id) : $standard;
    }

    // =====================================================================
    // Datenquellen
    // =====================================================================

    private function LadeMosmix(): ?array
    {
        $station = strtoupper(trim($this->ReadPropertyString('Station')));
        $cache = IPS_GetKernelDir() . 'dwd_mosmix_' . $station . '.kml';
        $kml = null;
        $cacheMin = max(5, $this->ReadPropertyInteger('CacheMinuten'));

        if (file_exists($cache) && filemtime($cache) > time() - $cacheMin * 60) {
            $kml = file_get_contents($cache);
        } else {
            $kmz = $this->Hole(sprintf(self::MOSMIX_URL, $station), 30);
            if ($kmz !== null) {
                $tmp = tempnam(sys_get_temp_dir(), 'mos');
                file_put_contents($tmp, $kmz);
                $zip = new ZipArchive();
                if ($zip->open($tmp) === true) {
                    $kml = $zip->getFromIndex(0) ?: null;
                    $zip->close();
                }
                @unlink($tmp);
                if ($kml) {
                    file_put_contents($cache, $kml);
                    $this->SendDebug('MOSMIX', 'neu geladen: ' . strlen($kml) . ' Bytes', 0);
                }
            }
            if (!$kml && file_exists($cache)) {
                $kml = file_get_contents($cache);   // Rückfall: alter Stand
                $this->SendDebug('MOSMIX', 'Download fehlgeschlagen, nutze Cache', 0);
            }
        }
        if (!$kml) {
            return null;
        }

        try {
            $x = new SimpleXMLElement($kml);
        } catch (Throwable $e) {
            @unlink($cache);
            return null;
        }
        $x->registerXPathNamespace('dwd', self::MOSMIX_NS);
        $ts = array_map(fn($t) => strtotime((string)$t), $x->xpath('//dwd:ForecastTimeSteps/dwd:TimeStep') ?: []);
        if (!count($ts)) {
            return null;
        }
        $werte = [];
        foreach ($x->xpath('//dwd:Forecast') ?: [] as $f) {
            $name = (string)$f->attributes(self::MOSMIX_NS)['elementName'];
            $roh = preg_split('/\s+/', trim((string)$f->children(self::MOSMIX_NS)->value));
            $werte[$name] = array_map(fn($s) => $s === '-' ? null : (float)$s, $roh);
        }
        $issue = $x->xpath('//dwd:IssueTime');
        return [
            'ts'    => $ts,
            'werte' => $werte,
            'issue' => isset($issue[0]) ? (int)strtotime((string)$issue[0]) : 0,
        ];
    }

    private function LadeTextberichte(): void
    {
        $cacheMin = max(5, $this->ReadPropertyInteger('CacheMinuten'));
        if ($this->ReadAttributeInteger('TextAbruf') > time() - $cacheMin * 60) {
            return;
        }
        $this->WriteAttributeInteger('TextAbruf', time());

        $kennung = strtoupper(trim($this->ReadPropertyString('TextKennung'), " _\t"));
        $anhang = $this->ReadPropertyString('TextAnhang');
        $dateien = $this->ReadPropertyBoolean('BerichtDateien');
        $pfad = IPS_GetKernelDir() . rtrim(str_replace('\\', '/', $this->ReadPropertyString('BerichtPfad')), '/') . '/';
        $neuester = 0;

        foreach (self::TEXTE as $typ => [$ident, , $datei]) {
            $kopf = [];
            $roh = $this->Hole(sprintf(self::TEXT_URL, $typ, $kennung), 20, $kopf);
            if ($roh === null) {
                $this->SendDebug('Text', "$typ nicht abrufbar – alter Text bleibt", 0);
                continue;
            }
            foreach ($kopf as $h) {
                if (stripos($h, 'Last-Modified:') === 0) {
                    $neuester = max($neuester, (int)strtotime(trim(substr($h, 14))));
                }
            }
            $text = $this->BereinigeBericht($roh);
            if ($text === '') {
                continue;
            }
            $this->Setze($ident, $text . $anhang);
            if ($dateien) {
                // die alten Wetterbericht-Seiten lesen ISO-8859-1 (utf8_encode)
                @file_put_contents($pfad . $datei, mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8'));
            }
        }
        if ($neuester > 0) {
            $this->Setze('TextStand', gmdate('dHi', $neuester));
        }
    }

    private function LadeUV(): ?float
    {
        $url = sprintf(self::UV_URL, $this->ReadPropertyFloat('Latitude'), $this->ReadPropertyFloat('Longitude'));
        $json = $this->Hole($url, 15);
        if ($json === null) {
            return null;
        }
        $uv = json_decode($json, true)['daily']['uv_index_max'][0] ?? null;
        return $uv === null ? null : (float)$uv;
    }

    /** HTTP-GET; liefert Inhalt oder null, Header in $kopf */
    private function Hole(string $url, int $timeout, array &$kopf = []): ?string
    {
        $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true]]);
        $daten = @file_get_contents($url, false, $ctx);
        $kopf = $http_response_header ?? [];
        $status = $kopf[0] ?? '';
        if ($daten === false || strpos($status, ' 200') === false) {
            $this->SendDebug('HTTP', "$status $url", 0);
            return null;
        }
        return $daten;
    }

    private function BereinigeBericht(string $roh): string
    {
        if (!mb_check_encoding($roh, 'UTF-8')) {
            $roh = mb_convert_encoding($roh, 'UTF-8', 'ISO-8859-1');
        }
        $t = str_replace('Copyright (c) Deutscher Wetterdienst', '', $roh);
        $t = preg_replace('/([\w]{4}[\d]{2}) ([\w]{4}) ([\d]{6})/', '', $t);
        $t = str_replace(['<br></br>', '=', chr(1), chr(3)], '', $t);
        $t = strip_tags($t, '<p></p><br><br/><br />');
        $t = str_replace(['<strong>', '</strong>'], '', $t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(trim(trim($t), "\x00..\x1F"));
    }

    // =====================================================================
    // Berechnung
    // =====================================================================

    /**
     * Linear zwischen zwei Stundenwerten interpolieren.
     * $winkel: Werte sind Richtungen in Grad – über den kürzeren Bogen interpolieren
     * (350° → 10° ergibt 0°, nicht 180°).
     */
    private function Interpoliere(array $ts, array $werte, int $zeit, bool $winkel = false): ?float
    {
        $n = count($ts);
        for ($i = 0; $i < $n - 1; $i++) {
            if ($ts[$i] <= $zeit && $ts[$i + 1] > $zeit) {
                $a = $werte[$i] ?? null;
                $b = $werte[$i + 1] ?? null;
                if ($a === null) {
                    return $b;
                }
                if ($b === null) {
                    return $a;
                }
                $anteil = ($zeit - $ts[$i]) / ($ts[$i + 1] - $ts[$i]);
                if ($winkel) {
                    $diff = fmod(fmod($b - $a, 360) + 540, 360) - 180;
                    return fmod($a + $diff * $anteil + 360, 360);
                }
                return $a + ($b - $a) * $anteil;
            }
        }
        if ($n && $zeit < $ts[0]) {
            return $werte[0] ?? null;
        }
        return null;
    }

    private function WwZuZustand($ww): ?string
    {
        if ($ww === null) {
            return null;
        }
        $w = (int)$ww;
        if ($w >= 95) {
            return 'lightning-rainy';
        }
        if (in_array($w, [56, 57, 66, 67, 68, 69, 83, 84])) {
            return 'snowy-rainy';
        }
        if (($w >= 70 && $w <= 79) || $w == 85 || $w == 86) {
            return 'snowy';
        }
        if (in_array($w, [65, 82])) {
            return 'pouring';
        }
        if (($w >= 50 && $w <= 67) || ($w >= 80 && $w <= 82)) {
            return 'rainy';
        }
        if ($w == 45 || $w == 49) {
            return 'fog';
        }
        if ($w == 3) {
            return 'cloudy';
        }
        if ($w == 1 || $w == 2) {
            return 'partlycloudy';
        }
        return 'sunny';
    }

    /** Niederschlag/Gewitter/Nebel ab n Stunden, sonst nach mittlerer effektiver Bewölkung */
    private function TagesZustand(array $zaehler, ?float $mittelNeff): string
    {
        $min = max(1, $this->ReadPropertyInteger('RegenStunden'));
        foreach (['lightning-rainy', 'snowy-rainy', 'snowy', 'pouring', 'rainy', 'fog'] as $c) {
            if (($zaehler[$c] ?? 0) >= $min) {
                return $c;
            }
        }
        if ((($zaehler['pouring'] ?? 0) + ($zaehler['rainy'] ?? 0)) >= $min) {
            return 'rainy';
        }
        if ($mittelNeff === null) {
            return 'cloudy';
        }
        if ($mittelNeff < 25) {
            return 'sunny';
        }
        if ($mittelNeff < 70) {
            return 'partlycloudy';
        }
        return 'cloudy';
    }

    private function Wochentag(string $datum): string
    {
        $tage = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
        return $tage[(int)date('N', strtotime($datum)) - 1];
    }

    private function TagName(int $n): string
    {
        return $n == 0 ? 'Heute' : ($n == 1 ? 'Morgen' : 'Tag ' . $n);
    }

    private function AnzahlTage(): int
    {
        return max(1, min(self::MAX_TAGE, $this->ReadPropertyInteger('Tage')));
    }

    private function StationGueltig(): bool
    {
        return (bool)preg_match('/^[A-Z0-9]{4,5}$/', strtoupper(trim($this->ReadPropertyString('Station'))));
    }

    // =====================================================================
    // Kachel
    // =====================================================================

    private function KachelDaten(): array
    {
        $istTag = true;
        $tagVar = $this->ReadPropertyInteger('IstTagVariable');
        if ($tagVar > 0 && IPS_VariableExists($tagVar)) {
            $istTag = (bool)GetValue($tagVar);
        }
        $tage = [];
        for ($n = 0; $n < $this->AnzahlTage(); $n++) {
            $p = 'Tag' . $n . '_';
            $datum = (string)$this->Lese($p . 'Datum', '');
            if ($datum === '') {
                continue;
            }
            $tage[] = [
                'name'   => $n == 0 ? 'Heute' : ($n == 1 ? 'Morgen' : mb_substr((string)$this->Lese($p . 'Wochentag', ''), 0, 2)),
                'datum'  => date('d.m.', strtotime($datum)),
                'zustand'=> (string)$this->Lese($p . 'Wetterzustand', ''),
                'text'   => (string)$this->Lese($p . 'WetterzustandText', ''),
                'max'    => $this->Lese($p . 'TempMax'),
                'min'    => $this->Lese($p . 'TempMin'),
                'regen'  => $this->Lese($p . 'Niederschlag'),
                'prob'   => $this->Lese($p . 'Regenwahrscheinlichkeit'),
                'sonne'  => $this->Lese($p . 'Sonnenstunden'),
            ];
        }
        return [
            'tag'     => $istTag,
            'aktuell' => [
                'temp'  => $this->Lese('Temperatur'),
                'wolken'=> $this->Lese('Bewoelkungsgrad'),
                'regen' => $this->Lese('Niederschlag'),
                'wind'  => $this->Lese('Wind'),
                'boeen' => $this->Lese('Windboeen'),
                'richtung' => $this->Lese('Windrichtung'),
                'druck' => $this->Lese('Druck'),
                'sicht' => $this->Lese('Sichtweite'),
                'uv'    => $this->Lese('UVIndex'),
            ],
            'tage'    => $tage,
            'texte'   => [
                'heute'  => (string)$this->Lese('TextHeute', ''),
                'morgen' => (string)$this->Lese('TextMorgen', ''),
            ],
            'stand'   => ($s = (int)$this->Lese('Datenstand', 0)) ? date('d.m. H:i', $s) : '',
        ];
    }

    private function AktualisiereKachel(): void
    {
        $this->UpdateVisualizationValue(json_encode($this->KachelDaten(), JSON_UNESCAPED_UNICODE));
    }
}
