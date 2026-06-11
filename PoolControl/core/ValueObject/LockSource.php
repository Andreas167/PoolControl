<?php

declare(strict_types=1);

namespace PoolControl\Core\ValueObject;

use PoolControl\Core\Enum\StaleAction;

/** Eine ausgewertete Sperrquelle zum Zeitpunkt des Zyklus (Konzept 4). */
final readonly class LockSource
{
    public function __construct(
        public string      $name,
        public bool        $active,
        public bool        $stale,
        public StaleAction $staleAction,
        public string      $affects, // 'pump' | 'heater' | 'both'
    ) {}

    /** Wirkt diese Quelle aktuell sperrend (unter Berücksichtigung von Stale)? */
    public function isEffective(): bool
    {
        if (!$this->active) {
            return false;
        }
        if ($this->stale && $this->staleAction === StaleAction::Loesen) {
            return false; // stummer Sensor → Sperre lösen (Default)
        }
        return true;
    }

    public function affectsPump(): bool
    {
        return $this->affects === 'pump' || $this->affects === 'both';
    }

    public function affectsHeater(): bool
    {
        return $this->affects === 'heater' || $this->affects === 'both';
    }
}
