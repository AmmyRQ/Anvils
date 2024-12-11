<?php

namespace AmmyRQ\Anvils\exception;

final class UndefinedPlayerException extends \Exception
{

    public function __construct(string $message = "")
    {
        parent::__construct("[Anvils] " . $message);
    }
}
