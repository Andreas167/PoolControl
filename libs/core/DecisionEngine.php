<?php

declare(strict_types=1);

namespace PoolControl\Core;

use PoolControl\Core\Enum\MessageLevel;
use PoolControl\Core\Enum\Mode;
use PoolControl\Core\State\PoolState;
use PoolControl\Core\ValueObject\Config;
use PoolControl\Core\ValueObject\Decision;
use PoolControl\Core\ValueObject\Inputs;
use PoolControl\Core\ValueObject\Message;

/**
 * Reiner Entscheidungskern (Konzept 1a.1).
 *
 * KEINE IPS-Abhängigkeit, keine Seiteneffekte: compute() nimmt Inputs + State + Config
 * und gibt eine Decision (inkl. neuem State) zurück. Der State wird unveränderlich
 * durch die Verarbeitungs-Pipeline gereicht – jede Stufe gibt einen neuen State zurück.
 *
 * Pipeline:
 *   accumulate → dayReset → watchdog → bucketLeak → resolveMode → resolveActors → transitions
 */
final class DecisionEngine
{
    /** @var list<Message> */
    private array $messages = [];

    public function compute(Inputs $in, PoolState $state, Config $cfg): Decision
    {
        $this->messages = [];

        // Δt plausibilisieren: Lücken > 1h (Neustart/Standby) nicht als Laufzeit werten
        $dt = $in->dtSeconds > 0 ? $in->dtSeconds : max(0, $in->now - $state->lastCycleTs);
        if ($dt > 3600) {
            $dt = 0;
        }

        // A6-Fix: PV-Debounce als erster Pipeline-Schritt – der Kern ist damit ohne
        // externe Vorbearbeitung vollständig (compute() allein liefert korrekte pvActive).
        $state = $this->updatePvDebounce($state, $in, $cfg);

        // 1. Laufzeit & Energie akkumulieren (auf Basis des bisherigen Schaltzustands)
        $state = $this->accumulate($state, $in, $cfg, $dt);

        // 2. Tagesreset (datumsbasiert, holt verpasste Resets nach – 5b.0)
        if ($state->lastResetDate !== $in->dateKey()) {
            $state = $this->dayReset($state, $in, $cfg);
            $this->addMessage(MessageLevel::Info, 'Tagesreset');
        }

        // 3. Watchdog bewerten (7b.5) – kann FAULT setzen
        $state = $this->evaluateWatchdog($state, $in, $cfg);

        // 4. Leaky-Bucket Leak (7b.4)
        $state = $state->with([
            'faultBucket' => max(0.0, $state->faultBucket - $cfg->faultBucketLeakRatePerMin * ($dt / 60.0)),
            'lastCycleTs' => $in->now,
        ]);

        // 5. FAULT hat Vorrang vor allem
        if ($state->faultActive) {
            return $this->faultDecision($state, $in, $cfg);
        }

        // 5b. Frost-Hysterese-Zustand fortschreiben (A2: muss persistiert werden,
        //     sonst greift die Verlassens-Hysterese nie)
        $state = $state->with(['frostActive' => $this->computeFrostActive($in, $state, $cfg)]);

        // 6. Modus über Prioritätshierarchie auflösen (3.2)
        $mode  = $this->resolveMode($in, $state, $cfg);

        // 7. Aktor-Soll bestimmen (3.5/3.6)
        [$pumpOn, $heaterOn, $state] = $this->resolveActors($mode, $in, $state, $cfg);

        // 8. Schalt-Transitions in den State schreiben (Timestamps, Zähler, Stagnationsziel)
        $state = $this->applyTransitions($state, $in, $cfg, $pumpOn, $heaterOn);

        // Modus für den nächsten Zyklus merken (A3: Watchdog-Aussetzung)
        $state = $state->with(['lastMode' => $mode->value]);

        return new Decision(
            mode:         $mode,
            pumpOn:       $pumpOn,
            heaterOn:     $heaterOn,
            nextState:    $state,
            messages:     $this->messages,
            simMode:      $cfg->simMode,
            poolTemp:     $in->poolTemp,
            poolSetpoint: $in->poolSetpoint,
        );
    }

    // ── 1. Akkumulation ──────────────────────────────────────────────────────

