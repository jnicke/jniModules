<?php

declare(strict_types=1);

/**
 * jniVVOAbfahrt – Abfahrtsmonitor für eine Haltestelle der DVB / des VVO
 *
 * Quelle: VVO-WebAPI (https://webapi.vvo-online.de), dieselbe API wie dvb-browser,
 * dvbjs und die offizielle VVO-App. Kein API-Schlüssel nötig.
 *  - /tr/pointfinder  Haltestellensuche
 *  - /dm              Abfahrten mit Echtzeit, Ausfällen und Auslastung
 *  - /rc              Störungen, Baustellen, Umleitungen
 *
 * Statusvariablen: nächste erreichbare Abfahrt (Linie, Ziel, Zeit, Minuten, Verspätung),
 * Losgehen in / Jetzt losgehen (aus dem Fußweg), Ausfall, Störungen, Datenstand.
 * Kachel-Visualisierung über das HTML-SDK (module.html) mit sekundengenauem Countdown im Browser.
 *
 * IPSView / Browser: dieselbe Ansicht als eigenständige Seite über WebHook (Web-Control)
 *   /hook/vvo<InstanzID>?theme=dark|light&bg=<hex>|transparent&size=<px, Standard 20>&header=0&refresh=<s>&icons=0
 *   /hook/vvo<InstanzID>?json=1   Rohdaten der Ansicht
 */
class jniVVOAbfahrt extends IPSModule
{
    private const API = 'https://webapi.vvo-online.de/';

    /** API-Kennung => [Property, Anzeigename] */
    private const MOTS = [
        'Tram'             => ['MotTram', 'Straßenbahn'],
        'CityBus'          => ['MotCityBus', 'Stadtbus'],
        'IntercityBus'     => ['MotIntercityBus', 'Regionalbus'],
        'SuburbanRailway'  => ['MotSuburbanRailway', 'S-Bahn'],
        'Train'            => ['MotTrain', 'Zug'],
        'Cableway'         => ['MotCableway', 'Seilbahn'],
        'Ferry'            => ['MotFerry', 'Fähre'],
        'HailedSharedTaxi' => ['MotHailedSharedTaxi', 'Rufbus'],
    ];

    private const STOERUNG_INTERVALL = 900;   // s
    private const WEBHOOK_GUID = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('StopID', '');
        $this->RegisterPropertyInteger('Anzahl', 8);
        foreach (self::MOTS as $mot => [$prop, $name]) {
            $this->RegisterPropertyBoolean($prop, true);
        }
        $this->RegisterPropertyString('Linien', '');
        $this->RegisterPropertyString('Richtungen', '');
        $this->RegisterPropertyString('Steige', '');
        $this->RegisterPropertyBoolean('Stoerungen', true);
        $this->RegisterPropertyInteger('Fussweg', 3);
        $this->RegisterPropertyInteger('Vorlauf', 1);
        $this->RegisterPropertyBoolean('Ausblenden', false);
        $this->RegisterPropertyInteger('Intervall', 60);
        $this->RegisterPropertyBoolean('Zeitfenster', false);
        $this->RegisterPropertyString('AktivVon', '{"hour":6,"minute":0,"second":0}');
        $this->RegisterPropertyString('AktivBis', '{"hour":20,"minute":0,"second":0}');
        $this->RegisterPropertyBoolean('NurWerktage', false);

        $this->RegisterAttributeString('Haltestelle', '{}');      // {"id","name","ort"}
        $this->RegisterAttributeString('Suchtreffer', '[]');
        $this->RegisterAttributeString('Fehler', '');
        $this->RegisterAttributeInteger('Fehlversuche', 0);
        $this->RegisterAttributeInteger('StoerungAbruf', 0);

        $this->SetBuffer('Abfahrten', '[]');
        $this->SetBuffer('Stoerungen', '[]');

        $this->RegisterTimer('Update', 0, 'VVO_Update($_IPS[\'TARGET\']);');

