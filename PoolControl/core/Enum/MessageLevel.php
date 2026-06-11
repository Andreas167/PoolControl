<?php

declare(strict_types=1);

namespace PoolControl\Core\Enum;

/** Kritikalitätsstufen für Statusmeldungen (Konzept 7a). */
enum MessageLevel: string
{
    case Info     = 'INFO';
    case Warnung  = 'WARNUNG';
    case Kritisch = 'KRITISCH';

    /** Mapping auf IPS-Log-Level (KL_*). Info wird nicht ins IPS-Log geschrieben. */
    public function ipsLogLevel(): ?int
    {
        return match ($this) {
            self::Info     => null,   // nur SendDebug
            self::Warnung  => 2,      // KL_WARNING
            self::Kritisch => 3,      // KL_ERROR
        };
    }
}
