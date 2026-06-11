<?php

declare(strict_types=1);

namespace PoolControl\Core\Enum;

/**
 * Betriebszustände der State-Machine (Konzept 3.1).
 *
 * Backed Enum: Der Integer-Wert wird als Symcon-Variablenwert persistiert.
 * Die Prioritäts-Hierarchie (Konzept 3.2) ist als Methode am Enum modelliert –
 * niedrigere Zahl = höhere Priorität.
 */
enum Mode: int
{
    case Idle              = 0;
    case HeizenPv          = 1;
    case HeizenSchnell     = 2;
    case UmwaelzungErzwungen = 3;
    case Urlaub            = 4;
    case Dauerbetrieb      = 5;
    case Frostschutz       = 6;
    case Gesperrt          = 7;
    case Fault             = 8;

    /** Prioritätsrang gemäß Konzept 3.2 (1 = höchste). */
    public function priority(): int
    {
        return match ($this) {
            self::Fault              => 1,
            self::Gesperrt           => 2,
            self::Frostschutz        => 3,
            self::Dauerbetrieb       => 4,
            self::Urlaub             => 5,
            self::UmwaelzungErzwungen => 6,
            self::HeizenPv, self::HeizenSchnell => 7,
            self::Idle               => 8,
        };
    }

    /** Klartext für Logmeldungen (Anzeige erfolgt lokalisiert über Profil/locale.json). */
    public function label(): string
    {
        return match ($this) {
            self::Idle               => 'Leerlauf',
            self::HeizenPv           => 'Heizen (PV)',
            self::HeizenSchnell      => 'Heizen (Schnell)',
            self::UmwaelzungErzwungen => 'Umwälzung erzwungen',
            self::Urlaub             => 'Urlaub',
            self::Dauerbetrieb       => 'Dauerbetrieb',
            self::Frostschutz        => 'Frostschutz',
            self::Gesperrt           => 'Gesperrt',
            self::Fault              => 'FAULT',
        };
    }

    /** Ist dies ein Zustand, in dem langer UP-Lauf erwartbar ist (Watchdog-Plausibilität aussetzen, 7b.5)? */
    public function suspendsPlausibilityWatchdog(): bool
    {
        return match ($this) {
            self::Frostschutz, self::Dauerbetrieb, self::UmwaelzungErzwungen => true,
            default => false,
        };
    }
}
