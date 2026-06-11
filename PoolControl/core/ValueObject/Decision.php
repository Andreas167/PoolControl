<?php

declare(strict_types=1);

namespace PoolControl\Core\ValueObject;

use PoolControl\Core\Enum\Mode;
use PoolControl\Core\State\PoolState;

/**
 * Ergebnis eines Entscheidungszyklus (Konzept 1a.1).
 * Immutabel: enthält das gewünschte Aktor-Soll, den neuen State und Meldungen.
 */
final readonly class Decision
{
    /**
     * @param list<Message> $messages
     */
    public function __construct(
        public Mode      $mode,
        public bool      $pumpOn,
        public bool      $heaterOn,
        public PoolState $nextState,
        public array     $messages = [],
        public bool      $simMode = false,
        public ?float    $poolTemp = null,
        public ?float    $poolSetpoint = null,
    ) {}
}