    private function accumulate(PoolState $s, Inputs $in, Config $cfg, int $dt): PoolState
    {
        if ($dt <= 0) {
            return $s;
        }
        $dtH = $dt / 3600.0;
        $changes = [];

        if ($s->pumpOn) {
            $w = $in->pumpPower ?? $cfg->pumpPowerW;
            $kwh = ($w / 1000.0) * $dtH;
            $changes += [
                'pumpRuntimeTodaySec' => $s->pumpRuntimeTodaySec + $dt,
                'pumpRuntimeTotalSec' => $s->pumpRuntimeTotalSec + $dt,
                'kwhPumpToday'  => $s->kwhPumpToday + $kwh,
                'kwhPumpTotal'  => $s->kwhPumpTotal + $kwh,
                'costPumpToday' => $s->costPumpToday + $kwh * $cfg->electricityPricePerKwh,
                'costPumpTotal' => $s->costPumpTotal + $kwh * $cfg->electricityPricePerKwh,
            ];
        }
        if ($s->heaterOn) {
            $w = $in->heaterPower ?? $cfg->heaterPowerW;
            $kwh = ($w / 1000.0) * $dtH;
            $changes += [
                'heaterRuntimeTodaySec' => $s->heaterRuntimeTodaySec + $dt,
                'heaterRuntimeTotalSec' => $s->heaterRuntimeTotalSec + $dt,
                'kwhHeaterToday'  => $s->kwhHeaterToday + $kwh,
                'kwhHeaterTotal'  => $s->kwhHeaterTotal + $kwh,
                'costHeaterToday' => $s->costHeaterToday + $kwh * $cfg->electricityPricePerKwh,
                'costHeaterTotal' => $s->costHeaterTotal + $kwh * $cfg->electricityPricePerKwh,
            ];
        }

        return $changes === [] ? $s : $s->with($changes);
    }

    // ── 2. Tagesreset ─────────────────────────────────────────────────────────

    private function dayReset(PoolState $s, Inputs $in, Config $cfg): PoolState
    {
        // Tagesziel ohne Heizzustand-Abhängigkeit (C9-Erkenntnis aus Konzept 5b.3)
        $target = $cfg->dailyTargetSeconds($in->poolTemp, $s->heaterOn, ignoreHeaterBasis: true);

        return $s->with([
            'pumpRuntimeTodaySec'   => 0,
            'heaterRuntimeTodaySec' => 0,
            'pumpStartsToday'       => 0,
            'heaterStartsToday'     => 0,
            'kwhPumpToday'          => 0.0,
            'kwhHeaterToday'        => 0.0,
            'costPumpToday'         => 0.0,
            'costHeaterToday'       => 0.0,
            'dailyTargetSec'        => $target,
            'lastResetDate'         => $in->dateKey(),
            // Stagnationszähler läuft über Mitternacht weiter (5b.1) – nicht zurücksetzen
        ]);
    }

    // ── 3. Watchdog ───────────────────────────────────────────────────────────

    private function evaluateWatchdog(PoolState $s, Inputs $in, Config $cfg): PoolState
    {
        // A3: Modus des Vorzyklus heranziehen – kennt korrekt auch UMWAELZUNG_ERZWUNGEN,
        // dessen Plausibilitäts-Watchdog laut Konzept 7b.5 ausgesetzt ist.
        $mode = Mode::tryFrom($s->lastMode) ?? Mode::HeizenPv;
        $suspend = $mode->suspendsPlausibilityWatchdog();

        $pumpRun   = ($s->pumpOn && $s->pumpStartTs > 0) ? $in->now - $s->pumpStartTs : 0;
        $heaterRun = ($s->heaterOn && $s->heaterStartTs > 0) ? $in->now - $s->heaterStartTs : 0;

        // Absolute Grenzen (immer aktiv)
        if ($pumpRun > $cfg->watchdogPumpAbsoluteSec) {
            $this->addMessage(MessageLevel::Kritisch, 'Watchdog UP: absolute Laufzeitgrenze überschritten');
            $s = $this->recordFault($s, $cfg);
        } elseif (!$suspend && $pumpRun > $cfg->watchdogPumpPlausibilitySec) {
            $this->addMessage(MessageLevel::Warnung, 'Watchdog UP: Plausibilitätsschwelle überschritten');
            $s = $this->recordFault($s, $cfg, faultOnThreshold: false);
        }

        if ($heaterRun > $cfg->watchdogHeaterAbsoluteSec) {
            $this->addMessage(MessageLevel::Kritisch, 'Watchdog PH: absolute Laufzeitgrenze überschritten');
            $s = $this->recordFault($s, $cfg);
        } elseif (!$suspend && $heaterRun > $cfg->watchdogHeaterPlausibilitySec) {
            $this->addMessage(MessageLevel::Warnung, 'Watchdog PH: Plausibilitätsschwelle überschritten');
            $s = $this->recordFault($s, $cfg, faultOnThreshold: false);
        }

        return $s;
    }

