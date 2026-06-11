<?php

declare(strict_types=1);

namespace PoolControl\Core\Enum;

/**
 * SetStatus-Codes (Konzept 7c.4).
 * 102/104 sind IPS-Standard (aktiv/inaktiv), 2xx modulspezifisch.
 */
enum StatusCode: int
{
    case Ok                 = 102;
    case Inactive           = 104;
    case ErrUpTarget        = 201;
    case ErrPhTarget        = 202;
    case ErrSensorMissing   = 203;
    case ErrFrostConfig     = 204;
    case ErrCirculationCfg  = 205;
    case ErrCurveInvalid    = 206;
    case ErrPvStability     = 207;
    case ErrTimeWindow      = 208;
    case ErrDayEndBeforeCurve = 209;
    case FaultActive        = 210;

    public function isConfigError(): bool
    {
        return $this->value >= 201 && $this->value <= 209;
    }
}
