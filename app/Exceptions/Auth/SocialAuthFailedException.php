<?php

namespace App\Exceptions\Auth;

use Exception;

class SocialAuthFailedException extends Exception
{
    public function __construct(string $message = 'Google authentication failed', int $code = 401)
    {
        parent::__construct($message, $code);
    }
}
