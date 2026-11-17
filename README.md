# jniModules

Eigene IP-Symcon-Module von jnicke.

| Modul | Beschreibung |
|---|---|
| [jniFeiertage](#jnifeiertage) | Zeigt an, ob heute ein Feiertag ist |
| [jniEnergyForecast](#jnienergyforecast) | Verbrauchsprognose mit Kostenrechnung und Diagrammen |

---

## jniFeiertage
abgekupfert von Dave zum 1. test für mich
Dieses Modul zeigt an, ob es sich heute um einen Feiertag handelt.
Im Konfigurationsformular muss das Bundesland ausgewählt werden.

**Befehlsreferenz:**

**IQLFT_Update** dient zum Aktualisieren der Instanz
```php
<?php
IQLFT_Update( interger $InstanceID );
?>
```
**ChangeLog**

06.11.2015

-Neu: Feiertage für Osterreich hinzugefügt

---

## jniEnergyForecast

Verbrauchsprognose und Kostenrechnung für einen archivierten Zähler (Strom, Gas, Wasser …)
mit Diagrammen für IPSView und WebFront.

### Herkunft / Dank

Die Idee und der Funktionsumfang gehen auf das Projekt **RS Energy Forecast** von
**Raketenschnecke** zurück (<https://www.raketenschnecke.net/rs-projekte/rs-ips-energy-forecast/>),
das hier seit 2016 lief: Tagesprognose, Forecast für die Abrechnungsperiode, Kostenmanager
mit Abschlagsvergleich und HighCharts-Diagramme. Vielen Dank an Raketenschnecke für die Vorlage!

jniEnergyForecast ist eine **eigenständige Neuimplementierung** als Symcon-Modul – es enthält
keinen Code aus dem Original. Übernommen sind Idee und Konzept, Berechnung und Darstellung
sind neu (u. a. saisonale Prognose aus dem Vorjahresverlauf, IPSView-taugliche Variablen,
Chart.js statt HighCharts).

### Voraussetzungen

- Zählervariable (z. B. Gesamtverbrauch in kWh oder m³), die im Archiv mit Aggregationstyp **Zähler** geloggt wird
- mindestens 14 Tage Daten; für die saisonale Prognose idealerweise ein volles Vorjahr
- optional: archivierte Außentemperatur für die witterungsbereinigte Prognose (Heizung/Gas)

### Prognose-Logik

| Zeitraum | Berechnung |
|---|---|
| Heute | Ist bis jetzt + Stundenprofil des gleichen Wochentags (letzte n Wochen) für den Rest des Tages |
| Folgetage | 7-Tage-Mittel um den gleichen Kalendertag 1–3 Jahre zurück × Niveaufaktor (letzte 28 Tage ggü. Vergleichsjahr, begrenzt 0,75–1,25) × Wochentagsfaktor |
| ohne Historie | Wochentags-Median der letzten n Wochen |
| Plan | vertraglicher Jahresverbrauch, saisonal verteilt nach dem Verlauf der Vorperiode |

### Witterungsbereinigte Prognose (Heizgradtage)

Sobald unter *Witterung / Heizgradtage* eine archivierte Außentemperatur gewählt ist:

- **Heizgradtage** je Tag: HGT = max(0, Heizgrenze − Tagesmittel der Außentemperatur), Heizgrenze Standard 15 °C
- **Modell**: Tagesverbrauch = Grundlast + Faktor × HGT, lineare Regression über die letzten 365 Tage
  (Heiztage mit HGT ≥ 1 und Verbrauch > 0, Ausreißer > 2,5 σ werden einmal entfernt, mind. 20 Heiztage)
- optional **getrennt nach Werktag und Wochenende/Feiertag** (gesetzliche Feiertage des gewählten Bundeslands)
- Tage ohne Heizbedarf (HGT = 0): Median der Sommertage der letzten 365 Tage (Warmwasser bzw. Heizung aus)
- **Temperatur der Zukunft**: die nächsten 7 Tage aus [Open-Meteo](https://open-meteo.com/) (Koordinaten aus der
  Symcon-Standort-Instanz oder der Konfiguration, Abruf höchstens stündlich), danach Mittel der HGT um den gleichen
  Kalendertag der Vorjahre (±3 Tage, bis 3 Jahre zurück)
- Heute: Tagessumme aus dem Modell, verteilt nach dem Stundenprofil
- *Temperatur gültig ab*: ältere Temperaturwerte werden ignoriert (z. B. wenn der Sensor anfangs nicht draußen hing)
- ohne ausreichendes Modell wird automatisch die Standard-Prognose verwendet

Kennzahlen:

- **Witterungsbereinigt ggü. Vorjahr**: Periode bis gestern ggü. gleichem Zeitraum im Vorjahr; der Heizanteil des
  Vorjahres (Verbrauch − Grundlast) wird mit dem Verhältnis der Heizgradtage auf das Wetter dieses Jahres umgerechnet.
  Gezählt werden nur Tage mit Verbrauch und Temperatur in beiden Jahren.
  Ein negativer Achsenabschnitt im Modell ist normal, wenn die Heizung an milden Tagen ganz aus bleibt (effektive Heizgrenze liegt dann unter der eingestellten).
- **Verbrauch je Heizgradtag**: Modell-Faktor (365 Tage) und Ist der letzten 30 Tage (ohne Grundlast), bei Gas in kWh/Kd
- **Modellgüte R²**, Heizgradtage gestern / Periode bisher / Vorjahr

### Gas

Zählerart *Gas (m³ → kWh)*:

- kWh = m³ × **Brennwert** × **Zustandszahl** (beide von der Gasrechnung)
- Arbeitspreis in ct/kWh und vertraglicher Jahresverbrauch in kWh – wie auf der Rechnung
- Hauptvariablen in m³, zusätzlich *Heute/Monat/Periode/Folgeperiode … (kWh)*; Diagramme zeigen kWh im Tooltip
- statt „Grundlast in W“ gibt es **Grundlast je Tag** (Median der Tage ohne Heizbedarf = Warmwasser/Sommer; ohne Witterung Ø der 3 niedrigsten Tage der letzten 28 Tage)

### Variablen

Alle Werte sind einfache Float-Variablen mit klassischen Variablenprofilen
(`EFC.Energy.<Einheit>`, `EFC.Euro`, `EFC.Percent`, `EFC.Deviation`, `EFC.Watt`, `EFC.HDD`, `EFC.PerHDD.<Einheit>`, `EFC.R2`)
und damit direkt in IPSView nutzbar:

- Heute Ist / Prognose / Prognose in % vom Ø
- Woche Ist, Monat Ist / Prognose, Periode Ist / Prognose / Abweichung zum Plan
- Periode Kosten, Abschläge + Buchungen, Saldo (+ Guthaben / − Nachzahlung)
- Folgeperiode Verbrauch, Kosten, empfohlener Abschlag
- Grundlast (Strom: Ø der niedrigsten Stunde der letzten 7 Tage in W; Gas: je Tag)
- Gas: kWh-Varianten der wichtigsten Werte
- Witterung: Heizgradtage gestern / Periode / Vorjahr, witterungsbereinigt ggü. Vorjahr, je Heizgradtag (Modell / 30 Tage), R²
- optional 12 Monatsvariablen (Ist + Prognose), benennen sich mit der Periode selbst um

Optional werden *Heute Prognose*, *Periode Ist*, *Periode Prognose*, *Periode Saldo*, *Grundlast* sowie
*Heizgradtage gestern*, *witterungsbereinigt* und *je Heizgradtag* archiviert (Verlaufsdiagramme).

### Zählerwechsel

Frühere Zähler werden mit Ausbautag und eigenem Faktor eingetragen. Bis einschließlich Ausbautag zählt der alte
Zähler, ab Ausbautag der Nachfolger. Optional wird die Lücke zwischen letztem Wert des alten und erstem Wert des
neuen Zählers geschätzt und gleichmäßig verteilt.

### Diagramme

Das Modul stellt die Diagramme über einen WebHook bereit (z. B. für das Web-Control in IPSView):

```
http://<Symcon-IP>:3777/hook/efc<InstanzID>                 Dashboard (Kennzahlen + Diagramme)
http://<Symcon-IP>:3777/hook/efc<InstanzID>?chart=stats     Tabelle Betrachtungszeitraum: Heute/Woche/Monat/Periode – Ist, Prognose, Plan, Vergleich
                                                ?chart=costs     Tabelle Vertragskosten: je Monat Verbrauch, Kosten, Abschlag, Saldo; Folgeperiode
http://<Symcon-IP>:3777/hook/efc<InstanzID>?chart=today     Heute: Stundenwerte, Ø Wochentag, gestern, Außentemperatur
                                                ?chart=week      Woche: Ist/Prognose, Spanne + Ø der letzten n Wochen, Ø Temperatur
                                                ?chart=months    Monate: Ist/Prognose, Vorjahr, Plan, Heizgradtage, Kosten im Tooltip
                                                ?chart=period    Periode kumuliert inkl. Δ zur Vorperiode
                                                ?chart=model     Heizgradtag-Modell: Tagesverbrauch über HGT mit Regressionsgeraden
```

Optionen: `&theme=dark|light`, `&bg=<hex>|transparent`, `&refresh=<Sekunden>` (Standard 300), `&header=0`

Die Diagramme nutzen [Chart.js](https://www.chartjs.org/) (MIT-Lizenz), lokal unter `jniEnergyForecast/libs/` abgelegt – es wird kein Internet benötigt.

### Befehlsreferenz

```php
EFC_Update(int $InstanceID);            // alle Werte sofort neu berechnen
EFC_GetModelInfo(int $InstanceID);      // Heizgradtag-Modell als Text (auch Button im Formular)
```

### ChangeLog

17.11.2026
- Neu: jniEnergyForecast als Nachfolger von RS Energy Forecast (Raketenschnecke)
- Zählerwechsel (alter/neuer Zähler mit Faktor und Lückenschätzung)
- Gas: Umrechnung m³ → kWh (Brennwert × Zustandszahl), Preise in kWh, Grundlast je Tag
- Witterungsbereinigte Prognose mit Heizgradtagen (Regression, optional Werktag/Wochenende+Feiertag, Open-Meteo)
- Kennzahlen: witterungsbereinigt ggü. Vorjahr, Verbrauch je Heizgradtag, R²
- Diagramme: Außentemperatur, Heizgradtage, neues Modell-Diagramm
- Tabellen „Betrachtungszeitraum“ und „Vertragskosten“ (wie im RS-Original, neu gestaltet), Planverteilung über ±3 Tage geglättet
