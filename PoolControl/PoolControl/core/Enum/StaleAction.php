<?php

declare(strict_types=1);

namespace PoolControl\Core\Enum;

/** Verhalten einer Sperrquelle, wenn ihr Sensor stumm wird (Konzept 4). */
enum StaleAction: string
{
    case Loesen = 'loosen'; // Default: Sperre aufheben (Komfort)
    case Halten = 'hold';   // sicherheitskritisch: Sperre bleibt

    /** Tolerante Konstruktion aus Konfigurationswert (Default = Loesen). */
    public static function fromConfig(?string $value): self
    {
        return self::tryFrom($value ?? '') ?? self::Loesen;
    }
}
