<?php

declare(strict_types=1);

namespace PoolControl\Tests;

require_once __DIR__ . '/../libs/core/autoload.php';

use PHPUnit\Framework\TestCase;
use PoolControl\Core\ConfigValidator;
use PoolControl\Core\DecisionEngine;
use PoolControl\Core\Enum\Mode;
use PoolControl\Core\Enum\StaleAction;
use PoolControl\Core\Enum\StatusCode;
use PoolControl\Core\State\PoolState;
use PoolControl\Core\ValueObject\Config;
use PoolControl\Core\ValueObject\Inputs;
use PoolControl\Core\ValueObject\LockSource;

final class DecisionEngineTest extends TestCase
{
    private DecisionEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new DecisionEngine();
    }

    private function inputs(array $o = []): Inputs
    {
        return new Inputs(
            now:            $o['now']            ?? time(),
            dtSeconds:      $o['dtSeconds']      ?? 60,
            poolTemp:       array_key_exists('poolTemp', $o) ? $o['poolTemp'] : 26.0,
            poolSetpoint:   array_key_exists('poolSetpoint', $o) ? $o['poolSetpoint'] : 28.0,
            outsideTemp:    $o['outsideTemp']    ?? null,
            pvPower:        $o['pvPower']        ?? null,
            energyCheap:    $o['energyCheap']    ?? null,
            pumpFeedback:   $o['pumpFeedback']   ?? null,
            heaterFeedback: $o['heaterFeedback'] ?? null,
            quickMode:      $o['quickMode']      ?? false,
            holidayMode:    $o['holidayMode']    ?? false,
            continuousMode: $o['continuousMode'] ?? false,
            lockSources:    $o['lockSources']    ?? [],
            pumpPower:      $o['pumpPower']       ?? null,
            heaterPower:    $o['heaterPower']     ?? null,
        );
    }

    private function state(array $o = []): PoolState
    {
        return (new PoolState())->with(array_merge([
            'lastResetDate' => (int) date('Ymd'),
            'lastCycleTs'   => time() - 60,
            'dailyTargetSec' => 36000,
        ], $o));
    }

    private function config(array $o = []): Config
    {
        return new Config(...$o);
    }

    // ── Konfig-Validierung ────────────────────────────────────────────────────

    public function testValidConfigPasses(): void
    {
        $errors = ConfigValidator::validate($this->config([
            'pvOnThreshold' => 3500.0, 'pvOffThreshold' => 200.0,
            'pumpPowerW' => 300.0, 'heaterPowerW' => 2500.0,
        ]));
        $this->assertEmpty($errors);
    }

    public function testInvalidVolumeRejected(): void
    {
        $errors = ConfigValidator::validate($this->config(['volume' => 0.0]));
        $this->assertContains(StatusCode::ErrCirculationCfg, array_map(fn($e) => $e->code, $errors));
    }

    public function testFrostConfigRejected(): void
    {
        $errors = ConfigValidator::validate($this->config([
            'frostUpThreshold' => 3.0, 'frostPhThreshold' => 5.0,
        ]));
        $this->assertContains(StatusCode::ErrFrostConfig, array_map(fn($e) => $e->code, $errors));
    }

    public function testPvStabilityRejected(): void
    {
        $errors = ConfigValidator::validate($this->config([
            'pvOnThreshold' => 500.0, 'pvOffThreshold' => 200.0,
            'pumpPowerW' => 300.0, 'heaterPowerW' => 2500.0,
        ]));
        $this->assertContains(StatusCode::ErrPvStability, array_map(fn($e) => $e->code, $errors));
    }

    public function testDayEndBeforeCurveRejected(): void
    {
        $errors = ConfigValidator::validate($this->config([
            'curvePoints' => [['hour' => 20.0, 'pct' => 100.0]],
            'dayEndHour'  => 19.0,
        ]));
        $this->assertContains(StatusCode::ErrDayEndBeforeCurve, array_map(fn($e) => $e->code, $errors));
    }

    // ── State-Machine ──────────────────────────────────────────────────────────

    public function testIdleWhenSatisfied(): void
    {
        $d = $this->engine->compute(
            $this->inputs(['poolTemp' => 28.5]),
            $this->state(['pumpRuntimeTodaySec' => 36000]),
            $this->config()
        );
        $this->assertSame(Mode::Idle, $d->mode);
        $this->assertFalse($d->pumpOn);
        $this->assertFalse($d->heaterOn);
    }

    public function testFaultHasHighestPriority(): void
    {
        $d = $this->engine->compute(
            $this->inputs(['outsideTemp' => -10.0]),
            $this->state(['faultActive' => true]),
            $this->config()
        );
        $this->assertSame(Mode::Fault, $d->mode);
        $this->assertFalse($d->heaterOn);
    }

    public function testLockBeatsFrost(): void
    {
        $lock = new LockSource('Schwalldusche', true, false, StaleAction::Loesen, 'both');
        $d = $this->engine->compute(
            $this->inputs(['outsideTemp' => -10.0, 'lockSources' => [$lock]]),
            $this->state(),
            $this->config()
        );
        $this->assertSame(Mode::Gesperrt, $d->mode);
        $this->assertFalse($d->pumpOn);
    }

    public function testFrostActivates(): void
    {
        $d = $this->engine->compute(
            $this->inputs(['outsideTemp' => 2.0]),
            $this->state(),
            $this->config(['frostUpThreshold' => 3.0])
        );
        $this->assertSame(Mode::Frostschutz, $d->mode);
        $this->assertTrue($d->pumpOn);
    }

    public function testFrostPhOnStrictCold(): void
    {
        $d = $this->engine->compute(
            $this->inputs(['outsideTemp' => -6.0]),
            $this->state(['frostActive' => true]),
            $this->config(['frostUpThreshold' => 3.0, 'frostPhThreshold' => -5.0])
        );
        $this->assertTrue($d->pumpOn);
        $this->assertTrue($d->heaterOn);
    }

    public function testFrostFailSafeWithoutSensor(): void
    {
        $d = $this->engine->compute(
            $this->inputs(['outsideTemp' => null]),
            $this->state(),
            $this->config()
        );
        $this->assertNotSame(Mode::Frostschutz, $d->mode);
    }

    public function testContinuousBeatsHoliday(): void
    {
        $d = $this->engine->compute(
            $this->inputs(['continuousMode' => true, 'holidayMode' => true]),
            $this->state(),
            $this->config()
        );
        $this->assertSame(Mode::Dauerbetrieb, $d->mode);
        $this->assertTrue($d->pumpOn);
    }

    public function testHolidayBlocksHeating(): void
    {
        $d = $this->engine->compute(
            $this->inputs(['holidayMode' => true, 'poolTemp' => 20.0, 'poolSetpoint' => 28.0]),
            $this->state(),
            $this->config()
        );
        $this->assertSame(Mode::Urlaub, $d->mode);
        $this->assertFalse($d->heaterOn);
    }

    // ── Heizregelung ────────────────────────────────────────────────────────────

    public function testHeatDemandTriggersHeating(): void
    {
        $d = $this->engine->compute(
            $this->inputs(['poolTemp' => 27.0, 'poolSetpoint' => 28.0, 'quickMode' => true, 'pumpFeedback' => true]),
            // Tagesziel bereits erreicht → keine erzwungene Umwälzung, reiner Heizbetrieb
            $this->state(['heaterOffSinceTs' => time() - 700, 'pumpRuntimeTodaySec' => 36000, 'dailyTargetSec' => 36000]),
            $this->config(['heatHysteresis' => 0.5, 'phMinOffSec' => 600, 'sensorInPipe' => false])
        );
        $this->assertTrue($d->heaterOn);
    }

    public function testWarmStop(): void
    {
        $d = $this->engine->compute(
            $this->inputs(['poolTemp' => 28.1, 'poolSetpoint' => 28.0, 'quickMode' => true]),
            $this->state(['heaterOn' => true, 'heaterStartTs' => time() - 700]),
            $this->config(['phMinOnSec' => 600])
        );
        $this->assertFalse($d->heaterOn);
    }

    public function testMinOffTimeBlocks(): void
    {
        $d = $this->engine->compute(
            $this->inputs(['poolTemp' => 27.0, 'poolSetpoint' => 28.0, 'quickMode' => true, 'pumpFeedback' => true]),
            $this->state(['heaterOffSinceTs' => time() - 100]),
            $this->config(['phMinOffSec' => 600, 'sensorInPipe' => false])
        );
        $this->assertFalse($d->heaterOn);
    }

    public function testNoHeatWithoutTemp(): void
    {
        $d = $this->engine->compute(
            $this->inputs(['poolTemp' => null]),
            $this->state(),
            $this->config()
        );
        $this->assertFalse($d->heaterOn);
    }

    // ── Stagnation / Umwälzung ───────────────────────────────────────────────────

    public function testStagnationForcesCirculation(): void
    {
        $now = time();
        $d = $this->engine->compute(
            $this->inputs(['now' => $now, 'poolTemp' => 28.5]),
            $this->state(['pumpPauseSinceTs' => $now - 29000, 'pumpRuntimeTodaySec' => 3600]),
            $this->config(['stagnationThresholdSec' => 28800])
        );
        $this->assertSame(Mode::UmwaelzungErzwungen, $d->mode);
        $this->assertTrue($d->pumpOn);
        $this->assertFalse($d->heaterOn);
    }

    // ── Sperrquellen / Stale ─────────────────────────────────────────────────────

    public function testStaleLockLoosens(): void
    {
        $lock = new LockSource('Schwalldusche', true, true, StaleAction::Loesen, 'both');
        $d = $this->engine->compute(
            $this->inputs(['lockSources' => [$lock]]),
            $this->state(),
            $this->config()
        );
        $this->assertNotSame(Mode::Gesperrt, $d->mode);
    }

    public function testStaleLockHolds(): void
    {
        $lock = new LockSource('Niedrigwasser', true, true, StaleAction::Halten, 'both');
        $d = $this->engine->compute(
            $this->inputs(['lockSources' => [$lock]]),
            $this->state(),
            $this->config()
        );
        $this->assertSame(Mode::Gesperrt, $d->mode);
    }

    // ── Fault / Watchdog ─────────────────────────────────────────────────────────

    public function testWatchdogAbsoluteAlwaysActive(): void
    {
        $now = time();
        $d = $this->engine->compute(
            $this->inputs(['now' => $now, 'continuousMode' => true]),
            $this->state(['pumpOn' => true, 'pumpStartTs' => $now - 90000, 'faultBucket' => 4.0, 'lastCycleTs' => $now - 60]),
            $this->config(['watchdogPumpAbsoluteSec' => 86400, 'faultBucketThreshold' => 5.0])
        );
        $this->assertTrue($d->nextState->faultActive, 'Absoluter Watchdog muss auch im Dauerbetrieb FAULT auslösen');
    }

    public function testWatchdogZeroStartTsNoFault(): void
    {
        $now = time();
        $d = $this->engine->compute(
            $this->inputs(['now' => $now, 'quickMode' => true, 'poolTemp' => 28.5]),
            $this->state(['pumpOn' => true, 'pumpStartTs' => 0, 'lastCycleTs' => $now - 60]),
            $this->config(['watchdogPumpAbsoluteSec' => 86400])
        );
        $this->assertFalse($d->nextState->faultActive, 'pumpStartTs=0 darf keinen Fault auslösen');
    }

    public function testSingleErrorNoFault(): void
    {
        $s = $this->state(['faultBucket' => 0.0]);
        $s2 = (new \ReflectionClass($this->engine));
        // recordFault ist privat – über Watchdog mit knappem Wert prüfen
        $now = time();
        $d = $this->engine->compute(
            $this->inputs(['now' => $now, 'quickMode' => true, 'poolTemp' => 28.5]),
            $this->state(['pumpOn' => true, 'pumpStartTs' => $now - 90000, 'faultBucket' => 0.0, 'lastCycleTs' => $now - 60]),
            $this->config(['watchdogPumpAbsoluteSec' => 86400, 'faultBucketThreshold' => 5.0])
        );
        // 1 Event → Bucket 1 < 5 → kein Fault
        $this->assertFalse($d->nextState->faultActive);
        $this->assertEqualsWithDelta(1.0, $d->nextState->faultBucket, 0.2);
    }

    public function testAcknowledgeFaultClearsBucket(): void
    {
        $s = $this->state(['faultActive' => true, 'faultBucket' => 4.5]);
        $cleared = $this->engine->acknowledgeFault($s);
        $this->assertFalse($cleared->faultActive);
        $this->assertEquals(0.0, $cleared->faultBucket);
    }

    public function testBucketLeaksOverTime(): void
    {
        $now = time();
        $d = $this->engine->compute(
            $this->inputs(['now' => $now, 'dtSeconds' => 3600, 'poolTemp' => 28.5]),
            $this->state(['faultBucket' => 3.0, 'lastCycleTs' => $now - 3600]),
            $this->config(['faultBucketLeakRatePerMin' => 5.0 / 60.0])
        );
        $this->assertEquals(0.0, $d->nextState->faultBucket);
    }

    // ── Tagesreset ───────────────────────────────────────────────────────────────

    public function testDayResetClearsToday(): void
    {
        $yesterday = (int) date('Ymd', strtotime('yesterday'));
        $d = $this->engine->compute(
            $this->inputs(['poolTemp' => 28.5]),
            $this->state(['lastResetDate' => $yesterday, 'pumpRuntimeTodaySec' => 10000]),
            $this->config()
        );
        $this->assertEquals(0, $d->nextState->pumpRuntimeTodaySec);
        $this->assertEquals((int) date('Ymd'), $d->nextState->lastResetDate);
    }

    public function testStagnationSurvivesDayReset(): void
    {
        $yesterday = (int) date('Ymd', strtotime('yesterday'));
        // Zeitpunkt früh am Tag (03:00), wo die Soll-Kurve noch 0% verlangt →
        // kein Umwälzstart, der Pausenzähler bleibt unangetastet.
        $earlyMorning = strtotime('03:00');
        $pauseTs = $earlyMorning - 5000;
        $d = $this->engine->compute(
            $this->inputs(['now' => $earlyMorning, 'poolTemp' => 28.5]),
            $this->state([
                'lastResetDate' => $yesterday,
                'pumpPauseSinceTs' => $pauseTs,
                'lastCycleTs' => $earlyMorning - 60,
            ]),
            $this->config(['stagnationThresholdSec' => 28800])
        );
        // Reset nullt die Tageslaufzeit, aber pumpPauseSinceTs (Stagnation) überlebt
        $this->assertEquals($pauseTs, $d->nextState->pumpPauseSinceTs);
        $this->assertEquals(0, $d->nextState->pumpRuntimeTodaySec);
    }

    // ── Akkumulation ─────────────────────────────────────────────────────────────

    public function testRuntimeAccumulation(): void
    {
        $now = time();
        $d = $this->engine->compute(
            $this->inputs(['now' => $now, 'dtSeconds' => 60, 'poolTemp' => 28.5]),
            $this->state(['pumpOn' => true, 'pumpRuntimeTodaySec' => 1000, 'pumpRuntimeTotalSec' => 50000]),
            $this->config(['pumpPowerW' => 300.0, 'electricityPricePerKwh' => 0.30])
        );
        $this->assertEquals(1060, $d->nextState->pumpRuntimeTodaySec);
        $this->assertEquals(50060, $d->nextState->pumpRuntimeTotalSec);
        $this->assertEqualsWithDelta(0.005, $d->nextState->kwhPumpToday, 0.0001);
    }

    public function testLargeGapNotBooked(): void
    {
        $now = time();
        $d = $this->engine->compute(
            $this->inputs(['now' => $now, 'dtSeconds' => 0, 'poolTemp' => 28.5]),
            $this->state(['pumpOn' => true, 'pumpRuntimeTodaySec' => 1000, 'lastCycleTs' => $now - 7200]),
            $this->config()
        );
        $this->assertEquals(1000, $d->nextState->pumpRuntimeTodaySec);
    }

    // ── PV-Debounce ──────────────────────────────────────────────────────────────

    public function testPvDebounceTurnsOnAfterDelay(): void
    {
        $now = time();
        $cfg = $this->config(['pvOnThreshold' => 3280.0, 'pvOnDebounceSec' => 60]);
        $in  = $this->inputs(['now' => $now, 'pvPower' => 4000.0]);

        // Erster Aufruf startet Debounce
        $s1 = $this->engine->updatePvDebounce($this->state(['pvActive' => false]), $in, $cfg);
        $this->assertFalse($s1->pvActive);

        // Nach Debounce-Zeit
        $in2 = $this->inputs(['now' => $now + 61, 'pvPower' => 4000.0]);
        $s2 = $this->engine->updatePvDebounce($s1->with(['pvOnDebounceStartTs' => $now]), $in2, $cfg);
        $this->assertTrue($s2->pvActive);
    }

    // ── Immutabilität ────────────────────────────────────────────────────────────

    public function testStateIsImmutable(): void
    {
        $original = $this->state(['pumpRuntimeTodaySec' => 500]);
        $modified = $original->with(['pumpRuntimeTodaySec' => 999]);
        $this->assertEquals(500, $original->pumpRuntimeTodaySec, 'Original darf nicht mutiert werden');
        $this->assertEquals(999, $modified->pumpRuntimeTodaySec);
    }

    public function testStateRoundTrip(): void
    {
        $s = $this->state(['faultBucket' => 3.5, 'pumpOn' => true, 'pumpStartTs' => 1700000000]);
        $restored = PoolState::fromArray($s->toArray());
        $this->assertEquals($s->faultBucket, $restored->faultBucket);
        $this->assertSame($s->pumpOn, $restored->pumpOn);
        $this->assertSame($s->pumpStartTs, $restored->pumpStartTs);
    }

    public function testFromArrayTypeSafe(): void
    {
        $s = PoolState::fromArray([
            'pumpStartTs' => 1700000000.0,  // float → int
            'faultBucket' => 3,             // int → float
            'pumpOn'      => 1,             // int → bool
        ]);
        $this->assertIsInt($s->pumpStartTs);
        $this->assertIsFloat($s->faultBucket);
        $this->assertIsBool($s->pumpOn);
        $this->assertTrue($s->pumpOn);
    }

    // ── Simulationsmodus ─────────────────────────────────────────────────────────

    public function testSimModeFlagPropagated(): void
    {
        $d = $this->engine->compute(
            $this->inputs(['poolTemp' => 27.0, 'quickMode' => true]),
            $this->state(['heaterOffSinceTs' => time() - 700]),
            $this->config(['simMode' => true])
        );
        $this->assertTrue($d->simMode);
    }

    // ── Tagesziel-Berechnung ──────────────────────────────────────────────────────

    public function testDailyTargetCalculation(): void
    {
        $cfg = $this->config(['volume' => 50.0, 'pumpFlow' => 8.0, 'circulationFactor' => 2.0]);
        $this->assertEquals(45000, $cfg->dailyTargetSeconds(28.5, false));
    }

    public function testDailyTargetZeroFlowSafe(): void
    {
        $cfg = $this->config(['pumpFlow' => 0.0]);
        $this->assertEquals(0, $cfg->dailyTargetSeconds(28.5, false));
    }

    // ── Regressionstests für Review-Befunde (Team A / Team B) ─────────────────

    /** A2: Frost-Hysterese – frostActive wird persistiert, Frost bleibt bis Schwelle+Hysterese */
    public function testA2_FrostHysteresisPersists(): void
    {
        $cfg = $this->config(['frostUpThreshold' => 3.0, 'frostHysteresis' => 1.0]);
        $now = time();

        $d1 = $this->engine->compute(
            new Inputs(now: $now, dtSeconds: 60, outsideTemp: 2.0),
            $this->state(['lastCycleTs' => $now - 60]),
            $cfg
        );
        $this->assertTrue($d1->nextState->frostActive, 'A2: frostActive muss persistiert werden');

        // 3.5°C liegt über Schwelle (3.0), aber unter Schwelle+Hysterese (4.0) → bleibt aktiv
        $d2 = $this->engine->compute(
            new Inputs(now: $now + 60, dtSeconds: 60, outsideTemp: 3.5),
            $d1->nextState,
            $cfg
        );
        $this->assertSame(Mode::Frostschutz, $d2->mode, 'A2: Hysterese hält Frostschutz bis 4°C');
    }

    /** A2: Frost wird oberhalb Schwelle+Hysterese verlassen */
    public function testA2_FrostReleasedAboveHysteresis(): void
    {
        $cfg = $this->config(['frostUpThreshold' => 3.0, 'frostHysteresis' => 1.0]);
        $now = time();
        $d = $this->engine->compute(
            new Inputs(now: $now, dtSeconds: 60, outsideTemp: 4.5, poolTemp: 28.5),
            $this->state(['frostActive' => true, 'pumpRuntimeTodaySec' => 45000, 'lastCycleTs' => $now - 60]),
            $cfg
        );
        $this->assertFalse($d->nextState->frostActive, 'A2: über 4°C wird Frost verlassen');
    }

    /** A3: Plausibilitäts-Watchdog ist im UMWAELZUNG_ERZWUNGEN-Modus ausgesetzt */
    public function testA3_WatchdogSuspendedInForcedCirculation(): void
    {
        $now = time();
        $cfg = $this->config([
            'watchdogPumpPlausibilitySec' => 64800,
            'watchdogPumpAbsoluteSec'     => 86400,
            'faultBucketThreshold'        => 5.0,
        ]);
        // Vorzyklus war UMWAELZUNG_ERZWUNGEN, Pumpe läuft 19.4h (> Plausibilität, < absolut)
        $s = $this->state([
            'pumpOn' => true,
            'pumpStartTs' => $now - 70000,
            'lastMode' => Mode::UmwaelzungErzwungen->value,
            'lastCycleTs' => $now - 60,
        ]);
        $d = $this->engine->compute(
            new Inputs(now: $now, dtSeconds: 60, poolTemp: 28.5, pvPower: 5000.0),
            $s,
            $cfg
        );
        $this->assertEquals(0.0, $d->nextState->faultBucket,
            'A3: Plausibilitäts-WD darf im erzwungenen Umwälzlauf nicht feuern');
    }

    /** A3: absoluter Watchdog feuert auch im UMWAELZUNG_ERZWUNGEN */
    public function testA3_AbsoluteWatchdogStillFiresInForcedCirculation(): void
    {
        $now = time();
        $cfg = $this->config(['watchdogPumpAbsoluteSec' => 86400, 'faultBucketThreshold' => 5.0]);
        $s = $this->state([
            'pumpOn' => true,
            'pumpStartTs' => $now - 90000, // > 24h
            'lastMode' => Mode::UmwaelzungErzwungen->value,
            'faultBucket' => 4.0,
            'lastCycleTs' => $now - 60,
        ]);
        $d = $this->engine->compute(
            new Inputs(now: $now, dtSeconds: 60, poolTemp: 28.5),
            $s,
            $cfg
        );
        $this->assertTrue($d->nextState->faultActive,
            'A3: absoluter WD muss auch im erzwungenen Umwälzlauf FAULT auslösen');
    }

    /** A6: compute() schreibt pvActive selbst fort (Kern ohne externe Vorbearbeitung vollständig) */
    public function testA6_PvDebounceInsideCompute(): void
    {
        $now = time();
        $cfg = $this->config(['pvOnThreshold' => 3280.0, 'pvOffThreshold' => 200.0, 'pvOnDebounceSec' => 60]);
        $s = $this->state([
            'pvActive' => false,
            'pvOnDebounceStartTs' => $now - 61, // Debounce-Zeit überschritten
            'lastCycleTs' => $now - 60,
        ]);
        $d = $this->engine->compute(
            new Inputs(now: $now, dtSeconds: 60, poolTemp: 27.0, poolSetpoint: 28.0, pvPower: 4000.0),
            $s,
            $cfg
        );
        $this->assertTrue($d->nextState->pvActive,
            'A6: compute() muss pvActive selbst setzen, ohne externen updatePvDebounce-Aufruf');
    }
}
