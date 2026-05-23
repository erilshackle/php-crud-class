<?php

namespace Qcrud\Exceptions;

class ConnectionException extends \Exception
{
    public function __construct(string $message = "Database connection failed", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