    /** Erhöht den Fehler-Bucket; bei Schwellenüberschreitung optional FAULT. */
    private function recordFault(PoolState $s, Config $cfg, bool $faultOnThreshold = true): PoolState
    {
        $bucket = $s->faultBucket + 1.0;
        $fault  = $s->faultActive || ($faultOnThreshold && $bucket >= $cfg->faultBucketThreshold);
        return $s->with(['faultBucket' => $bucket, 'faultActive' => $fault]);
    }

    // ── 5. FAULT-Notbetrieb (7b.6) ────────────────────────────────────────────

    private function faultDecision(PoolState $s, Inputs $in, Config $cfg): Decision
    {
        // Notlauf nur, wenn keine UP-wirksame Sperrquelle aktiv ist (W12)
        $pumpLocked = $this->pumpLockActive($in);
        $pumpOn = false;

        if (!$pumpLocked) {
            $inRun = ($in->now - $s->faultEmergencyStartTs) < $cfg->faultEmergencyRunSec;
            $dueForRun = ($in->now - $s->faultEmergencyLastTs) >= $cfg->faultEmergencyIntervalSec;
            if ($inRun) {
                $pumpOn = true;
            } elseif ($dueForRun) {
                $s = $s->with(['faultEmergencyStartTs' => $in->now, 'faultEmergencyLastTs' => $in->now]);
                $pumpOn = true;
            }
        }

        $s = $this->applyTransitions($s, $in, $cfg, $pumpOn, false);
        $s = $s->with(['lastMode' => Mode::Fault->value]);

        return new Decision(
            mode:      Mode::Fault,
            pumpOn:    $pumpOn,
            heaterOn:  false,
            nextState: $s,
            messages:  $this->messages,
            simMode:   $cfg->simMode,
            poolTemp:  $in->poolTemp,
            poolSetpoint: $in->poolSetpoint,
        );
    }

    // ── 6. Modus-Auflösung (Prioritätshierarchie 3.2) ─────────────────────────

    private function resolveMode(Inputs $in, PoolState $s, Config $cfg): Mode
    {
        // GESPERRT
        foreach ($in->lockSources as $lock) {
            if ($lock->isEffective()) {
                $this->addMessage(MessageLevel::Info, 'Gesperrt: ' . $lock->name);
                return Mode::Gesperrt;
            }
        }

        // FROSTSCHUTZ (Zustand wurde in compute bereits fortgeschrieben)
        if ($s->frostActive) {
            return Mode::Frostschutz;
        }

        // DAUERBETRIEB
        if ($in->continuousMode) {
            return Mode::Dauerbetrieb;
        }

        // URLAUB (enthält Umwälzlogik, sperrt nur Heizen)
        if ($in->holidayMode) {
            return Mode::Urlaub;
        }

        // UMWAELZUNG_ERZWUNGEN
        if ($this->circulationNeeded($in, $s, $cfg)) {
            return Mode::UmwaelzungErzwungen;
        }

        // Regelbetrieb: Heizen oder Idle
        if ($this->heatDemand($in, $s, $cfg)) {
            if ($in->quickMode) {
                return Mode::HeizenSchnell;
            }
            if ($this->energyCheap($in, $s, $cfg)) {
                return Mode::HeizenPv;
            }
            return Mode::Idle; // Heizbedarf, aber kein PV/Schnell → warten
        }

        return Mode::Idle;
    }

