<?php

namespace App\Services\Auth;

use App\Exceptions\Auth\SocialAuthFailedException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use stdClass;
use Throwable;

class AppleIdentityTokenVerifier
{
    private const JWKS_URL = 'https://appleid.apple.com/auth/keys';

    private const VALID_ISSUER = 'https://appleid.apple.com';

    /**
     * @throws SocialAuthFailedException
     */
    public function verify(string $identityToken): stdClass
    {
        try {
            $payload = JWT::decode($identityToken, JWK::parseKeySet($this->getJwks()));
        } catch (Throwable) {
            throw new SocialAuthFailedException('Invalid Apple identity token');
        }

        if (($payload->iss ?? '') !== self::VALID_ISSUER) {
            throw new SocialAuthFailedException('Invalid Apple identity token issuer');
        }

        $allowedClientIds = array_values(array_unique(array_filter([
            config('services.apple.client_id'),
        ])));

        if ($allowedClientIds === []) {
            throw new SocialAuthFailedException('Apple client ID is not configured on the server');
        }

        $tokenAudience = $payload->aud ?? null;
        if (is_array($tokenAudience)) {
            $audienceMatches = count(array_intersect($allowedClientIds, $tokenAudience)) > 0;
        } else {
            $audienceMatches = in_array($tokenAudience, $allowedClientIds, true);
        }

        if (! $audienceMatches) {
            throw new SocialAuthFailedException(
                'Invalid Apple identity token audience. Ensure backend APPLE_CLIENT_ID matches the iOS bundle ID.',
            );
        }

        if (empty($payload->sub)) {
            throw new SocialAuthFailedException('Invalid Apple identity token subject');
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
        return Cache::remember('apple_oauth_jwks', 3600, function (): array {
            $response = Http::timeout(10)->get(self::JWKS_URL);

            if (! $response->successful()) {
                throw new SocialAuthFailedException('Unable to verify Apple identity token');
            }

            return $response->json();
        });
    }
}
