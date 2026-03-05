<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Audit Retention Defaults
    |--------------------------------------------------------------------------
    |
    | Retention can be overridden per action in audit policy settings.
    |
    */
    'retention' => [
        'mandatory_days' => (int) env('AUDIT_RETENTION_MANDATORY_DAYS', 365),
        'optional_days' => (int) env('AUDIT_RETENTION_OPTIONAL_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Sampling Defaults
    |--------------------------------------------------------------------------
    |
    | Sampling applies to optional events only. Mandatory events are always
    | stored with sampling rate 1.0.
    |
    */
    'sampling' => [
        'default_optional_rate' => (float) env('AUDIT_OPTIONAL_DEFAULT_SAMPLING_RATE', 1.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Payload Sanitization
    |--------------------------------------------------------------------------
    |
    | Sensitive keys are masked before persisting old/new values. Large payloads
    | are replaced by compact metadata to prevent unbounded row growth.
    |
    */
    'payload' => [
        'redacted_text' => (string) env('AUDIT_REDACTED_TEXT', '[REDACTED]'),
        'max_json_bytes' => (int) env('AUDIT_PAYLOAD_MAX_JSON_BYTES', 8192),
        'sensitive_keys' => array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', (string) env(
                'AUDIT_SENSITIVE_KEYS',
                'password,password_confirmation,token,access_token,refresh_token,secret,client_secret,api_key,authorization,cookie,otp,two_factor_secret'
            ))
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Queue Delivery
    |--------------------------------------------------------------------------
    |
    | Audit writes can be pushed to queue for lower API latency under load.
    | Keep sync delivery as automatic fallback when queue dispatch fails.
    |
    */
    'queue' => [
        'enabled' => (bool) env('AUDIT_QUEUE_ENABLED', true),
        'connection' => env('AUDIT_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
        'name' => env('AUDIT_QUEUE_NAME', 'audit'),
        'tries' => (int) env('AUDIT_QUEUE_TRIES', 5),
        'backoff' => array_values(array_filter(array_map(
            static fn (string $value): int => max(1, (int) trim($value)),
            explode(',', (string) env('AUDIT_QUEUE_BACKOFF', '5,30,120'))
        ))),
        'timeout' => (int) env('AUDIT_QUEUE_TIMEOUT', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | API Access Logs (High Frequency)
    |--------------------------------------------------------------------------
    |
    | Full request-level API logs are stored in a dedicated table to avoid
    | bloating audit_logs. Sampling applies unless response is server error.
    |
    */
    'api_access' => [
        'enabled' => (bool) env('API_ACCESS_LOG_ENABLED', false),
        'sample_rate' => (float) env('API_ACCESS_LOG_SAMPLE_RATE', 0.2),
        'errors_only' => (bool) env('API_ACCESS_LOG_ERRORS_ONLY', false),
        'retention_days' => (int) env('API_ACCESS_LOG_RETENTION_DAYS', 30),
        'excluded_paths' => array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', (string) env(
                'API_ACCESS_LOG_EXCLUDED_PATHS',
                'api/health*,api/health/*,api/language/messages,api/language/locales,api/system/bootstrap'
            ))
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Auditable Events Catalog
    |--------------------------------------------------------------------------
    |
    | category:
    | - mandatory: cannot be disabled, always sampled at 1.0
    | - optional: can be configured by policy
    |
    */
    'events' => [
        'auth.login' => [
            'category' => 'optional',
            'log_type' => 'login',
            'description' => 'User login',
            'default_enabled' => false,
            'default_retention_days' => 30,
            'default_sampling_rate' => 1.0,
        ],
        'auth.logout' => [
            'category' => 'optional',
            'log_type' => 'login',
            'description' => 'User logout',
            'default_enabled' => false,
            'default_retention_days' => 30,
            'default_sampling_rate' => 1.0,
        ],

        // User security and lifecycle
        'user.register' => ['category' => 'mandatory', 'log_type' => 'data', 'description' => 'User account registration'],
        'user.verify_email' => ['category' => 'mandatory', 'log_type' => 'login', 'description' => 'User email verification'],
        'user.2fa.enable' => ['category' => 'mandatory', 'log_type' => 'login', 'description' => 'Enable two-factor authentication'],
        'user.2fa.disable' => ['category' => 'mandatory', 'log_type' => 'login', 'description' => 'Disable two-factor authentication'],
        'user.assign_role' => ['category' => 'mandatory', 'log_type' => 'permission', 'description' => 'Assign role to user'],
        'user.create' => ['category' => 'mandatory', 'log_type' => 'data', 'description' => 'Create user'],
        'user.update' => ['category' => 'mandatory', 'log_type' => 'data', 'description' => 'Update user'],
        'user.delete' => ['category' => 'mandatory', 'log_type' => 'data', 'description' => 'Delete user (legacy)'],
        'user.deactivate' => ['category' => 'mandatory', 'log_type' => 'data', 'description' => 'Deactivate user'],
        'user.soft_delete' => ['category' => 'mandatory', 'log_type' => 'data', 'description' => 'Soft delete user'],
        'user.profile.update' => ['category' => 'mandatory', 'log_type' => 'data', 'description' => 'Update own profile'],

        // Noisy preference event: disabled by default
        'user.locale.update' => [
            'category' => 'optional',
            'log_type' => 'data',
            'description' => 'Update preferred language',
            'default_enabled' => false,
            'default_retention_days' => 30,
            'default_sampling_rate' => 1.0,
        ],
        'user.preferences.update' => [
            'category' => 'optional',
            'log_type' => 'data',
            'description' => 'Update user preferences',
            'default_enabled' => false,
            'default_retention_days' => 30,
            'default_sampling_rate' => 1.0,
        ],

        // Role and permission management
        'role.create' => ['category' => 'mandatory', 'log_type' => 'permission', 'description' => 'Create role'],
        'role.update' => ['category' => 'mandatory', 'log_type' => 'permission', 'description' => 'Update role'],
        'role.delete' => ['category' => 'mandatory', 'log_type' => 'permission', 'description' => 'Delete role (legacy)'],
        'role.deactivate' => ['category' => 'mandatory', 'log_type' => 'permission', 'description' => 'Deactivate role'],
        'role.soft_delete' => ['category' => 'mandatory', 'log_type' => 'permission', 'description' => 'Soft delete role'],
        'role.sync_permissions' => ['category' => 'mandatory', 'log_type' => 'permission', 'description' => 'Sync role permissions'],
        'permission.create' => ['category' => 'mandatory', 'log_type' => 'permission', 'description' => 'Create permission'],
        'permission.update' => ['category' => 'mandatory', 'log_type' => 'permission', 'description' => 'Update permission'],
        'permission.delete' => ['category' => 'mandatory', 'log_type' => 'permission', 'description' => 'Delete permission (legacy)'],
        'permission.deactivate' => ['category' => 'mandatory', 'log_type' => 'permission', 'description' => 'Deactivate permission'],
        'permission.soft_delete' => ['category' => 'mandatory', 'log_type' => 'permission', 'description' => 'Soft delete permission'],

        // Tenant management
        'tenant.create' => ['category' => 'mandatory', 'log_type' => 'data', 'description' => 'Create tenant'],
        'tenant.update' => ['category' => 'mandatory', 'log_type' => 'data', 'description' => 'Update tenant'],
        'tenant.delete' => ['category' => 'mandatory', 'log_type' => 'data', 'description' => 'Delete tenant (legacy)'],
        'tenant.deactivate' => ['category' => 'mandatory', 'log_type' => 'data', 'description' => 'Deactivate tenant'],
        'tenant.soft_delete' => ['category' => 'mandatory', 'log_type' => 'data', 'description' => 'Soft delete tenant'],

        // Organization and team management
        'organization.create' => ['category' => 'mandatory', 'log_type' => 'data', 'description' => 'Create organization'],
        'organization.update' => ['category' => 'mandatory', 'log_type' => 'data', 'description' => 'Update organization'],
        'organization.deactivate' => ['category' => 'mandatory', 'log_type' => 'data', 'description' => 'Deactivate organization'],
        'organization.soft_delete' => ['category' => 'mandatory', 'log_type' => 'data', 'description' => 'Soft delete organization'],
        'team.create' => ['category' => 'mandatory', 'log_type' => 'data', 'description' => 'Create team'],
        'team.update' => ['category' => 'mandatory', 'log_type' => 'data', 'description' => 'Update team'],
        'team.deactivate' => ['category' => 'mandatory', 'log_type' => 'data', 'description' => 'Deactivate team'],
        'team.soft_delete' => ['category' => 'mandatory', 'log_type' => 'data', 'description' => 'Soft delete team'],

        // Localization content management
        'language.translation.create' => ['category' => 'optional', 'log_type' => 'data', 'description' => 'Create language translation'],
        'language.translation.update' => ['category' => 'optional', 'log_type' => 'data', 'description' => 'Update language translation'],
        'language.translation.delete' => ['category' => 'optional', 'log_type' => 'data', 'description' => 'Delete language translation'],

        // Audit system operations
        'audit.policy.update' => ['category' => 'mandatory', 'log_type' => 'permission', 'description' => 'Update audit policy'],
        'system.config.update' => ['category' => 'mandatory', 'log_type' => 'operation', 'description' => 'Update system configuration'],
        'theme.config.update' => ['category' => 'mandatory', 'log_type' => 'operation', 'description' => 'Update theme configuration'],
        'theme.config.reset' => ['category' => 'mandatory', 'log_type' => 'operation', 'description' => 'Reset theme configuration'],
        'feature-flag.toggle' => ['category' => 'mandatory', 'log_type' => 'operation', 'description' => 'Toggle feature flag'],
        'feature-flag.purge' => ['category' => 'mandatory', 'log_type' => 'operation', 'description' => 'Purge feature flag override'],
    ],
];
