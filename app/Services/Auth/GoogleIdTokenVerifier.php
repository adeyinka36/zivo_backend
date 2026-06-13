<?php

namespace App\Services\Auth;

use App\Exceptions\Auth\SocialAuthFailedException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use stdClass;
use Throwable;

class GoogleIdTokenVerifier
{
    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    private const VALID_ISSUERS = [
        'https://accounts.google.com',
        'accounts.google.com',
    ];

    /**
     * @throws SocialAuthFailedException
     */
    public function verify(string $idToken): stdClass
    {
        try {
            $payload = JWT::decode($idToken, JWK::parseKeySet($this->getJwks()));
        } catch (Throwable) {
            throw new SocialAuthFailedException('Invalid Google ID token');
        }

        if (! in_array($payload->iss ?? '', self::VALID_ISSUERS, true)) {
            throw new SocialAuthFailedException('Invalid Google ID token issuer');
        }

        $allowedClientIds = array_values(array_unique(array_filter([
            config('services.google.client_id'),
            env('GOOGLE_OAUTH_WEB_CLIENT_ID'),
        ])));

        if ($allowedClientIds === []) {
            throw new SocialAuthFailedException('Google client ID is not configured on the server');
        }

        $tokenAudience = $payload->aud ?? null;
        if (is_array($tokenAudience)) {
            $audienceMatches = count(array_intersect($allowedClientIds, $tokenAudience)) > 0;
        } else {
            $audienceMatches = in_array($tokenAudience, $allowedClientIds, true);
        }

        if (! $audienceMatches) {
            throw new SocialAuthFailedException(
                'Invalid Google ID token audience. Ensure backend GOOGLE_CLIENT_ID matches app EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID.',
            );
        }

        if (($payload->email_verified ?? false) !== true) {
            throw new SocialAuthFailedException('Google account email is not verified');
        }

        if (empty($payload->email)) {
            throw new SocialAuthFailedException('Google account must have an email address');
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SocialAuthFailedException
     */
    private function getJwks(): array
    {
        return Cache::remember('google_oauth_jwks', 3600, function (): array {
            $response = Http::timeout(10)->get(self::JWKS_URL);

            if (! $response->successful()) {
                throw new SocialAuthFailedException('Unable to verify Google ID token');
            }

            return $response->json();
        });
    }
}
