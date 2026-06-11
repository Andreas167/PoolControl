<?php

declare(strict_types=1);

namespace PoolControl\Core\ValueObject;

/**
 * Unveränderliche Konfiguration (Konzept 9). Alle Parameter mit Defaults.
 *
 * @phpstan-type CurvePoint array{hour: float, pct: float}
 */
final readonly class Config
{
    /**
     * @param list<array{hour: float, pct: float}> $curvePoints
     */
    public function __construct(
        // Simulation
        public bool   $simMode = false,
        // Umwälzung (5)
        public float  $volume = 50.0,
        public float  $pumpFlow = 8.0,
        public float  $circulationFactor = 2.0,
        public int    $stagnationThresholdSec = 28800,
        public float  $stagnationMandatoryShare = 0.2,
        public float  $dayEndHour = 22.0,
        public array  $curvePoints = [
            ['hour' => 12.0, 'pct' => 40.0],
            ['hour' => 16.0, 'pct' => 80.0],
            ['hour' => 20.0, 'pct' => 100.0],
        ],
        public bool   $tempAdjustActive = false,
        public string $tempAdjustBasis = 'water', // 'water' | 'heater'
        public float  $tempAdjustRefTemp = 28.0,
        public float  $tempAdjustHeaterBoost = 0.2,
        public float  $pvWindowStartHour = 10.0,
        public float  $pvWindowEndHour = 16.0,
        // Heizung (5a)
        public float  $heatHysteresis = 0.5,
        public int    $phMinOnSec = 600,
        public int    $phMinOffSec = 600,
        public int    $pumpLeadSec = 30,
        public int    $pumpTrailSec = 0,
        public bool   $sensorInPipe = true,
        public int    $sensorWarmupSec = 60,
        // PV (5a.4, W1)
        public float  $pvOnThreshold = 3280.0,
        public float  $pvOffThreshold = 200.0,
        public int    $pvOnDebounceSec = 60,
        public int    $pvOffDebounceSec = 120,
        public float  $pvStabilityReserve = 0.1,
        public float  $pumpPowerW = 300.0,
        public float  $heaterPowerW = 2500.0,
        // Frostschutz (6)
        public float  $frostUpThreshold = 3.0,
        public float  $frostPhThreshold = -5.0,
        public float  $frostHysteresis = 1.0,
        // Fault / Watchdog (7b)
        public float  $faultBucketThreshold = 5.0,
        public float  $faultBucketLeakRatePerMin = 0.0833, // 5/60
        public float  $faultBucketRecoverStep = 1.0,
        public int    $verifyDeadlineSec = 5,
        public int    $maxRetries = 3,
        public int    $watchdogPumpPlausibilitySec = 64800,
        public int    $watchdogPumpAbsoluteSec = 86400,
        public int    $watchdogHeaterPlausibilitySec = 43200,
        public int    $watchdogHeaterAbsoluteSec = 64800,
        public int    $faultEmergencyRunSec = 900,
        public int    $faultEmergencyIntervalSec = 21600,
        public int    $quittRateLimitSec = 60,
        // Energie (8)
        public float  $electricityPricePerKwh = 0.30,
    ) {}

    /** Tagesziel in Sekunden (Konzept 5.2). Reine Berechnung. */
    public function dailyTargetSeconds(?float $poolTemp, bool $heaterOn, bool $ignoreHeaterBasis = false): int
    {
        if ($this->pumpFlow <= 0.0) {
            return 0;
        }
        $hours = ($this->volume * $this->circulationFactor) / $this->pumpFlow;
        $factor = 1.0;

        if ($this->tempAdjustActive) {
            if ($this->tempAdjustBasis === 'water' && $poolTemp !== null && $this->tempAdjustRefTemp > 0.0) {
                $factor = max(1.0, $poolTemp / $this->tempAdjustRefTemp);
            } elseif ($this->tempAdjustBasis === 'heater' && !$ignoreHeaterBasis && $heaterOn) {
                $factor = 1.0 + $this->tempAdjustHeaterBoost;
            }
        }

        return (int) round($hours * $factor * 3600);
    }
}