    // ── 7. Aktor-Auflösung ────────────────────────────────────────────────────

    /**
     * @return array{0: bool, 1: bool, 2: PoolState}
     */
    private function resolveActors(Mode $mode, Inputs $in, PoolState $s, Config $cfg): array
    {
        return match ($mode) {
            Mode::Gesperrt   => [false, false, $s],
            Mode::Idle       => [false, false, $s],
            Mode::Frostschutz => [
                true,
                $in->outsideTemp !== null && $in->outsideTemp < $cfg->frostPhThreshold,
                $s,
            ],
            Mode::Dauerbetrieb => [true, $this->heaterOutput($in, $s, $cfg), $s],
            Mode::Urlaub       => [$this->circulationNeeded($in, $s, $cfg), false, $s],
            Mode::UmwaelzungErzwungen => [$this->circulationNeeded($in, $s, $cfg), false, $s],
            Mode::HeizenPv, Mode::HeizenSchnell => $this->resolveHeating($in, $s, $cfg),
            Mode::Fault => [false, false, $s], // in faultDecision behandelt
        };
    }

    /**
     * @return array{0: bool, 1: bool, 2: PoolState}
     */
    private function resolveHeating(Inputs $in, PoolState $s, Config $cfg): array
    {
        $heaterWanted = $this->heaterOutput($in, $s, $cfg);
        // UP muss laufen, wenn PH läuft oder Umwälzbedarf besteht (3.6)
        $pumpOn = $heaterWanted || $this->circulationNeeded($in, $s, $cfg);
        // PH erst nach bestätigtem UP-Lauf / Vorlaufzeit (5a.2)
        if ($heaterWanted && !$this->pumpConfirmed($in, $s, $cfg)) {
            $heaterWanted = false;
        }
        return [$pumpOn, $heaterWanted, $s];
    }

    // ── 8. State-Transitions ──────────────────────────────────────────────────

    private function applyTransitions(PoolState $s, Inputs $in, Config $cfg, bool $pumpOn, bool $heaterOn): PoolState
    {
        $changes = [];

        // Pumpe
        if ($pumpOn && !$s->pumpOn) {
            $changes['pumpStartTs']      = $in->now;
            $changes['pumpPauseSinceTs'] = 0;
            $changes['pumpStartsToday']  = $s->pumpStartsToday + 1;
            // Stagnationsziel mit echten Inputs einfrieren (W4)
            $target = $cfg->dailyTargetSeconds($in->poolTemp, $s->heaterOn);
            $changes['stagnationFrozenTargetSec'] = (int) round($cfg->stagnationMandatoryShare * $target);
        } elseif (!$pumpOn && $s->pumpOn) {
            $changes['pumpPauseSinceTs'] = $in->now;
            $changes['pumpTrailUntilTs'] = $in->now + $cfg->pumpTrailSec;
        }
        if ($pumpOn !== $s->pumpOn) {
            $changes['lastCmdPumpTs'] = $in->now;
            $changes['pumpOn'] = $pumpOn;
        }

        // Heizung
        if ($heaterOn && !$s->heaterOn) {
            $changes['heaterStartTs']     = $in->now;
            $changes['heaterStartsToday'] = $s->heaterStartsToday + 1;
        } elseif (!$heaterOn && $s->heaterOn) {
            $changes['heaterOffSinceTs'] = $in->now;
        }
        if ($heaterOn !== $s->heaterOn) {
            $changes['lastCmdHeaterTs'] = $in->now;
            $changes['heaterOn'] = $heaterOn;
        }

        return $changes === [] ? $s : $s->with($changes);
    }

    // ── Fachlogik-Helfer ──────────────────────────────────────────────────────

    /**
     * Berechnet den neuen Frost-Zustand mit Verlassens-Hysterese (Konzept 6).
     * Reine Funktion; das Ergebnis wird in compute() in den State geschrieben (A2).
     */
    private function computeFrostActive(Inputs $in, PoolState $s, Config $cfg): bool
    {
        if ($in->outsideTemp === null) {
            return false; // fail-safe: kein Sensor → kein Zwang
        }
        $t = $in->outsideTemp;
        if (!$s->frostActive && $t < $cfg->frostUpThreshold) {
            return true;
        }
        if ($s->frostActive && $t < $cfg->frostUpThreshold + $cfg->frostHysteresis) {
            return true; // Hysterese: bleibt aktiv bis Schwelle+Hysterese
        }
        return false;
    }

