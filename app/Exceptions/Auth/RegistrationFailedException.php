<?php

namespace App\Exceptions\Auth;

use Exception;

class RegistrationFailedException extends Exception
{
    public function __construct(string $message = 'Registration failed', int $code = 500)
    {
        parent::__construct($message, $code);
    }
}
