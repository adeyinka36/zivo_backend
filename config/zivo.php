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

    /**
     * Mobile app: when true, platform-info exposes campaigns discovery and GET /discover/campaigns is allowed.
     */
    'show_campaigns_list' => filter_var(
        env('SHOW_CAMPAIGNS_LIST', true),
        FILTER_VALIDATE_BOOLEAN
    ),
];
