<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/autoload.php';

use PoolControl\Core\ConfigValidator;
use PoolControl\Core\DecisionEngine;
use PoolControl\Core\Enum\MessageLevel;
use PoolControl\Core\Enum\Mode;
use PoolControl\Core\Enum\StaleAction;
use PoolControl\Core\Enum\StatusCode;
use PoolControl\Core\State\PoolState;
use PoolControl\Core\ValueObject\Config;
use PoolControl\Core\ValueObject\Decision;
use PoolControl\Core\ValueObject\Inputs;
use PoolControl\Core\ValueObject\LockSource;

/**
 * IP-Symcon Device-Modul (Thin Shell, Konzept 1a.1).
 *
 * Die Shell ist bewusst dünn: sie liest I/O, ruft den reinen DecisionEngine
 * und schreibt das Ergebnis zurück. Keine Fachlogik hier.
 */
class PoolControl extends IPSModule
{
    private const ATTR_STATE   = 'State';
    private const ATTR_CFG_VER = 'ConfigVersion';
    private const ATTR_RERUN   = 'RerunPending';
    private const ATTR_LOGTS   = 'LogDebounceMap';
    private const LOG_DEBOUNCE_SEC = 300; // N4: max. 1 gleiche Log-Meldung / 5 min
    private const CFG_VERSION  = 1;

    private const TIMER_MAIN   = 'Cycle';
    private const TIMER_VERIFY = 'Verify';