        $this->RegisterProfile('VVO.Minuten', VARIABLETYPE_INTEGER, '', ' min', 0, 120, 1, 'Clock');
        $this->RegisterProfile('VVO.Verspaetung', VARIABLETYPE_INTEGER, '+', ' min', 0, 60, 1, 'Hourglass');
        if (!IPS_VariableProfileExists('VVO.Losgehen')) {
            IPS_CreateVariableProfile('VVO.Losgehen', VARIABLETYPE_BOOLEAN);
        }
        IPS_SetVariableProfileIcon('VVO.Losgehen', 'Motion');
        IPS_SetVariableProfileAssociation('VVO.Losgehen', 0, 'Noch Zeit', '', -1);
        IPS_SetVariableProfileAssociation('VVO.Losgehen', 1, 'Jetzt losgehen', '', 0xFF8000);

        $this->SetVisualizationType(2);   // HTML-Kachel
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        $p = 1;
        $this->RegisterVariableString('Linie', 'Nächste Linie', '', $p++);
        $this->RegisterVariableString('Ziel', 'Nächstes Ziel', '', $p++);
        $this->RegisterVariableInteger('Abfahrt', 'Nächste Abfahrt', '~UnixTimestampTime', $p++);
        $this->RegisterVariableInteger('Minuten', 'Abfahrt in', 'VVO.Minuten', $p++);
        $this->RegisterVariableInteger('Verspaetung', 'Verspätung', 'VVO.Verspaetung', $p++);
        $this->RegisterVariableString('Steig', 'Steig', '', $p++);
        $this->RegisterVariableInteger('LosgehenIn', 'Losgehen in', 'VVO.Minuten', $p++);
        $this->RegisterVariableBoolean('JetztLosgehen', 'Jetzt losgehen', 'VVO.Losgehen', $p++);
        $this->RegisterVariableBoolean('Ausfall', 'Ausfall in der Liste', '~Alert', $p++);
        if ($this->ReadPropertyBoolean('Stoerungen')) {
            $this->RegisterVariableString('Stoerung', 'Störungen / Baustellen', '', $p++);
        } else {
            $this->UnregisterVariable('Stoerung');
        }
        $p++;
        $this->RegisterVariableInteger('Datenstand', 'Datenstand', '~UnixTimestamp', 20);

