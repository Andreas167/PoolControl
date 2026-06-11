# Changelog – Pool Control (Team 3)

Format nach [Keep a Changelog](https://keepachangelog.com/de/1.0.0/),
Versionierung nach [Semantic Versioning](https://semver.org/lang/de/).

---

## [1.0.2] – 2026-06-10

### Behoben (vertiefter Selbst-Review, Befunde C9)

- **C9a:** `faultBucketRecoverStep` ist jetzt konfigurierbar (Property + Formularfeld).
  Zuvor nutzte `recoverSuccess()` immer den fest verdrahteten Default 1.0, unabhängig
  von der Konfiguration.
- **C9b:** `tempAdjustHeaterBoost` ist jetzt konfigurierbar (Property + Formularfeld).
  Zuvor war der Heizungs-Boost der temperaturabhängigen Umwälzanpassung nicht
  einstellbar und nutzte still 0.2.

### Geprüft ohne Befund (Robustheit bestätigt)

Uhr-Rückstellung/NTP-Sprung (Datum in Zukunft), negatives Δt, Soll-Kurve mit einem
oder null Stützpunkten, Total-Zähler beim Tagesreset, Reconciliation-Deadline-Logik,
Reentrancy/Semaphore-Verschachtelung, IPS-API-Signaturen (MessageSink,
RegisterAttributeBoolean, SelectInstance).

### Tests

- 41 Unit-Tests grün, Property/Form-Konsistenz 69:69.

---

## [1.0.1] – 2026-06-10

### Behoben (Review-Befunde Team A & Team B)

- **A4 (kritisch):** `Message`-Klasse in eigene Datei `core/ValueObject/Message.php`
  extrahiert – verhinderte sonst einen Fatal Error beim ersten meldungserzeugenden
  Zyklus (Autoload fand die Klasse nicht).
- **A2 (kritisch):** Frostschutz-Hysterese wirksam – `frostActive` wird jetzt im State
  persistiert (`computeFrostActive()`), sodass der Frostschutz bis Schwelle + Hysterese
  aktiv bleibt statt hart an der Schwelle zu pendeln.
- **A3 (hoch):** Plausibilitäts-Watchdog berücksichtigt jetzt den Vorzyklus-Modus
  (`lastMode` im State). Lange, legitime erzwungene Umwälzläufe lösen keinen
  unberechtigten FAULT mehr aus; der absolute Watchdog bleibt unberührt.
- **A6 (mittel):** PV-Debounce ist als erster Schritt in `compute()` integriert – der
  reine Kern ist ohne externe Vorbearbeitung vollständig.
- **B2 (kritisch):** Schaltfehler-Pfad in `switchActor()` erhöht den Fehler-Bucket und
  prüft die FAULT-Schwelle korrekt (kein paradoxes `acknowledgeFault` mehr). Ein hart
  defekter Aktor löst jetzt zuverlässig FAULT aus.
- **B7 (kritisch):** Dreistufige Schaltziel-Auflösung vollständig – `TargetMode`
  (Variable / Instanz-Aktion direkt) mit `TargetInstanceId`/`TargetIdent` in `module.php`
  und `form.json`. Auch Aktoren ohne Statusvariable sind jetzt konfigurierbar.
- **B1 (hoch):** W2-Re-Run nutzt ein eigenes atomares Attribut (`RerunPending`) statt
  eines State-Roundtrips – das Dirty-Flag kann bei Semaphore-Konflikt nicht mehr
  verloren gehen. Das ungenutzte `rerun`-State-Feld wurde entfernt.
- **B3 (mittel-hoch):** Bei manueller Übernahme einer Vor-Ort-Schaltung wird auch
  `pumpStartTs`/`heaterStartTs` gesetzt, damit der Laufzeit-Watchdog eine real
  laufende Pumpe/Heizung erfassen kann.
- **B8 (mittel):** Meldungs-Entprellung jetzt pro Meldungstyp (Attribut-Map mit
  5-Minuten-Fenster) statt nur Vergleich mit der letzten Meldung (Konzept N4).

### Hinweis

- **B6:** Die GUIDs in `library.json`/`module.json` sind weiterhin Platzhalter und vor
  Veröffentlichung durch eindeutige zu ersetzen.

### Tests

- 41 Unit-Tests (vorher 36), 5 neue Regressionstests für A2, A3, A6.
- Alle mit PHP 8.3 grün; 10-Zyklen-Integrationstest bestanden.

---

## [1.0.0] – 2026-06-10

Erste Umsetzung des Modulkonzepts v1.2 in einer modernen PHP-8.3-Architektur.

### Architektur

- **Reiner Entscheidungskern** (`core/DecisionEngine.php`) ohne IPS-Abhängigkeiten,
  vollständig per Unit-Test verifizierbar. Strikte Trennung nach EVA-Prinzip:
  `readInputs` (Shell) → `compute` (reiner Kern) → `applyDecision` (Shell).
- **Backed Enums mit Verhalten** statt Integer-Konstanten:
  `Mode` (inkl. `priority()`, `label()`, `suspendsPlausibilityWatchdog()`),
  `MessageLevel`, `StatusCode`, `StaleAction`.
- **Immutable Value Objects** (`final readonly`): `Inputs`, `Config`, `LockSource`,
  `Decision`, `Message` – Constructor Property Promotion, `?float`/`?bool` für
  „Wert vorhanden/gültig" statt separater Valid-Flags.
- **Immutabler State** (`PoolState`) mit `with*()`-Wither-Methoden; typsichere
  `fromArray()`/`toArray()`-Serialisierung für das IPS-Attribut.
- **Match-basierte Modus- und Aktor-Auflösung** statt verschachtelter If-Kaskaden.

### Funktionen (Konzept v1.2)

- State-Machine mit 9 Zuständen und Prioritätshierarchie (Enum-modelliert).
- Hardware-Abstraktion über konfigurierbare Variablen/Rückmeldungen (Open- und Closed-Loop).
- PV-Optimierung mit Hysterese, getrenntem Ein-/Ausschalt-Debounce und
  Stabilitätsbedingung W1 (Konfig-Validierung Code 207).
- Heizregelung mit Hysterese, Takt-Schutz, UP-Vor-/Nachlauf, Rohrfühler-Mess-Vorlauf.
- Umwälz-Tagesziel aus Volumen/Förderleistung, Garantie-Soll-Kurve (interpoliert),
  Spätester-Start-Schutz, Stagnationsschranke mit eingefrorenem Lauf-Ziel (W4).
- Frostschutz zweistufig (UP/PH) mit Hysterese, fail-safe ohne Sensor.
- Generische Sperrquellen mit Stale-Verhalten (lösen/halten).
- Fault-Management: Leaky-Bucket, zweistufiger Watchdog (Plausibilität aussetzbar,
  absolut immer), gedrosselter Notlauf unter Beachtung von UP-Sperren (W12),
  Quittierung mit Rate-Limit (W9).
- Energie-/Kostentracking je Aktor (gemessen oder Festwert), datumsbasierter
  DST-fester Tagesreset.
- Simulationsbetrieb (Dry-Run, W6), Anlauf-Stagger über Timer-Offset (M6),
  externe Modus-Sync-Eingänge (W5).

### Tests & Qualität

- 36 Unit-Tests gegen den reinen Kern, mit PHP 8.3 verifiziert grün.
- 10-Zyklen-Integrationstest (State-Roundtrip) bestanden.
- CI-Pipeline: Syntax, statische Analyse, Tests, JSON-Schema.

### Hinweise

- Die GUIDs in `library.json` und `PoolControl/module.json` sind Platzhalter und
  vor dem Produktiveinsatz durch eigene zu ersetzen.
- Inbetriebnahme im Simulationsbetrieb empfohlen.