    private function heatDemand(Inputs $in, PoolState $s, Config $cfg): bool
    {
        if (!$in->hasValidPoolTemp()) {
            return false; // 5a.5 Fallback
        }
        // Rohrfühler: Messung erst nach Vorlauf verlässlich
        if ($cfg->sensorInPipe) {
            $warmupDone = ($in->now - $s->pumpStartTs) >= $cfg->sensorWarmupSec;
            if (($in->pumpFeedback ?? $s->pumpOn) === false && !$warmupDone) {
                return false;
            }
        }
        return $in->poolTemp <= ($in->poolSetpoint - $cfg->heatHysteresis);
    }

    private function heaterOutput(Inputs $in, PoolState $s, Config $cfg): bool
    {
        if (!$in->hasValidPoolTemp()) {
            return false;
        }
        $warmStop = $in->poolTemp >= $in->poolSetpoint; // IL-08

        // Takt-Schutz Mindest-Ausschaltzeit
        if (!$s->heaterOn) {
            $minOffDone = ($in->now - $s->heaterOffSinceTs) >= $cfg->phMinOffSec;
            if (!$minOffDone) {
                return false;
            }
        }
        // Takt-Schutz Mindest-Einschaltzeit (überstimmt Hysterese, außer Warm-Stopp)
        if ($s->heaterOn) {
            $minOnDone = ($in->now - $s->heaterStartTs) >= $cfg->phMinOnSec;
            if (!$minOnDone && !$warmStop) {
                return true;
            }
        }
        if ($warmStop) {
            return false;
        }
        return $this->heatDemand($in, $s, $cfg);
    }

    private function energyCheap(Inputs $in, PoolState $s, Config $cfg): bool
    {
        // 1. PV-Messung mit Hysterese + Debounce (Status liegt in $s, hier nur lesen)
        if ($in->pvPower !== null) {
            return $s->pvActive;
        }
        // 2. Externer Bool
        if ($in->energyCheap !== null) {
            return $in->energyCheap;
        }
        // 3. Zeitfenster-Fallback
        $h = $in->decimalHour();
        return $h >= $cfg->pvWindowStartHour && $h < $cfg->pvWindowEndHour;
    }

    private function circulationNeeded(Inputs $in, PoolState $s, Config $cfg): bool
    {
        // Stagnationsschranke
        if ($s->pumpPauseSinceTs > 0 && ($in->now - $s->pumpPauseSinceTs) >= $cfg->stagnationThresholdSec) {
            return true;
        }
        // Soll-Kurve unterschritten
        if ($this->belowCurve($in, $s, $cfg)) {
            return true;
        }
        // Spätester Start
        if ($this->afterLatestStart($in, $s, $cfg)) {
            return true;
        }
        // PV-Ertragszeit + Tagesziel noch nicht erreicht
        if ($this->energyCheap($in, $s, $cfg)) {
            return $s->pumpRuntimeTodaySec < $this->targetSeconds($s, $in, $cfg);
        }
        return false;
    }

    /** Aktuelles Tagesziel: bevorzugt der beim Reset gespeicherte Wert (5b.3). */
    private function targetSeconds(PoolState $s, Inputs $in, Config $cfg): int
    {
        return $s->dailyTargetSec > 0
            ? $s->dailyTargetSec
            : $cfg->dailyTargetSeconds($in->poolTemp, $s->heaterOn);
    }

    private function belowCurve(Inputs $in, PoolState $s, Config $cfg): bool
    {
        if ($cfg->curvePoints === []) {
            return false;
        }
        $points = $cfg->curvePoints;
        usort($points, static fn($a, $b) => $a['hour'] <=> $b['hour']);
        $h = $in->decimalHour();

        $pct = match (true) {
            $h <= $points[0]['hour']                       => 0.0,
            $h >= $points[array_key_last($points)]['hour'] => 100.0,
            default => $this->interpolate($points, $h),
        };

        $target  = $this->targetSeconds($s, $in, $cfg);
        $sollSec = $target * ($pct / 100.0);
        return $s->pumpRuntimeTodaySec < $sollSec;
    }