        if ($this->Haltestelle()['id'] !== $this->ReadPropertyString('StopID')) {
            $this->WriteAttributeString('Haltestelle', json_encode(['id' => $this->ReadPropertyString('StopID'), 'name' => '', 'ort' => '']));
            $this->WriteAttributeInteger('StoerungAbruf', 0);
            $this->SetBuffer('Abfahrten', '[]');
            $this->SetBuffer('Stoerungen', '[]');
        }

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->RegisterHook();
        }

        $this->SetTimerInterval('Update', 0);
        if (!$this->StopGueltig()) {
            $this->SetStatus(202);
            return;
        }
        if (count($this->Verkehrsmittel()) == 0) {
            $this->SetStatus(203);
            return;
        }
        $this->SetStatus(102);
        $this->SetTimerInterval('Update', max(30, $this->ReadPropertyInteger('Intervall')) * 1000);

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->Update();
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == IPS_KERNELSTARTED) {
            $this->RegisterHook();
            $this->Update();
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

        // Auswahlliste: aktuelle Haltestelle + letzte Suchtreffer
        $optionen = [['caption' => '– keine –', 'value' => '']];
        $h = $this->Haltestelle();
        if ($h['id'] !== '') {
            $optionen[] = ['caption' => $this->Anzeigename($h), 'value' => $h['id']];
        }
        foreach (json_decode($this->ReadAttributeString('Suchtreffer'), true) ?: [] as $t) {
            if ($t['id'] !== $h['id']) {
                $optionen[] = ['caption' => $this->Anzeigename($t), 'value' => $t['id']];
            }
        }
        $form['elements'][0]['items'][0]['options'] = $optionen;

        foreach ($form['actions'] as &$a) {
            if (($a['name'] ?? '') === 'Info') {
                $a['caption'] = $this->InfoText();
            }
            if (($a['name'] ?? '') === 'HookInfo') {
                $a['caption'] = 'IPSView Web-Control / Browser: http://<Symcon-IP>:3777' . $this->HookPfad()
                    . '   (Optionen: ?theme=dark|light &bg=<hex>|transparent &size=<px> &header=0 &refresh=<s> &icons=0)';
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

    // =====================================================================
    // Öffentliche Funktionen
    // =====================================================================

    /**
     * Sucht Haltestellen und füllt die Auswahlliste im Formular.
     * VVO_Suchen($InstanzID, 'Berthold-Haupt'); liefert JSON [{id, name, ort}]
     */
    public function Suchen(string $Suchbegriff): string
    {
        $Suchbegriff = trim($Suchbegriff);
        if (mb_strlen($Suchbegriff) < 2) {
            echo 'Bitte mindestens 2 Zeichen eingeben.';
            return '[]';
        }
        $r = $this->Api('tr/pointfinder', [
            'query'         => $Suchbegriff,
            'stopsOnly'     => true,
            'limit'         => 15,
            'assignedStops' => true,
            'dvb'           => true,
        ]);
        $treffer = [];
        foreach ($r['Points'] ?? [] as $p) {
            $f = explode('|', $p);
            if (($f[3] ?? '') === '' || !ctype_digit($f[0])) {
                continue;
            }
            $treffer[$f[0]] = ['id' => $f[0], 'name' => $f[3], 'ort' => $f[2] === '' ? 'Dresden' : $f[2]];
        }
        $treffer = array_values($treffer);
        $this->WriteAttributeString('Suchtreffer', json_encode($treffer, JSON_UNESCAPED_UNICODE));

        if (count($treffer) == 0) {
            echo 'Keine Haltestelle gefunden für "' . $Suchbegriff . '".';
            return '[]';
        }
        $optionen = [];
        $h = $this->Haltestelle();
        if ($h['id'] !== '' && !in_array($h['id'], array_column($treffer, 'id'), true)) {
            $optionen[] = ['caption' => $this->Anzeigename($h), 'value' => $h['id']];
        }
        foreach ($treffer as $t) {
            $optionen[] = ['caption' => $this->Anzeigename($t), 'value' => $t['id']];
        }
        $this->UpdateFormField('StopID', 'options', json_encode($optionen));
        $this->UpdateFormField('StopID', 'value', $treffer[0]['id']);
        return json_encode($treffer, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Holt die Abfahrten und schreibt alle Variablen.
     * VVO_Update($InstanzID);
     */
    public function Update(): bool
    {
        if (!$this->StopGueltig()) {
            $this->SetStatus(202);
            return false;
        }
        $mots = $this->Verkehrsmittel();
        if (count($mots) == 0) {
            $this->SetStatus(203);
            return false;
        }

        if (!$this->ImZeitfenster()) {
            if ($this->GetStatus() != 104) {
                $this->SetStatus(104);
                $this->SetBuffer('Abfahrten', '[]');
                $this->SchreibeVariablen([]);
                $this->AktualisiereKachel();
            }
            return true;
        }

        $anzahl = max(1, $this->ReadPropertyInteger('Anzahl'));
        $gefiltert = $this->ReadPropertyString('Linien') !== '' || $this->ReadPropertyString('Richtungen') !== ''
            || $this->ReadPropertyString('Steige') !== '' || $this->ReadPropertyBoolean('Ausblenden');
        $limit = min(80, $gefiltert ? max(30, $anzahl * 4) : $anzahl + 10);

        $r = $this->Api('dm', [
            'stopid'           => $this->ReadPropertyString('StopID'),
            'time'             => gmdate('Y-m-d\TH:i:s\Z'),
            'isarrival'        => false,
            'limit'            => $limit,
            'shorttermchanges' => true,
            'mentzonly'        => false,
            'mot'              => $mots,
        ]);

        $code = $r['Status']['Code'] ?? '';
        $keineLinien = $code === 'ServiceError' && stripos($r['Status']['Message'] ?? '', 'no serving lines') !== false;
        if ($r === null || ($code !== 'Ok' && !$keineLinien)) {
            $n = $this->ReadAttributeInteger('Fehlversuche') + 1;
            $this->WriteAttributeInteger('Fehlversuche', $n);
            if ($r !== null) {
                $this->WriteAttributeString('Fehler', trim($code . ' ' . ($r['Status']['Message'] ?? '')));
            }
            $this->SendDebug('Update', 'Fehlversuch ' . $n . ': ' . $this->ReadAttributeString('Fehler'), 0);
            if ($n >= 3) {
                $this->SetStatus(201);
                $this->AktualisiereKachel();
            }
            return false;
        }
        $this->WriteAttributeInteger('Fehlversuche', 0);
        $this->WriteAttributeString('Fehler', '');
        if ($this->GetStatus() != 102) {
            $this->SetStatus(102);
        }

        if (isset($r['Name'])) {
            $h = ['id' => $this->ReadPropertyString('StopID'), 'name' => (string)$r['Name'], 'ort' => (string)($r['Place'] ?? '')];
            if ($h != $this->Haltestelle()) {
                $this->WriteAttributeString('Haltestelle', json_encode($h, JSON_UNESCAPED_UNICODE));
            }
        }

        $liste = [];
        foreach ($r['Departures'] ?? [] as $dep) {
            $a = $this->Abfahrt($dep);
            if ($a !== null && $this->Passt($a)) {
                $liste[] = $a;
            }
        }
        usort($liste, fn($x, $y) => $x['e'] <=> $y['e']);

        $jetzt = time();
        $fussweg = $this->ReadPropertyInteger('Fussweg') * 60;
        if ($this->ReadPropertyBoolean('Ausblenden')) {
            $liste = array_values(array_filter($liste, fn($a) => $a['e'] - $jetzt >= $fussweg));
        }
        $liste = array_slice($liste, 0, $anzahl);

        $this->SetBuffer('Abfahrten', json_encode($liste, JSON_UNESCAPED_UNICODE));

        if ($this->ReadPropertyBoolean('Stoerungen')) {
            $this->LadeStoerungen(array_merge(...array_map(fn($a) => $a['d'], $liste ?: [['d' => []]])));
        }

        $this->SchreibeVariablen($liste);
        $this->Setze('Datenstand', $jetzt);
        $this->AktualisiereKachel();
        return true;
    }

    /**
     * Abfahrten als JSON für eigene Skripte:
     * [{l: Linie, z: Ziel, m: Verkehrsmittel, s: Steig, p: Plan (Unix), e: Echtzeit (Unix),
     *   v: Verspätung (min), a: Ausfall, o: Auslastung, st: Status}]
     */
    public function GetAbfahrten(): string
    {
        return $this->GetBuffer('Abfahrten');
    }

    // =====================================================================
    // Auswertung
    // =====================================================================

    private function Abfahrt(array $d): ?array
    {
        $plan = $this->ApiDatum($d['ScheduledTime'] ?? null);
        if ($plan === null) {
            return null;
        }
        $echt = $this->ApiDatum($d['RealTime'] ?? null);
        $status = (string)($d['State'] ?? 'Unknown');
        $ausfall = $status === 'Cancelled' || !empty($d['CancelReasons']);

        $divas = [];
        if (isset($d['Diva']['Number'])) {
            $divas[] = $d['Diva']['Number'] . ':' . ($d['Diva']['Network'] ?? '');
        }

        return [
            'l'  => (string)($d['LineName'] ?? ''),
            'z'  => (string)($d['Direction'] ?? ''),
            'm'  => (string)($d['Mot'] ?? ''),
            's'  => (string)($d['Platform']['Name'] ?? ''),
            'p'  => $plan,
            'e'  => $echt ?? $plan,
            'v'  => $echt === null ? null : (int)round(($echt - $plan) / 60),
            'a'  => $ausfall,
            'ag' => $ausfall ? $this->Ausfallgrund($d['CancelReasons'] ?? []) : '',
            'o'  => (string)($d['Occupancy'] ?? 'Unknown'),
            'st' => $status,
            'd'  => $divas,
        ];
    }

    private function Ausfallgrund($gruende): string
    {
        $t = [];
        foreach ((array)$gruende as $g) {
            $t[] = is_array($g) ? (string)($g['Text'] ?? $g['Title'] ?? json_encode($g)) : (string)$g;
        }
        return trim(implode(', ', array_filter($t)));
    }

    private function Passt(array $a): bool
    {
        $linien = $this->Liste('Linien');
        if ($linien && !in_array(mb_strtolower($a['l']), $linien, true)) {
            return false;
        }
        $richtungen = $this->Liste('Richtungen');
        if ($richtungen) {
            $treffer = false;
            foreach ($richtungen as $r) {
                if (mb_stripos($a['z'], $r) !== false) {
                    $treffer = true;
                    break;
                }
            }
            if (!$treffer) {
                return false;
            }
        }
        $steige = $this->Liste('Steige');
        if ($steige && !in_array(mb_strtolower($a['s']), $steige, true)) {
            return false;
        }
        return true;
    }

    private function SchreibeVariablen(array $liste): void
    {
        $jetzt = time();
        $fussweg = $this->ReadPropertyInteger('Fussweg');
        $naechste = null;
        foreach ($liste as $a) {
            if (!$a['a'] && $a['e'] - $jetzt >= $fussweg * 60) {
                $naechste = $a;
                break;
            }
        }

        if ($naechste === null) {
            $this->Setze('Linie', '');
            $this->Setze('Ziel', '');
            $this->Setze('Abfahrt', 0);
            $this->Setze('Minuten', 0);
            $this->Setze('Verspaetung', 0);
            $this->Setze('Steig', '');
            $this->Setze('LosgehenIn', 0);
            $this->Setze('JetztLosgehen', false);
        } else {
            $min = intdiv(max(0, $naechste['e'] - $jetzt), 60);
            $los = max(0, $min - $fussweg);
            $this->Setze('Linie', $naechste['l']);
            $this->Setze('Ziel', $naechste['z']);
            $this->Setze('Abfahrt', $naechste['e']);
            $this->Setze('Minuten', $min);
            $this->Setze('Verspaetung', max(0, (int)$naechste['v']));
            $this->Setze('Steig', $naechste['s']);
            $this->Setze('LosgehenIn', $los);
            $this->Setze('JetztLosgehen', $los <= $this->ReadPropertyInteger('Vorlauf'));
        }
        $this->Setze('Ausfall', count(array_filter($liste, fn($a) => $a['a'])) > 0);

        if ($this->ReadPropertyBoolean('Stoerungen')) {
            $s = json_decode($this->GetBuffer('Stoerungen'), true) ?: [];
            $this->Setze('Stoerung', implode("\n", array_map(fn($x) => ($x['l'] ? 'Linie ' . implode(', ', $x['l']) . ': ' : '') . $x['t'], $s)));
        }
    }

    /** Störungen/Baustellen aus /rc für die Linien der aktuellen Abfahrten (höchstens alle 15 min) */
    private function LadeStoerungen(array $divas): void
    {
        if (time() - $this->ReadAttributeInteger('StoerungAbruf') < self::STOERUNG_INTERVALL) {
            return;
        }
        $r = $this->Api('rc', ['shortterm' => true]);
        if (($r['Status']['Code'] ?? '') !== 'Ok') {
            return;
        }
        $this->WriteAttributeInteger('StoerungAbruf', time());

        $divas = array_flip(array_unique($divas));
        $linien = [];   // Linien-Id => Name, nur Linien dieser Haltestelle
        foreach ($r['Lines'] ?? [] as $l) {
            $ids = [(string)($l['Id'] ?? '')];
            foreach ($l['Divas'] ?? [] as $dv) {
                $ids[] = ($dv['Number'] ?? '') . ':' . ($dv['Network'] ?? '');
            }
            foreach ($ids as $id) {
                if (isset($divas[$id])) {
                    $linien[(string)$l['Id']] = (string)$l['Name'];
                    break;
                }
            }
        }

        $jetzt = time();
        $liste = [];
        foreach ($r['Changes'] ?? [] as $c) {
            $betroffen = array_values(array_unique(array_filter(array_map(fn($id) => $linien[$id] ?? null, $c['LineIds'] ?? []))));
            if (!$betroffen) {
                continue;
            }
            $gueltig = empty($c['ValidityPeriods']);
            foreach ($c['ValidityPeriods'] ?? [] as $v) {
                $von = $this->ApiDatum($v['Begin'] ?? null) ?? 0;
                $bis = $this->ApiDatum($v['End'] ?? null) ?? PHP_INT_MAX;
                if ($von <= $jetzt + 3600 && $bis >= $jetzt) {
                    $gueltig = true;
                    break;
                }
            }
            if (!$gueltig) {
                continue;
            }
            natsort($betroffen);
            $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", (string)($c['Description'] ?? ''))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim(preg_replace("/[ \t\x{00A0}]+/u", ' ', $text));
            $liste[] = [
                't'  => trim((string)($c['Title'] ?? '')),
                'b'  => mb_substr($text, 0, 600),
                'l'  => array_values($betroffen),
                'kurz' => ($c['Type'] ?? '') === 'ShortTerm',
            ];
        }
        usort($liste, fn($x, $y) => $y['kurz'] <=> $x['kurz']);
        $this->SetBuffer('Stoerungen', json_encode($liste, JSON_UNESCAPED_UNICODE));
    }

    // =====================================================================
    // Kachel
    // =====================================================================

    private function KachelDaten(): array
    {
        $h = $this->Haltestelle();
        return [
            'name'       => $h['name'] !== '' ? $h['name'] : ($h['id'] !== '' ? 'Haltestelle ' . $h['id'] : 'Keine Haltestelle'),
            'ort'        => $h['ort'],
            'jetzt'      => time(),
            'fussweg'    => $this->ReadPropertyInteger('Fussweg'),
            'vorlauf'    => $this->ReadPropertyInteger('Vorlauf'),
            'ausblenden' => $this->ReadPropertyBoolean('Ausblenden'),
            'abfahrten'  => json_decode($this->GetBuffer('Abfahrten'), true) ?: [],
            'stoerungen' => $this->ReadPropertyBoolean('Stoerungen') ? (json_decode($this->GetBuffer('Stoerungen'), true) ?: []) : [],
            'stand'      => (int)$this->Lese('Datenstand', 0),
            'intervall'  => max(30, $this->ReadPropertyInteger('Intervall')),
            'pause'      => $this->GetStatus() == 104,
            'fehler'     => $this->GetStatus() == 201 ? ($this->ReadAttributeString('Fehler') ?: 'Abruf fehlgeschlagen') : '',
        ];
    }

    private function AktualisiereKachel(): void
    {
        $this->UpdateVisualizationValue(json_encode($this->KachelDaten(), JSON_UNESCAPED_UNICODE));
    }

    // =====================================================================
    // WebHook für IPSView (Web-Control) und Browser
    // =====================================================================

    protected function ProcessHookData()
    {
        if (isset($_GET['json'])) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode($this->KachelDaten(), JSON_UNESCAPED_UNICODE);
            return;
        }

        $hell = ($_GET['theme'] ?? 'dark') === 'light';
        $farben = $hell
            ? ['bg' => '#f6f8fa', 'card' => '#ffffff', 'text' => '#24292f', 'accent' => '#d9480f']
            : ['bg' => '#16191d', 'card' => '#16191d', 'text' => '#d9dee4', 'accent' => '#ff9f1c'];
        $bg = (string)($_GET['bg'] ?? '');
        if ($bg === 'transparent') {
            $farben['bg'] = 'transparent';
            $farben['card'] = $hell ? '#ffffff' : '#16191d';
        } elseif (preg_match('/^[0-9a-fA-F]{3,8}$/', $bg)) {
            $farben['bg'] = $farben['card'] = '#' . $bg;
        }
        $groesse = min(48, max(8, (int)($_GET['size'] ?? 20)));
        $refresh = min(300, max(10, (int)($_GET['refresh'] ?? 20)));
        $kopf = ($_GET['header'] ?? '1') !== '0';
        $icons = ($_GET['icons'] ?? '1') !== '0';

        $stil = '<style>:root{--content-color:' . $farben['text'] . ';--accent-color:' . $farben['accent']
            . ';--card-color:' . $farben['card'] . ';--font-size:' . $groesse . 'px;}'
            . 'html,body{background:' . $farben['bg'] . ';font-family:-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;}'
            . ($kopf ? '' : '.kopf{display:none!important;}')
            . ($icons ? '' : '.fa-light{display:none!important;}')
            . '</style>';
        $html = file_get_contents(__DIR__ . '/module.html');
        $html = str_replace('<script src="/icons.js"></script>', $stil, $html);
        $html = str_replace('/*DATEN*/null', json_encode($this->KachelDaten(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG), $html);

        $abruf = '<script>(function(){let fehl=0;window.vvoAbruf=function(){fetch(' . json_encode($this->HookPfad() . '?json=1')
            . ',{cache:"no-store"}).then(function(r){if(!r.ok)throw 0;return r.json();}).then(function(d){fehl=0;handleMessage(d);})'
            . '.catch(function(){if(++fehl>=Math.ceil(180/' . $refresh . ')&&daten){daten.fehler="Keine Verbindung zu Symcon";render();}});};'
            . 'setInterval(window.vvoAbruf,' . ($refresh * 1000) . ');})();</script>';
        if ($icons) {
            $abruf .= '<script>(function(){const I=' . json_encode($this->IconPfade($html)) . ';'
                . 'function ersetze(){document.querySelectorAll("i.fa-light:not([data-svg])").forEach(function(i){'
                . 'const n=[...i.classList].find(function(c){return c.indexOf("fa-")===0&&c!=="fa-light";});const d=n&&I[n.slice(3)];'
                . 'i.setAttribute("data-svg","1");if(!d)return;'
                . 'i.innerHTML=\'<svg viewBox="0 0 \'+d[0]+\' \'+d[1]+\'" style="height:1em;width:auto;vertical-align:-0.125em;fill:currentColor;overflow:visible"><path d="\'+d[2]+\'"/></svg>\';});}'
                . 'ersetze();new MutationObserver(ersetze).observe(document.body,{childList:true,subtree:true});})();</script>';
        }
        $html = str_replace('</body>', $abruf . "\n</body>", $html);

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo $html;
    }

    /**
     * SVG-Pfade der in module.html verwendeten Font-Awesome-Icons aus der Symcon-icons.js
     * (icons.js selbst läuft außerhalb der Symcon-Visualisierung nicht). Ergebnis im Puffer.
     */
    private function IconPfade(string $html): array
    {
        $cache = json_decode($this->GetBuffer('Icons'), true);
        if (is_array($cache) && count($cache) > 0) {
            return $cache;
        }
        preg_match_all('/fa-([a-z0-9-]+)/', $html, $m);
        $namen = array_diff(array_unique($m[1]), ['light', 'family', 'style']);

        $ch = curl_init('http://127.0.0.1:3777/icons.js');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $js = (string)curl_exec($ch);
        curl_close($ch);

        $pfade = [];
        foreach ($namen as $name) {
            $q = preg_quote($name, '/');
            if (preg_match('/(?:"' . $q . '"|(?<![\w"-])' . $q . '):\[(\d+),(\d+),\[[^\]]*\],"[^"]*","([^"]+)"\]/', $js, $t)) {
                $pfade[$name] = [(int)$t[1], (int)$t[2], $t[3]];
            }
        }
        if (count($pfade) > 0) {
            $this->SetBuffer('Icons', json_encode($pfade));
        }
        return $pfade;
    }

    private function HookPfad(): string
    {
        return '/hook/vvo' . $this->InstanceID;
    }

    private function RegisterHook(): void
    {
        $ids = IPS_GetInstanceListByModuleID(self::WEBHOOK_GUID);
        if (count($ids) === 0) {
            return;
        }
        $hook = $this->HookPfad();
        $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true) ?: [];
        foreach ($hooks as $i => $h) {
            if ($h['Hook'] === $hook) {
                if ($h['TargetID'] === $this->InstanceID) {
                    return;
                }
                $hooks[$i]['TargetID'] = $this->InstanceID;
                IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
                IPS_ApplyChanges($ids[0]);
                return;
            }
        }
        $hooks[] = ['Hook' => $hook, 'TargetID' => $this->InstanceID];
        IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($ids[0]);
    }

    // =====================================================================
    // Hilfsfunktionen
    // =====================================================================

    private function Api(string $pfad, array $daten): ?array
    {
        $ch = curl_init(self::API . $pfad);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($daten, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'IP-Symcon jniVVOAbfahrt',
        ]);
        $antwort = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fehler = curl_error($ch);
        curl_close($ch);

        if ($antwort === false || $http != 200) {
            $this->WriteAttributeString('Fehler', $fehler !== '' ? $fehler : 'HTTP ' . $http);
            $this->SendDebug($pfad, 'Fehler: ' . $this->ReadAttributeString('Fehler'), 0);
            return null;
        }
        $json = json_decode($antwort, true);
        if (!is_array($json)) {
            $this->WriteAttributeString('Fehler', 'Ungültige Antwort');
            return null;
        }
        $this->SendDebug($pfad, mb_substr($antwort, 0, 2000), 0);
        return $json;
    }

    /** "/Date(1795090440000-0000)/" => Unix-Zeit */
    private function ApiDatum($wert): ?int
    {
        if (!is_string($wert) || !preg_match('/Date\((-?\d+)/', $wert, $m)) {
            return null;
        }
        return intdiv((int)$m[1], 1000);
    }

    private function Verkehrsmittel(): array
    {
        $mots = [];
        foreach (self::MOTS as $mot => [$prop, $name]) {
            if ($this->ReadPropertyBoolean($prop)) {
                $mots[] = $mot;
            }
        }
        return $mots;
    }

    private function Liste(string $prop): array
    {
        return array_values(array_filter(array_map(fn($x) => mb_strtolower(trim($x)), explode(',', $this->ReadPropertyString($prop))), fn($x) => $x !== ''));
    }

    private function ImZeitfenster(): bool
    {
        if (!$this->ReadPropertyBoolean('Zeitfenster')) {
            return true;
        }
        if ($this->ReadPropertyBoolean('NurWerktage') && (int)date('N') >= 6) {
            return false;
        }
        $von = $this->Minuten($this->ReadPropertyString('AktivVon'));
        $bis = $this->Minuten($this->ReadPropertyString('AktivBis'));
        $jetzt = (int)date('G') * 60 + (int)date('i');
        return $von <= $bis ? ($jetzt >= $von && $jetzt < $bis) : ($jetzt >= $von || $jetzt < $bis);
    }

    private function Minuten(string $zeit): int
    {
        $z = json_decode($zeit, true);
        return (int)($z['hour'] ?? 0) * 60 + (int)($z['minute'] ?? 0);
    }

    private function StopGueltig(): bool
    {
        return (bool)preg_match('/^\d{5,9}$/', trim($this->ReadPropertyString('StopID')));
    }

    private function Haltestelle(): array
    {
        $h = json_decode($this->ReadAttributeString('Haltestelle'), true) ?: [];
        return ['id' => (string)($h['id'] ?? ''), 'name' => (string)($h['name'] ?? ''), 'ort' => (string)($h['ort'] ?? '')];
    }

    private function Anzeigename(array $h): string
    {
        $name = $h['name'] !== '' ? $h['name'] : 'Haltestelle';
        return ($h['ort'] !== '' && $h['ort'] !== 'Dresden' ? $h['ort'] . ', ' : '') . $name . ' (' . $h['id'] . ')';
    }

    private function InfoText(): string
    {
        $stand = (int)$this->Lese('Datenstand', 0);
        $info = $stand ? 'Letzter Abruf: ' . date('d.m.Y H:i:s', $stand) : 'Noch keine Daten abgerufen';
        $n = count(json_decode($this->GetBuffer('Abfahrten'), true) ?: []);
        if ($stand) {
            $info .= ' – ' . $n . ' Abfahrten';
        }
        $fehler = $this->ReadAttributeString('Fehler');
        if ($fehler !== '') {
            $info .= ' – Fehler: ' . $fehler;
        }
        return $info;
    }

    private function RegisterProfile(string $name, int $typ, string $prefix, string $suffix, float $min, float $max, float $schritt, string $icon): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, $typ);
        }
        IPS_SetVariableProfileText($name, $prefix, $suffix);
        IPS_SetVariableProfileValues($name, $min, $max, $schritt);
        IPS_SetVariableProfileIcon($name, $icon);
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
            case VARIABLETYPE_INTEGER: $wert = (int)$wert; break;
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
}