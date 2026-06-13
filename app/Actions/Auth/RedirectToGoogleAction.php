<?php

namespace App\Actions\Auth;

use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse;

class RedirectToGoogleAction
{
    public function execute(): RedirectResponse
    {
        return Socialite::driver('google')->stateless()->redirect();
    }
}
