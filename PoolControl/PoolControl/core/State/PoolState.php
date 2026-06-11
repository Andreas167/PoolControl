<?php

declare(strict_types=1);

namespace PoolControl\Core\State;

/**
 * Persistierter interner Zustand (Konzept 7c.5 – als Attribut gespeichert).
 *
 * MODERNER ANSATZ: Der State ist effektiv unveränderlich. Statt Felder zu
 * mutieren, erzeugen `with*()`-Methoden eine modifizierte Kopie. Das macht
 * den Datenfluss im reinen Kern nachvollziehbar und schließt versehentliche
 * Seiteneffekte (das klassische Lost-Update-Problem) strukturell aus.
 *
 * Die Properties sind nicht `readonly`, weil `clone`+Zuweisung in den Withern
 * sonst umständlich würde; die Kapselung über ausschließlich `with*()` und
 * `fromArray()`/`toArray()` stellt die Unveränderlichkeit nach außen sicher.
 */
final class PoolState
{
    public function __construct(
        public int   $lastResetDate = 0,
        public int   $lastCycleTs = 0,
        public int   $lastMode = 0, // A3: Modus des Vorzyklus (für Watchdog-Aussetzung)
        // Pumpe
        public bool  $pumpOn = false,
        public int   $pumpStartTs = 0,
        public int   $pumpPauseSinceTs = 0,
        public int   $pumpTrailUntilTs = 0,
        public int   $lastCmdPumpTs = 0,
        // Heizung
        public bool  $heaterOn = false,
        public int   $heaterStartTs = 0,
        public int   $heaterOffSinceTs = 0,
        public int   $lastCmdHeaterTs = 0,
        // Laufzeit & Energie
        public int   $pumpRuntimeTodaySec = 0,
        public int   $pumpRuntimeTotalSec = 0,
        public int   $heaterRuntimeTodaySec = 0,
        public int   $heaterRuntimeTotalSec = 0,
        public int   $pumpStartsToday = 0,
        public int   $heaterStartsToday = 0,
        public float $kwhPumpToday = 0.0,
        public float $kwhPumpTotal = 0.0,
        public float $kwhHeaterToday = 0.0,
        public float $kwhHeaterTotal = 0.0,
        public float $costPumpToday = 0.0,
        public float $costPumpTotal = 0.0,
        public float $costHeaterToday = 0.0,
        public float $costHeaterTotal = 0.0,
        public int   $dailyTargetSec = 0,
        // Stagnation (W4)
        public int   $stagnationFrozenTargetSec = 0,
        // PV-Debounce (5a.4)
        public bool  $pvActive = false,
        public int   $pvOnDebounceStartTs = 0,
        public int   $pvOffDebounceStartTs = 0,
        // Frost
        public bool  $frostActive = false,
        // Fault (7b)
        public bool  $faultActive = false,
        public float $faultBucket = 0.0,
        public int   $faultEmergencyStartTs = 0,
        public int   $faultEmergencyLastTs = 0,
        public int   $retryCountPump = 0,
        public int   $retryCountHeater = 0,
        public int   $lastQuittTs = 0,
    ) {}

    /**
     * Erzeugt eine Kopie mit überschriebenen Feldern (Wither-Pattern).
     * @param array<string,mixed> $changes
     */
    public function with(array $changes): self
    {
        $clone = clone $this;
        foreach ($changes as $key => $value) {
            if (property_exists($clone, $key)) {
                $clone->$key = $value;
            }
        }
        return $clone;
    }

    /**
     * Typsichere Deserialisierung aus persistiertem JSON-Array.
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $state = new self();
        foreach ($data as $key => $value) {
            if (!property_exists($state, $key)) {
                continue;
            }
            $ref = $state->$key;
            $state->$key = match (true) {
                is_int($ref)   => (int) $value,
                is_float($ref) => (float) $value,
                is_bool($ref)  => (bool) $value,
                default        => $value,
            };
        }
        return $state;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
