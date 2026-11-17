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

- Zählervariable (z. B. Gesamtverbrauch in kWh), die im Archiv mit Aggregationstyp **Zähler** geloggt wird
- mindestens 14 Tage Daten; für die saisonale Prognose idealerweise ein volles Vorjahr

### Prognose-Logik

| Zeitraum | Berechnung |
|---|---|
| Heute | Ist bis jetzt + Stundenprofil des gleichen Wochentags (letzte n Wochen) für den Rest des Tages |
| Folgetage | 7-Tage-Mittel um den gleichen Kalendertag 1–3 Jahre zurück × Niveaufaktor (letzte 28 Tage ggü. Vergleichsjahr, begrenzt 0,75–1,25) × Wochentagsfaktor |
| ohne Historie | Wochentags-Median der letzten n Wochen |
| Plan | vertraglicher Jahresverbrauch, saisonal verteilt nach dem Verlauf der Vorperiode |

### Variablen

Alle Werte sind einfache Float-Variablen mit klassischen Variablenprofilen
(`EFC.Energy.<Einheit>`, `EFC.Euro`, `EFC.Percent`, `EFC.Deviation`, `EFC.Watt`) und damit direkt in IPSView nutzbar:

- Heute Ist / Prognose / Prognose in % vom Ø
- Woche Ist, Monat Ist / Prognose, Periode Ist / Prognose / Abweichung zum Plan
- Periode Kosten, Abschläge + Buchungen, Saldo (+ Guthaben / − Nachzahlung)
- Folgeperiode Verbrauch, Kosten, empfohlener Abschlag
- Grundlast (Ø der niedrigsten Stunde der letzten 7 Tage)
- optional 12 Monatsvariablen (Ist + Prognose), benennen sich mit der Periode selbst um

Optional werden *Heute Prognose*, *Periode Ist*, *Periode Prognose*, *Periode Saldo* und *Grundlast* archiviert (Verlaufsdiagramme).

### Diagramme

Das Modul stellt die Diagramme über einen WebHook bereit (z. B. für das Web-Control in IPSView):

```
http://<Symcon-IP>:3777/hook/efc<InstanzID>                 Dashboard (Kennzahlen + 4 Diagramme)
http://<Symcon-IP>:3777/hook/efc<InstanzID>?chart=today     Heute: Stundenwerte, Ø Wochentag, gestern
                                                ?chart=week      Woche: Ist/Prognose, Spanne + Ø der letzten n Wochen
                                                ?chart=months    Monate: Ist/Prognose, Vorjahr, Plan, Kosten im Tooltip
                                                ?chart=period    Periode kumuliert inkl. Δ zur Vorperiode
```

Optionen: `&theme=dark|light`, `&bg=<hex>|transparent`, `&refresh=<Sekunden>` (Standard 300), `&header=0`

Die Diagramme nutzen [Chart.js](https://www.chartjs.org/) (MIT-Lizenz), lokal unter `jniEnergyForecast/libs/` abgelegt – es wird kein Internet benötigt.

### Befehlsreferenz

```php
EFC_Update(int $InstanceID); // alle Werte sofort neu berechnen
```

### ChangeLog

17.11.2026
- Neu: jniEnergyForecast als Nachfolger von RS Energy Forecast (Raketenschnecke)
