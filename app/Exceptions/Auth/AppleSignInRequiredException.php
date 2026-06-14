<?php

namespace App\Exceptions\Auth;

use Exception;

class AppleSignInRequiredException extends Exception
{
    public const MESSAGE = 'This email is registered with Apple. Please use Sign in with Apple below to sign in.';

    public function __construct(string $message = self::MESSAGE, int $code = 400)
    {
        parent::__construct($message, $code);
    }
}
