<?php

return [
    // Deliberately not registered as a Laravel filesystem disk or a public link.
    'storage_root' => storage_path('private/signature-assets'),
    'temporary_directory' => storage_path('private/signature-asset-tmp'),

    // Per database lock wait, not a total network deadline. Every timeout retains bytes.
    'reconciliation_lock_wait_seconds' => 2,

    // Empty PHP configuration fails closed; never use PHP's default temp fallback.
    'php_upload_directory' => ini_get('upload_tmp_dir') ?: null,

    // Explicit deployment service-account SIDs, in addition to the running account,
    // SYSTEM and Administrators. These apply only to the existing PHP upload root.
    'php_upload_allowed_service_sids' => array_values(array_filter(array_map(
        'trim', explode(',', (string) env('SIGNATURE_PHP_UPLOAD_ALLOWED_SERVICE_SIDS', '')),
    ))),
];
