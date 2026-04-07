<?php

return [
    /**
     * Mobile app: when true, users must grant location + push before the main UI (RequiredPermissionsGate).
     * When false, home is reachable without those permissions. Defaults to true if env is unset.
     */
    'blocking_permissions' => filter_var(
        env('BLOCKING_PERMISSIONS', true),
        FILTER_VALIDATE_BOOLEAN
    ),
];
