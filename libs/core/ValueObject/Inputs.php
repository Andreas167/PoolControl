<?php

declare(strict_types=1);

namespace PoolControl\Core\ValueObject;

/**
 * Unveränderlicher Schnappschuss aller Eingänge eines Zyklus (Konzept 1a.1).
 *
 * Optionale Sensoren werden als ?float/?bool modelliert: null = nicht vorhanden
 * oder ungültig/stale. Das ersetzt die fehleranfällige paarweise
 * Wert+Valid-Flag-Modellierung – ein null-Wert ist eindeutig "nicht nutzbar".
 */
final readonly class Inputs
{
    /**
     * @param list<LockSource> $lockSources
     */
    public function __construct(
        public int     $now,
        public int     $dtSeconds,
        public ?float   $poolTemp        = null,
        public ?float   $poolSetpoint    = null,
        public ?float   $outsideTemp     = null,
        public ?float   $pvPower         = null,
        public ?bool    $energyCheap     = null,
        public ?bool    $pumpFeedback    = null,
        public ?bool    $heaterFeedback  = null,
        public bool     $quickMode       = false,
        public bool     $holidayMode     = false,
        public bool     $continuousMode  = false,
        public array    $lockSources     = [],
        public ?float   $pumpPower       = null,
        public ?float   $heaterPower     = null,
    ) {}

    public function hasValidPoolTemp(): bool
    {
        return $this->poolTemp !== null && $this->poolSetpoint !== null;
    }

    /** Lokale Dezimalstunde (DST-fest, da auf now basierend). */
    public function decimalHour(): float
    {
        return (int) date('H', $this->now) + ((int) date('i', $this->now) / 60.0);
    }

    public function dateKey(): int
    {
        return (int) date('Ymd', $this->now);
    }
}
