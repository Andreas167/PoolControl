<?php

declare(strict_types=1);

namespace PoolControl\Core;

use PoolControl\Core\Enum\StatusCode;
use PoolControl\Core\ValueObject\Config;

/** Ergebnis einer Konfig-Prüfung. */
final readonly class ValidationError
{
    public function __construct(
        public StatusCode $code,
        public string     $message,
    ) {}
}

/**
 * Validiert die Konfiguration vor dem Steuerbetrieb (Konzept 7c.4).
 * Reine Funktion – keine IPS-Abhängigkeit.
 */
final class ConfigValidator
{
    /**
     * @return list<ValidationError>
     */
    public static function validate(Config $cfg): array
    {
        $errors = [];

        // Umwälzung (205)
        if ($cfg->volume <= 0.0 || $cfg->pumpFlow <= 0.0) {
            $errors[] = new ValidationError(
                StatusCode::ErrCirculationCfg,
                'Poolvolumen und Förderleistung müssen > 0 sein'
            );
        }

        // Frost (204): PH-Schwelle muss unter UP-Schwelle liegen
        if ($cfg->frostPhThreshold >= $cfg->frostUpThreshold) {
            $errors[] = new ValidationError(
                StatusCode::ErrFrostConfig,
                'PH-Frostschwelle muss unter UP-Frostschwelle liegen'
            );
        }

        // Soll-Kurve (206): monoton steigend, max 100
        $points = $cfg->curvePoints;
        if ($points !== []) {
            usort($points, static fn($a, $b) => $a['hour'] <=> $b['hour']);
            $lastPct = -1.0;
            foreach ($points as $p) {
                if ($p['pct'] <= $lastPct || $p['pct'] > 100.0) {
                    $errors[] = new ValidationError(
                        StatusCode::ErrCurveInvalid,
                        'Soll-Kurve muss monoton steigen und max. 100% erreichen'
                    );
                    break;
                }
                $lastPct = $p['pct'];
            }

            // Tagesende nach letztem Stützpunkt (209)
            $lastHour = $points[array_key_last($points)]['hour'];
            if ($cfg->dayEndHour <= $lastHour) {
                $errors[] = new ValidationError(
                    StatusCode::ErrDayEndBeforeCurve,
                    'Tagesende-Grenze muss nach dem letzten Soll-Kurven-Stützpunkt liegen'
                );
            }
        }

        // PV-Zeitfenster (208)
        if ($cfg->pvWindowEndHour <= $cfg->pvWindowStartHour) {
            $errors[] = new ValidationError(
                StatusCode::ErrTimeWindow,
                'PV-Zeitfenster-Ende muss nach dem Start liegen'
            );
        }

        // PV-Stabilitätsbedingung (207, W1): Abstand ≥ P_eigen + Reserve
        $pEigen   = $cfg->pumpPowerW + $cfg->heaterPowerW;
        $required = $pEigen * (1.0 + $cfg->pvStabilityReserve);
        $actual   = $cfg->pvOnThreshold - $cfg->pvOffThreshold;
        if ($actual < $required) {
            $errors[] = new ValidationError(
                StatusCode::ErrPvStability,
                sprintf(
                    'PV-Schwellenabstand %.0f W < benötigt %.0f W (P_eigen %.0f W + %.0f%% Reserve) – Takt-Gefahr!',
                    $actual,
                    $required,
                    $pEigen,
                    $cfg->pvStabilityReserve * 100
                )
            );
        }

        return $errors;
    }
}
