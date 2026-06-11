# PoolControl – IP-Symcon Modul (Team 3)

Hardware-unabhängige Pool-Steuerung mit PV-Optimierung, Temperaturregelung,
Interlocks und Fault-Management. Umsetzung des Modulkonzepts v1.2 mit einem
bewusst modernen PHP-8.3-Kern.

## Architektur

Dieses Modul trennt strikt zwischen reinem Entscheidungskern und IPS-Anbindung
(EVA-Prinzip, Konzept 1a.1):

```
PoolControl/
├── core/                      ← reiner Kern, KEINE IPS-Abhängigkeit, PHP 8.3
│   ├── Enum/                  ← Mode, MessageLevel, StatusCode, StaleAction
│   │                            (backed enums mit Verhaltensmethoden)
│   ├── ValueObject/           ← Inputs, Config, LockSource, Decision, Message
│   │                            (final readonly, Constructor Promotion)
│   ├── State/PoolState.php    ← immutabler State mit with()-Wither
│   ├── DecisionEngine.php     ← reine compute()-Pipeline
│   ├── ConfigValidator.php    ← Konfig-Prüfung (Konzept 7c.4)
│   └── autoload.php           ← PSR-4-Autoloader (kein Composer nötig)
├── module.php                 ← dünne IPS-Shell (I/O + Kern-Aufruf)
├── module.json
└── tests/                     ← 36 Unit-Tests, offline lauffähig
```

### Moderne Ansätze

| Technik | Nutzen |
|---|---|
| **PHP 8.1 Enums** (`Mode`, `StatusCode` …) | Typsicherheit statt magischer Integer-Konstanten; Verhalten am Enum (z.B. `Mode::priority()`) |
| **`final readonly` Value Objects** | echte Immutabilität, keine versehentlichen Seiteneffekte |
| **Immutabler State** mit `with()` | jede Pipeline-Stufe gibt neuen State zurück → Lost-Update strukturell ausgeschlossen |
| **`match`-Ausdrücke** | erschöpfende, lesbare Fallunterscheidung der State-Machine |
| **`?float`/`?bool` statt Wert+Valid-Flag** | `null` = „nicht nutzbar" ist eindeutig; fail-safe by design |
| **Named Arguments** | selbstdokumentierende Aufrufe bei vielen Parametern |
| **Reiner Kern** | 36 Unit-Tests laufen ohne Symcon-Kernel/Hardware |

## Voraussetzungen

- IP-Symcon ≥ 7.0 (wegen PHP-8.3-Sprachfeatures)
- Mindestens eine schaltbare Variable für die Umwälzpumpe

## Installation

1. Module Control → Repository-URL hinzufügen
2. Modul „PoolControl" installieren
3. Instanz anlegen, Aktoren und Sensoren verknüpfen

## Inbetriebnahme

Im Konfigurationsformular **Simulationsbetrieb** aktivieren: Das Modul durchläuft
die komplette Logik, schaltet aber keine Aktoren, sondern loggt `[SIM]`-Meldungen.
So lässt sich die Konfiguration gefahrlos über Tage verifizieren.

## Tests

```bash
php PoolControl/tests/run.php      # eigenständiger Runner (ohne Composer)
# oder in CI:
vendor/bin/phpunit PoolControl/tests
```

## Fehlercodes

Siehe `Enum/StatusCode.php` – u.a. 207 (PV-Schwellenabstand zu klein, Takt-Gefahr),
204 (Frost-Fehlkonfiguration), 205 (Umwälzung), 209 (Tagesende vor Soll-Kurve).

## Lizenz

MIT