    /** @param list<array{hour: float, pct: float}> $points */
    private function interpolate(array $points, float $h): float
    {
        $count = count($points);
        for ($i = 0; $i < $count - 1; $i++) {
            [$p0, $p1] = [$points[$i], $points[$i + 1]];
            if ($h >= $p0['hour'] && $h <= $p1['hour']) {
                $t = ($h - $p0['hour']) / ($p1['hour'] - $p0['hour']);
                return $p0['pct'] + $t * ($p1['pct'] - $p0['pct']);
            }
        }
        return 0.0;
    }

    private function afterLatestStart(Inputs $in, PoolState $s, Config $cfg): bool
    {
        $target    = $this->targetSeconds($s, $in, $cfg);
        $remaining = max(0, $target - $s->pumpRuntimeTodaySec) / 3600.0;
        $latest    = $cfg->dayEndHour - $remaining;
        return $in->decimalHour() >= $latest;
    }

    private function pumpConfirmed(Inputs $in, PoolState $s, Config $cfg): bool
    {
        if ($in->pumpFeedback !== null) {
            return $in->pumpFeedback;
        }
        return ($in->now - $s->pumpStartTs) >= $cfg->pumpLeadSec;
    }

    private function pumpLockActive(Inputs $in): bool
    {
        foreach ($in->lockSources as $lock) {
            if ($lock->isEffective() && $lock->affectsPump()) {
                return true;
            }
        }
        return false;
    }

    // ── PV-Debounce-Zustandsfortschreibung (5a.4) ─────────────────────────────

    /**
     * Aktualisiert den PV-Debounce-Zustand. Wird von der Shell vor compute() aufgerufen,
     * da es zum Zustand gehört. Reine Funktion.
     */
    public function updatePvDebounce(PoolState $s, Inputs $in, Config $cfg): PoolState
    {
        if ($in->pvPower === null) {
            return $s;
        }
        $p = $in->pvPower;

        if (!$s->pvActive && $p >= $cfg->pvOnThreshold) {
            $start = $s->pvOnDebounceStartTs > 0 ? $s->pvOnDebounceStartTs : $in->now;
            if (($in->now - $start) >= $cfg->pvOnDebounceSec) {
                return $s->with(['pvActive' => true, 'pvOnDebounceStartTs' => 0, 'pvOffDebounceStartTs' => 0]);
            }
            return $s->with(['pvOnDebounceStartTs' => $start]);
        }

        if ($s->pvActive && $p < $cfg->pvOffThreshold) {
            $start = $s->pvOffDebounceStartTs > 0 ? $s->pvOffDebounceStartTs : $in->now;
            if (($in->now - $start) >= $cfg->pvOffDebounceSec) {
                return $s->with(['pvActive' => false, 'pvOnDebounceStartTs' => 0, 'pvOffDebounceStartTs' => 0]);
            }
            return $s->with(['pvOffDebounceStartTs' => $start]);
        }

        // Im Hysteresefenster → Debounce-Zähler zurücksetzen
        return $s->with(['pvOnDebounceStartTs' => 0, 'pvOffDebounceStartTs' => 0]);
    }

    // ── Quittierung & Reconciliation (öffentlich, von Shell genutzt) ──────────

    public function acknowledgeFault(PoolState $s): PoolState
    {
        return $s->with(['faultActive' => false, 'faultBucket' => 0.0]);
    }

    public function recoverSuccess(PoolState $s, Config $cfg): PoolState
    {
        return $s->with(['faultBucket' => max(0.0, $s->faultBucket - $cfg->faultBucketRecoverStep)]);
    }

    public static function isManualOverride(bool $feedback, bool $shadow, int $lastCmdTs, int $deadline, int $now): bool
    {
        return $feedback !== $shadow && ($now - $lastCmdTs) > $deadline;
    }

    // ── intern ────────────────────────────────────────────────────────────────

    private function addMessage(MessageLevel $level, string $text): void
    {
        $this->messages[] = new Message($level, $text);
    }
}