    private DecisionEngine $engine;

    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
        $this->engine = new DecisionEngine();
    }

    // ════════════════════════════════════════════════════════════════════════
    // LIFECYCLE
    // ════════════════════════════════════════════════════════════════════════

    public function Create(): void
    {
        parent::Create();
        $this->registerProperties();
        $this->RegisterAttributeString(self::ATTR_STATE, json_encode((new PoolState())->toArray()));
        $this->RegisterAttributeInteger(self::ATTR_CFG_VER, 0);
        $this->RegisterAttributeBoolean(self::ATTR_RERUN, false);
        $this->RegisterAttributeString(self::ATTR_LOGTS, '{}');
        $this->RegisterTimer(self::TIMER_MAIN, 0, 'POOL3_RunCycle($_IPS[\'TARGET\']);');
        $this->RegisterTimer(self::TIMER_VERIFY, 0, 'POOL3_RunVerify($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->migrate();
        $this->registerVariables();

        // Konfig-Validierung (7c.4) – Steuerbetrieb nur bei valider Konfig
        $errors = ConfigValidator::validate($this->buildConfig());
        if ($errors !== []) {
            $this->SetStatus($errors[0]->code->value);
            $this->SetTimerInterval(self::TIMER_MAIN, 0);
            $this->LogMessage('Konfigurationsfehler: ' . $errors[0]->message, KL_ERROR);
            return;
        }

        $this->registerMessages();

        // Stagger über verzögerten Timer-Erststart (nicht blockierend)
        $stagger  = $this->ReadPropertyInteger('StaggerOffsetSec');
        $interval = $this->ReadPropertyInteger('CycleIntervalSec') * 1000;
        $this->SetTimerInterval(self::TIMER_MAIN, $stagger > 0 ? ($stagger * 1000 + $interval) : $interval);

        $this->SetStatus(StatusCode::Ok->value);
        $this->softRestart();
    }

    public function MessageSink($Timestamp, $SenderID, $Message, $Data): void
    {
        if ($Message !== VM_UPDATE) {
            return;
        }
        $this->syncExternalSwitch((int) $SenderID);
        if ($this->isCriticalSensor((int) $SenderID)) {
            $this->RunCycle();
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // ÖFFENTLICHE METHODEN
    // ════════════════════════════════════════════════════════════════════════

    public function RequestAction($Ident, $Value): void
    {
        // Whitelist (W9)
        $handled = match ($Ident) {
            'QuickMode'      => $this->setSwitch('QuickMode', (bool) $Value),
            'HolidayMode'    => $this->setSwitch('HolidayMode', (bool) $Value),
            'ContinuousMode' => $this->setSwitch('ContinuousMode', (bool) $Value),
            'AckFault'       => $this->acknowledgeFault(),
            'DayReset'       => $this->manualDayReset(),
            default          => null,
        };
        if ($handled === null) {
            $this->LogMessage("Unbekannter RequestAction-Ident: {$Ident}", KL_WARNING);
        }
    }

    public function RunCycle(): void
    {
        $sem = 'POOL3_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sem, 500)) {
            // B1-Fix (W2): Dirty-Flag als eigenes Attribut atomar setzen – KEIN State-Roundtrip,
            // der ein gleichzeitiges Schreiben des Semaphore-Halters überschreiben könnte.
            $this->WriteAttributeBoolean(self::ATTR_RERUN, true);
            return;
        }
        try {
            $this->executeCycle();
            // Re-Run-Schleife: solange ein abgewiesener Pfad das Flag gesetzt hat
            $guard = 0;
            while ($this->ReadAttributeBoolean(self::ATTR_RERUN) && $guard++ < 3) {
                $this->WriteAttributeBoolean(self::ATTR_RERUN, false);
                $this->executeCycle();
            }
        } finally {
            IPS_SemaphoreLeave($sem);
        }
    }

    public function RunVerify(): void
    {
        $this->SetTimerInterval(self::TIMER_VERIFY, 0);
        $sem = 'POOL3_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sem, 500)) {
            return;
        }
        try {
            $this->verifySwitching();
        } finally {
            IPS_SemaphoreLeave($sem);
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // KERN-ZYKLUS
    // ════════════════════════════════════════════════════════════════════════

    private function executeCycle(): void
    {
        $cfg   = $this->buildConfig();
        $state = $this->loadState();
        $in    = $this->readInputs($state);

        // Reiner Entscheidungskern (enthält jetzt selbst den PV-Debounce, A6-Fix)
        $decision = $this->engine->compute($in, $state, $cfg);

        $this->applyDecision($decision, $state, $cfg);
        $this->persist($decision->nextState);
    }

    // ── readInputs: IPS → Inputs ──────────────────────────────────────────────

    private function readInputs(PoolState $state): Inputs
    {
        $now      = time();
        $staleSec = $this->ReadPropertyInteger('SensorStaleSec');

        return new Inputs(
            now:            $now,
            dtSeconds:      max(0, $now - $state->lastCycleTs),
            poolTemp:       $this->readSensor('PoolTempVarId', -10.0, 50.0, $staleSec),
            poolSetpoint:   $this->readSensor('PoolSetpointVarId', 5.0, 40.0, $staleSec),
            outsideTemp:    $this->readSensor('OutsideTempVarId', -50.0, 60.0, $staleSec),
            pvPower:        $this->readSensor('PvPowerVarId', -100000.0, 100000.0, $staleSec, $this->ReadPropertyFloat('PvPowerFactor')),
            energyCheap:    $this->readBool('EnergyCheapVarId', $staleSec),
            pumpFeedback:   $this->readBool('UpFeedbackVarId', 0),
            heaterFeedback: $this->readBool('PhFeedbackVarId', 0),
            quickMode:      $this->GetValue('QuickMode'),
            holidayMode:    $this->GetValue('HolidayMode'),
            continuousMode: $this->GetValue('ContinuousMode'),
            lockSources:    $this->readLockSources(),
            pumpPower:      $this->readSensor('UpPowerVarId', 0.0, 50000.0, 0),
            heaterPower:    $this->readSensor('PhPowerVarId', 0.0, 50000.0, 0),
        );
    }

    private function readSensor(string $prop, float $min, float $max, int $staleSec, float $factor = 1.0): ?float
    {
        $vid = $this->ReadPropertyInteger($prop);
        if ($vid <= 0 || !IPS_VariableExists($vid)) {
            return null;
        }
        if ($staleSec > 0 && $this->isStale($vid, $staleSec)) {
            return null;
        }
        $val = ((float) GetValue($vid)) * $factor;
        return ($val >= $min && $val <= $max) ? $val : null;
    }

    private function readBool(string $prop, int $staleSec): ?bool
    {
        $vid = $this->ReadPropertyInteger($prop);
        if ($vid <= 0 || !IPS_VariableExists($vid)) {
            return null;
        }
        if ($staleSec > 0 && $this->isStale($vid, $staleSec)) {
            return null;
        }
        return (bool) GetValue($vid);
    }

    private function isStale(int $vid, int $staleSec): bool
    {
        $updated = IPS_GetVariable($vid)['VariableUpdated'] ?? 0;
        return $updated > 0 && (time() - $updated) > $staleSec;
    }

    /** @return list<LockSource> */
    private function readLockSources(): array
    {
        $raw = json_decode($this->ReadPropertyString('LockSources'), true) ?? [];
        $result = [];
        foreach ($raw as $l) {
            $vid = $l['var_id'] ?? 0;
            if ($vid <= 0 || !IPS_VariableExists($vid)) {
                continue;
            }
            $staleAfter = $l['stale_after_sec'] ?? 900;
            $updated    = IPS_GetVariable($vid)['VariableUpdated'] ?? 0;
            $stale      = $updated > 0 && (time() - $updated) > $staleAfter;
            $value      = GetValue($vid);
            $active     = $this->evalCondition($value, $l['condition'] ?? 'bool_true', $l['threshold'] ?? 0);

            $result[] = new LockSource(
                name:        $l['name'] ?? 'Sperrquelle',
                active:      $active,
                stale:       $stale,
                staleAction: StaleAction::from($l['stale_action'] ?? 'loosen'),
                affects:     $l['affects'] ?? 'both',
            );
        }
        return $result;
    }

    private function evalCondition(mixed $v, string $cond, mixed $thr): bool
    {
        return match ($cond) {
            'bool_true'  => (bool) $v === true,
            'bool_false' => (bool) $v === false,
            'gt'         => (float) $v >  (float) $thr,
            'gte'        => (float) $v >= (float) $thr,
            'lt'         => (float) $v <  (float) $thr,
            'lte'        => (float) $v <= (float) $thr,
            default      => false,
        };
    }

    // ── applyDecision: Decision → IPS ─────────────────────────────────────────

    private function applyDecision(Decision $d, PoolState $prev, Config $cfg): void
    {
        $this->SetValue('Mode', $d->mode->value);
        $this->SetValue('FaultActive', $d->nextState->faultActive);

        foreach ($d->messages as $msg) {
            $this->emitMessage($msg->level, $msg->text);
        }

        // Schaltbefehle (W6: Sim-Modus unterdrückt)
        $this->switchActor('Up', $d->pumpOn, $prev->pumpOn, $d->simMode, $cfg);
        $this->switchActor('Ph', $d->heaterOn, $prev->heaterOn, $d->simMode, $cfg);

        $this->SetStatus(
            ($d->mode === Mode::Fault || $d->nextState->faultActive)
                ? StatusCode::FaultActive->value
                : StatusCode::Ok->value
        );

        // Anzeige-Variablen
        $ns = $d->nextState;
        $this->SetValue('PumpOn', $d->pumpOn);
        $this->SetValue('HeaterOn', $d->heaterOn);
        $this->SetValue('RuntimePumpToday', $ns->pumpRuntimeTodaySec);
        $this->SetValue('RuntimePumpTotal', $ns->pumpRuntimeTotalSec);
        $this->SetValue('RuntimeHeaterToday', $ns->heaterRuntimeTodaySec);
        $this->SetValue('RuntimeHeaterTotal', $ns->heaterRuntimeTotalSec);
        $this->SetValue('DailyTarget', $ns->dailyTargetSec);
        $this->SetValue('KwhPumpTotal', round($ns->kwhPumpTotal, 3));
        $this->SetValue('KwhHeaterTotal', round($ns->kwhHeaterTotal, 3));
        $this->SetValue('CostTotal', round($ns->costPumpTotal + $ns->costHeaterTotal, 2));

        if ($d->poolTemp !== null) {
            $this->SetValue('PoolTemp', $d->poolTemp);
        }
    }

    private function switchActor(string $actor, bool $desired, bool $current, bool $sim, Config $cfg): void
    {
        if ($desired === $current) {
            return;
        }
        $label = $actor === 'Up' ? 'UP' : 'PH';

        if ($sim) {
            $this->emitMessage(MessageLevel::Info, "[SIM] {$label} → " . ($desired ? 'EIN' : 'AUS'));
            return;
        }

        try {
            $this->dispatchSwitch($actor, $desired);

            if ($this->ReadPropertyInteger($actor . 'FeedbackVarId') > 0) {
                $this->SetTimerInterval(self::TIMER_VERIFY, $cfg->verifyDeadlineSec * 1000);
            }
        } catch (Throwable $e) {
            $this->emitMessage(MessageLevel::Warnung, "{$label} Schaltbefehl fehlgeschlagen: {$e->getMessage()}");
            // B2-Fix: Fehler-Bucket erhöhen UND Fault-Schwelle prüfen (kein acknowledgeFault!).
            // Ein Schaltfehler darf einen bestehenden FAULT niemals quittieren.
            $state  = $this->loadState();
            $bucket = $state->faultBucket + 1.0;
            $this->persist($state->with([
                'faultBucket' => $bucket,
                'faultActive' => $state->faultActive || $bucket >= $cfg->faultBucketThreshold,
            ]));
        }
    }

    /**
     * Dreistufige Schaltziel-Auflösung (Konzept 2.1, B7-Fix):
     *   TargetMode 0 = Variable: Stufe 1 (HasAction → RequestAction)
     *                            Stufe 2 (ohne Aktion → IPS_RequestAction auf Parent-Instanz)
     *   TargetMode 1 = Instanz-Aktion direkt: Stufe 3 (IPS_RequestAction mit konfiguriertem Ident)
     */
    private function dispatchSwitch(string $actor, bool $desired): void
    {
        $mode = $this->ReadPropertyInteger($actor . 'TargetMode');

        if ($mode === 1) {
            // Stufe 3: Instanz-Aktion direkt
            $instanceId = $this->ReadPropertyInteger($actor . 'TargetInstanceId');
            $ident      = $this->ReadPropertyString($actor . 'TargetIdent');
            if ($instanceId <= 0 || !IPS_InstanceExists($instanceId) || $ident === '') {
                throw new RuntimeException('Instanz-Aktion unvollständig konfiguriert');
            }
            IPS_RequestAction($instanceId, $ident, $desired);
            return;
        }

        // Stufe 1/2: Variable
        $vid = $this->ReadPropertyInteger($actor . 'TargetVarId');
        if ($vid <= 0 || !IPS_VariableExists($vid)) {
            throw new RuntimeException('Kein Schaltziel konfiguriert');
        }
        if (HasAction($vid)) {
            RequestAction($vid, $desired);          // Stufe 1
        } else {
            $parent = IPS_GetParent($vid);          // Stufe 2
            $ident  = IPS_GetObject($vid)['ObjectIdent'];
            if ($parent > 0 && $ident !== '') {
                IPS_RequestAction($parent, $ident, $desired);
            } else {
                throw new RuntimeException('Variable ohne Aktion und ohne auflösbaren Parent/Ident');
            }
        }
    }

    private function verifySwitching(): void
    {
        $cfg   = $this->buildConfig();
        $state = $this->loadState();
        $now   = time();

        foreach (['Up' => 'pumpOn', 'Ph' => 'heaterOn'] as $actor => $field) {
            $fbVid = $this->ReadPropertyInteger($actor . 'FeedbackVarId');
            if ($fbVid <= 0 || !IPS_VariableExists($fbVid)) {
                continue;
            }
            $actual  = (bool) GetValue($fbVid);
            $desired = $state->$field;
            $cmdTs   = $actor === 'Up' ? $state->lastCmdPumpTs : $state->lastCmdHeaterTs;

            if ($actual === $desired) {
                $state = $this->engine->recoverSuccess($state, $cfg);
                continue;
            }
            if (DecisionEngine::isManualOverride($actual, $desired, $cmdTs, $cfg->verifyDeadlineSec * 2, $now)) {
                // B3-Fix: bei manueller Übernahme auch StartTs setzen, damit der
                // Laufzeit-Watchdog eine real laufende Pumpe/Heizung erfassen kann.
                $changes = [$field => $actual];
                $startField = $actor === 'Up' ? 'pumpStartTs' : 'heaterStartTs';
                if ($actual) {
                    $changes[$startField] = $now;
                }
                $state = $state->with($changes);
                $this->emitMessage(MessageLevel::Info, "{$actor} manuell übernommen");
            } else {
                $bucket = $state->faultBucket + 1.0;
                $state = $state->with([
                    'faultBucket' => $bucket,
                    'faultActive' => $bucket >= $cfg->faultBucketThreshold,
                ]);
                $this->emitMessage(MessageLevel::Warnung, "{$actor} Verifikation fehlgeschlagen");
            }
        }
        $this->persist($state);
    }

    // ════════════════════════════════════════════════════════════════════════
    // AKTIONEN
    // ════════════════════════════════════════════════════════════════════════

    private function setSwitch(string $ident, bool $value): bool
    {
        $this->SetValue($ident, $value);
        $this->RunCycle();
        return true;
    }

    private function acknowledgeFault(): bool
    {
        $sem = 'POOL3_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sem, 500)) {
            return true;
        }
        try {
            $cfg   = $this->buildConfig();
            $state = $this->loadState();
            if ((time() - $state->lastQuittTs) < $cfg->quittRateLimitSec) {
                $this->emitMessage(MessageLevel::Warnung, 'Quittierung abgelehnt: Rate-Limit');
                return true;
            }
            $state = $this->engine->acknowledgeFault($state)->with(['lastQuittTs' => time()]);
            $this->persist($state);
            $this->SetValue('FaultActive', false);
            $this->SetStatus(StatusCode::Ok->value);
            $this->emitMessage(MessageLevel::Info, 'FAULT quittiert');
        } finally {
            IPS_SemaphoreLeave($sem);
        }
        return true;
    }

    private function manualDayReset(): bool
    {
        $this->persist($this->loadState()->with(['lastResetDate' => 0]));
        $this->RunCycle();
        return true;
    }

    // ════════════════════════════════════════════════════════════════════════
    // HELPER
    // ════════════════════════════════════════════════════════════════════════

    private function softRestart(): void
    {
        $state = $this->loadState();
        $now   = time();
        $changes = ['lastCycleTs' => $now];

        // Ist-Zustand aus Rückmeldung übernehmen, Start-TS absichern (Watchdog-Schutz)
        $upFb = $this->ReadPropertyInteger('UpFeedbackVarId');
        if ($upFb > 0 && IPS_VariableExists($upFb)) {
            $on = (bool) GetValue($upFb);
            $changes['pumpOn'] = $on;
            if ($on && $state->pumpStartTs <= 0) {
                $changes['pumpStartTs'] = $now;
            }
        }
        $phFb = $this->ReadPropertyInteger('PhFeedbackVarId');
        if ($phFb > 0 && IPS_VariableExists($phFb)) {
            $on = (bool) GetValue($phFb);
            $changes['heaterOn'] = $on;
            if ($on && $state->heaterStartTs <= 0) {
                $changes['heaterStartTs'] = $now;
            }
        }
        $this->persist($state->with($changes));
        $this->RunCycle();
    }

    private function syncExternalSwitch(int $senderId): void
    {
        $map = [
            $this->ReadPropertyInteger('QuickSyncVarId')      => 'QuickMode',
            $this->ReadPropertyInteger('HolidaySyncVarId')    => 'HolidayMode',
            $this->ReadPropertyInteger('ContinuousSyncVarId') => 'ContinuousMode',
        ];
        foreach ($map as $vid => $ident) {
            if ($vid > 0 && $vid === $senderId) {
                $this->SetValue($ident, (bool) GetValue($vid));
            }
        }
    }

    private function isCriticalSensor(int $senderId): bool
    {
        $critical = [
            $this->ReadPropertyInteger('OutsideTempVarId'),
            $this->ReadPropertyInteger('EnergyCheapVarId'),
        ];
        foreach (json_decode($this->ReadPropertyString('LockSources'), true) ?? [] as $l) {
            $critical[] = $l['var_id'] ?? 0;
        }
        return in_array($senderId, $critical, true);
    }

    private function emitMessage(MessageLevel $level, string $text): void
    {
        $now = time();

        // Anzeige-Variablen immer aktualisieren (Webfront zeigt stets aktuellen Stand)
        $this->SetValue('LastMessage', '[' . date('Y-m-d H:i:s', $now) . "] [{$level->value}] {$text}");
        $this->SetValue('MessageLevel', $level->value);
        $this->SendDebug($level->value, $text, 0);

        $ipsLevel = $level->ipsLogLevel();
        if ($ipsLevel === null) {
            return; // INFO: nur SendDebug, kein IPS-Log
        }

        // B8-Fix (N4): Rate-Limit pro Meldungstyp über Attribut-Map (Typ → letzter Log-TS).
        // Verhindert Log-Flut auch bei abwechselnden Meldungen (UP/PH-Verifikation etc.).
        $map = json_decode($this->ReadAttributeString(self::ATTR_LOGTS), true);
        $map = is_array($map) ? $map : [];
        $key = md5($level->value . '|' . $text);
        $lastTs = $map[$key] ?? 0;

        if (($now - $lastTs) >= self::LOG_DEBOUNCE_SEC) {
            $this->LogMessage($text, $ipsLevel);
            $map[$key] = $now;
            // Map bei Bedarf beschneiden (max 50 Einträge, älteste raus)
            if (count($map) > 50) {
                asort($map);
                $map = array_slice($map, -50, null, true);
            }
            $this->WriteAttributeString(self::ATTR_LOGTS, json_encode($map));
        }
    }

    private function loadState(): PoolState
    {
        $data = json_decode($this->ReadAttributeString(self::ATTR_STATE), true);
        return is_array($data) ? PoolState::fromArray($data) : new PoolState();
    }

    private function persist(PoolState $state): void
    {
        $this->WriteAttributeString(self::ATTR_STATE, json_encode($state->toArray()));
    }

    private function buildConfig(): Config
    {
        return new Config(
            simMode:                  $this->ReadPropertyBoolean('SimMode'),
            volume:                   $this->ReadPropertyFloat('Volume'),
            pumpFlow:                 $this->ReadPropertyFloat('PumpFlow'),
            circulationFactor:        $this->ReadPropertyFloat('CirculationFactor'),
            stagnationThresholdSec:   $this->ReadPropertyInteger('StagnationThresholdSec'),
            stagnationMandatoryShare: $this->ReadPropertyFloat('StagnationShare'),
            dayEndHour:               $this->ReadPropertyFloat('DayEndHour'),
            curvePoints:              json_decode($this->ReadPropertyString('CurvePoints'), true) ?? [],
            tempAdjustActive:         $this->ReadPropertyBoolean('TempAdjustActive'),
            tempAdjustBasis:          $this->ReadPropertyString('TempAdjustBasis'),
            tempAdjustRefTemp:        $this->ReadPropertyFloat('TempAdjustRefTemp'),
            tempAdjustHeaterBoost:    $this->ReadPropertyFloat('TempAdjustHeaterBoost'),
            pvWindowStartHour:        $this->ReadPropertyFloat('PvWindowStartHour'),
            pvWindowEndHour:          $this->ReadPropertyFloat('PvWindowEndHour'),
            heatHysteresis:           $this->ReadPropertyFloat('HeatHysteresis'),
            phMinOnSec:               $this->ReadPropertyInteger('PhMinOnSec'),
            phMinOffSec:              $this->ReadPropertyInteger('PhMinOffSec'),
            pumpLeadSec:              $this->ReadPropertyInteger('PumpLeadSec'),
            pumpTrailSec:             $this->ReadPropertyInteger('PumpTrailSec'),
            sensorInPipe:             $this->ReadPropertyBoolean('SensorInPipe'),
            sensorWarmupSec:          $this->ReadPropertyInteger('SensorWarmupSec'),
            pvOnThreshold:            $this->ReadPropertyFloat('PvOnThreshold'),
            pvOffThreshold:           $this->ReadPropertyFloat('PvOffThreshold'),
            pvOnDebounceSec:          $this->ReadPropertyInteger('PvOnDebounceSec'),
            pvOffDebounceSec:         $this->ReadPropertyInteger('PvOffDebounceSec'),
            pvStabilityReserve:       $this->ReadPropertyFloat('PvStabilityReserve'),
            pumpPowerW:               $this->ReadPropertyFloat('PumpPowerW'),
            heaterPowerW:             $this->ReadPropertyFloat('HeaterPowerW'),
            frostUpThreshold:         $this->ReadPropertyFloat('FrostUpThreshold'),
            frostPhThreshold:         $this->ReadPropertyFloat('FrostPhThreshold'),
            frostHysteresis:          $this->ReadPropertyFloat('FrostHysteresis'),
            faultBucketThreshold:     $this->ReadPropertyFloat('FaultBucketThreshold'),
            faultBucketRecoverStep:   $this->ReadPropertyFloat('FaultBucketRecoverStep'),
            faultBucketLeakRatePerMin: $this->ReadPropertyFloat('FaultBucketLeakRate'),
            verifyDeadlineSec:        $this->ReadPropertyInteger('VerifyDeadlineSec'),
            maxRetries:               $this->ReadPropertyInteger('MaxRetries'),
            watchdogPumpPlausibilitySec:   $this->ReadPropertyInteger('WatchdogPumpPlausSec'),
            watchdogPumpAbsoluteSec:       $this->ReadPropertyInteger('WatchdogPumpAbsSec'),
            watchdogHeaterPlausibilitySec: $this->ReadPropertyInteger('WatchdogHeaterPlausSec'),
            watchdogHeaterAbsoluteSec:     $this->ReadPropertyInteger('WatchdogHeaterAbsSec'),
            faultEmergencyRunSec:      $this->ReadPropertyInteger('FaultEmergencyRunSec'),
            faultEmergencyIntervalSec: $this->ReadPropertyInteger('FaultEmergencyIntervalSec'),
            quittRateLimitSec:         $this->ReadPropertyInteger('QuittRateLimitSec'),
            electricityPricePerKwh:    $this->ReadPropertyFloat('ElectricityPrice'),
        );
    }

    private function migrate(): void
    {
        if ($this->ReadAttributeInteger(self::ATTR_CFG_VER) < self::CFG_VERSION) {
            $this->WriteAttributeInteger(self::ATTR_CFG_VER, self::CFG_VERSION);
        }
    }

    private function registerProperties(): void
    {
        // System
        $this->RegisterPropertyBoolean('SimMode', false);
        $this->RegisterPropertyInteger('CycleIntervalSec', 60);
        $this->RegisterPropertyInteger('StaggerOffsetSec', 0);
        $this->RegisterPropertyInteger('SensorStaleSec', 900);
        // Aktoren (B7: dreistufige Auflösung – TargetMode 0=Variable, 1=Instanz-Aktion)
        $this->RegisterPropertyInteger('UpTargetMode', 0);
        $this->RegisterPropertyInteger('UpTargetVarId', 0);
        $this->RegisterPropertyInteger('UpTargetInstanceId', 0);
        $this->RegisterPropertyString('UpTargetIdent', '');
        $this->RegisterPropertyInteger('UpFeedbackVarId', 0);
        $this->RegisterPropertyInteger('PhTargetMode', 0);
        $this->RegisterPropertyInteger('PhTargetVarId', 0);
        $this->RegisterPropertyInteger('PhTargetInstanceId', 0);
        $this->RegisterPropertyString('PhTargetIdent', '');
        $this->RegisterPropertyInteger('PhFeedbackVarId', 0);
        // Sensoren
        $this->RegisterPropertyInteger('PoolTempVarId', 0);
        $this->RegisterPropertyInteger('PoolSetpointVarId', 0);
        $this->RegisterPropertyInteger('OutsideTempVarId', 0);
        $this->RegisterPropertyInteger('PvPowerVarId', 0);
        $this->RegisterPropertyFloat('PvPowerFactor', 1.0);
        $this->RegisterPropertyInteger('EnergyCheapVarId', 0);
        $this->RegisterPropertyInteger('UpPowerVarId', 0);
        $this->RegisterPropertyInteger('PhPowerVarId', 0);
        $this->RegisterPropertyInteger('QuickSyncVarId', 0);
        $this->RegisterPropertyInteger('HolidaySyncVarId', 0);
        $this->RegisterPropertyInteger('ContinuousSyncVarId', 0);
        // Sperrquellen
        $this->RegisterPropertyString('LockSources', '[]');
        // Umwälzung
        $this->RegisterPropertyFloat('Volume', 50.0);
        $this->RegisterPropertyFloat('PumpFlow', 8.0);
        $this->RegisterPropertyFloat('CirculationFactor', 2.0);
        $this->RegisterPropertyInteger('StagnationThresholdSec', 28800);
        $this->RegisterPropertyFloat('StagnationShare', 0.2);
        $this->RegisterPropertyFloat('DayEndHour', 22.0);
        $this->RegisterPropertyString('CurvePoints', json_encode([
            ['hour' => 12.0, 'pct' => 40.0],
            ['hour' => 16.0, 'pct' => 80.0],
            ['hour' => 20.0, 'pct' => 100.0],
        ]));
        $this->RegisterPropertyBoolean('TempAdjustActive', false);
        $this->RegisterPropertyString('TempAdjustBasis', 'water');
        $this->RegisterPropertyFloat('TempAdjustRefTemp', 28.0);
        $this->RegisterPropertyFloat('TempAdjustHeaterBoost', 0.2);
        $this->RegisterPropertyFloat('PvWindowStartHour', 10.0);
        $this->RegisterPropertyFloat('PvWindowEndHour', 16.0);
        // Heizung
        $this->RegisterPropertyFloat('HeatHysteresis', 0.5);
        $this->RegisterPropertyInteger('PhMinOnSec', 600);
        $this->RegisterPropertyInteger('PhMinOffSec', 600);
        $this->RegisterPropertyInteger('PumpLeadSec', 30);
        $this->RegisterPropertyInteger('PumpTrailSec', 0);
        $this->RegisterPropertyBoolean('SensorInPipe', true);
        $this->RegisterPropertyInteger('SensorWarmupSec', 60);
        // PV
        $this->RegisterPropertyFloat('PvOnThreshold', 3280.0);
        $this->RegisterPropertyFloat('PvOffThreshold', 200.0);
        $this->RegisterPropertyInteger('PvOnDebounceSec', 60);
        $this->RegisterPropertyInteger('PvOffDebounceSec', 120);
        $this->RegisterPropertyFloat('PvStabilityReserve', 0.1);
        $this->RegisterPropertyFloat('PumpPowerW', 300.0);
        $this->RegisterPropertyFloat('HeaterPowerW', 2500.0);
        // Frost
        $this->RegisterPropertyFloat('FrostUpThreshold', 3.0);
        $this->RegisterPropertyFloat('FrostPhThreshold', -5.0);
        $this->RegisterPropertyFloat('FrostHysteresis', 1.0);
        // Fault/Watchdog
        $this->RegisterPropertyFloat('FaultBucketThreshold', 5.0);
        $this->RegisterPropertyFloat('FaultBucketRecoverStep', 1.0);
        $this->RegisterPropertyFloat('FaultBucketLeakRate', 0.0833);
        $this->RegisterPropertyInteger('VerifyDeadlineSec', 5);
        $this->RegisterPropertyInteger('MaxRetries', 3);
        $this->RegisterPropertyInteger('WatchdogPumpPlausSec', 64800);
        $this->RegisterPropertyInteger('WatchdogPumpAbsSec', 86400);
        $this->RegisterPropertyInteger('WatchdogHeaterPlausSec', 43200);
        $this->RegisterPropertyInteger('WatchdogHeaterAbsSec', 64800);
        $this->RegisterPropertyInteger('FaultEmergencyRunSec', 900);
        $this->RegisterPropertyInteger('FaultEmergencyIntervalSec', 21600);
        $this->RegisterPropertyInteger('QuittRateLimitSec', 60);
        // Energie
        $this->RegisterPropertyFloat('ElectricityPrice', 0.30);
    }

    private function registerVariables(): void
    {
        if (!IPS_VariableProfileExists('POOL3.Mode')) {
            IPS_CreateVariableProfile('POOL3.Mode', 1);
            foreach (Mode::cases() as $mode) {
                IPS_SetVariableProfileAssociation('POOL3.Mode', $mode->value, $mode->label(), '', -1);
            }
        }

        $this->RegisterVariableInteger('Mode', $this->Translate('Betriebsmodus'), 'POOL3.Mode', 10);
        $this->RegisterVariableString('LastMessage', $this->Translate('Letzte Meldung'), '', 20);
        $this->RegisterVariableString('MessageLevel', $this->Translate('Meldungs-Stufe'), '', 21);
        $this->RegisterVariableBoolean('PumpOn', $this->Translate('UP an'), '', 30);
        $this->RegisterVariableBoolean('HeaterOn', $this->Translate('PH an'), '', 31);
        $this->RegisterVariableFloat('PoolTemp', $this->Translate('Pool-Temperatur'), '~Temperature', 40);

        $this->RegisterVariableBoolean('QuickMode', $this->Translate('Schnellmodus'), '~Switch', 50);
        $this->EnableAction('QuickMode');
        $this->RegisterVariableBoolean('HolidayMode', $this->Translate('Urlaub'), '~Switch', 51);
        $this->EnableAction('HolidayMode');
        $this->RegisterVariableBoolean('ContinuousMode', $this->Translate('Dauerbetrieb'), '~Switch', 52);
        $this->EnableAction('ContinuousMode');

        $this->RegisterVariableInteger('RuntimePumpToday', $this->Translate('UP Laufzeit heute [s]'), '', 60);
        $this->RegisterVariableInteger('RuntimePumpTotal', $this->Translate('UP Laufzeit gesamt [s]'), '', 61);
        $this->RegisterVariableInteger('RuntimeHeaterToday', $this->Translate('PH Laufzeit heute [s]'), '', 62);
        $this->RegisterVariableInteger('RuntimeHeaterTotal', $this->Translate('PH Laufzeit gesamt [s]'), '', 63);
        $this->RegisterVariableInteger('DailyTarget', $this->Translate('Tages-Umwälzziel [s]'), '', 64);
        $this->RegisterVariableFloat('KwhPumpTotal', $this->Translate('UP Energie gesamt [kWh]'), '', 70);
        $this->RegisterVariableFloat('KwhHeaterTotal', $this->Translate('PH Energie gesamt [kWh]'), '', 71);
        $this->RegisterVariableFloat('CostTotal', $this->Translate('Kosten gesamt [€]'), '', 72);
        $this->RegisterVariableBoolean('FaultActive', $this->Translate('FAULT aktiv'), '', 90);
    }

    private function registerMessages(): void
    {
        foreach ($this->GetMessageList() as $senderId => $msgs) {
            $this->UnregisterMessage($senderId, VM_UPDATE);
        }
        $ids = [
            'PoolTempVarId', 'PoolSetpointVarId', 'OutsideTempVarId', 'PvPowerVarId',
            'EnergyCheapVarId', 'UpFeedbackVarId', 'PhFeedbackVarId',
            'QuickSyncVarId', 'HolidaySyncVarId', 'ContinuousSyncVarId',
        ];
        foreach ($ids as $prop) {
            $vid = $this->ReadPropertyInteger($prop);
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $this->RegisterMessage($vid, VM_UPDATE);
            }
        }
        foreach (json_decode($this->ReadPropertyString('LockSources'), true) ?? [] as $l) {
            $vid = $l['var_id'] ?? 0;
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $this->RegisterMessage($vid, VM_UPDATE);
            }
        }
    }
}
