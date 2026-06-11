<?php

declare(strict_types=1);

namespace PoolControl\Core\ValueObject;

use PoolControl\Core\Enum\MessageLevel;

/** Eine Statusmeldung aus dem Entscheidungskern. */
final readonly class Message
{
    public function __construct(
        public MessageLevel $level,
        public string       $text,
    ) {}
}
