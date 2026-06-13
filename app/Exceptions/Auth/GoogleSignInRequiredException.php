<?php

namespace App\Exceptions\Auth;

use Exception;

class GoogleSignInRequiredException extends Exception
{
    public const MESSAGE = 'This email is registered with Google. Please use Google below to sign in.';

    public function __construct(string $message = self::MESSAGE, int $code = 400)
    {
        parent::__construct($message, $code);
    }
}
