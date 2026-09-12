<?php

return [
    // Only audited local disks are eligible. Never infer a missing historical disk.
    'allowed_disks' => ['local', 'project-imports'],
    'temporary_directory' => storage_path('app/private/document-version-downloads'),
];
