<?php

return [
    'disk' => env('PROJECT_IMPORTS_DISK', 'project-imports'),

    'upload' => [
        'max_bytes' => (int) env('PROJECT_IMPORT_MAX_BYTES', 10 * 1024 * 1024),
        'accepted_mime_types' => ['application/pdf'],
    ],

    'pdf' => [
        'pdfinfo_binary' => env('PROJECT_IMPORT_PDFINFO_BINARY', 'pdfinfo'),
        'pdftotext_binary' => env('PROJECT_IMPORT_PDFTOTEXT_BINARY', 'pdftotext'),
        'timeout_seconds' => (float) env('PROJECT_IMPORT_PDF_TIMEOUT_SECONDS', 30),
        'max_pages' => (int) env('PROJECT_IMPORT_PDF_MAX_PAGES', 100),
        'max_text_bytes' => (int) env('PROJECT_IMPORT_PDF_MAX_TEXT_BYTES', 2 * 1024 * 1024),
        'reject_encrypted' => filter_var(
            env('PROJECT_IMPORT_PDF_REJECT_ENCRYPTED', true),
            FILTER_VALIDATE_BOOL,
        ),
    ],

    'processing' => [
        'queue' => env('PROJECT_IMPORT_QUEUE', 'document-imports'),
        'tries' => (int) env('PROJECT_IMPORT_JOB_TRIES', 1),
        'timeout_seconds' => (int) env('PROJECT_IMPORT_JOB_TIMEOUT_SECONDS', 120),
    ],

    'provider' => [
        'driver' => env('PROJECT_IMPORT_PROVIDER', 'n8n'),
        'schema_version' => env('PROJECT_IMPORT_SCHEMA_VERSION', 'project-import.v1'),
        'prompt_version' => env('PROJECT_IMPORT_PROMPT_VERSION'),
        'model_name' => env('PROJECT_IMPORT_MODEL_NAME'),
        'n8n' => [
            'url' => env('PROJECT_IMPORT_N8N_URL'),
            'token' => env('PROJECT_IMPORT_N8N_TOKEN'),
            'timeout_seconds' => (float) env('PROJECT_IMPORT_N8N_TIMEOUT_SECONDS', 30),
            'connect_timeout_seconds' => (float) env('PROJECT_IMPORT_N8N_CONNECT_TIMEOUT_SECONDS', 5),
        ],
    ],

    'callback' => [
        'url' => env('PROJECT_IMPORT_CALLBACK_URL'),
        'current_secret' => env('PROJECT_IMPORT_CALLBACK_SECRET'),
        'previous_secret' => env('PROJECT_IMPORT_CALLBACK_PREVIOUS_SECRET'),
        'max_clock_skew_seconds' => (int) env('PROJECT_IMPORT_CALLBACK_MAX_CLOCK_SKEW_SECONDS', 300),
        'max_body_bytes' => (int) env('PROJECT_IMPORT_CALLBACK_MAX_BODY_BYTES', 1024 * 1024),
        'max_requests_per_minute' => (int) env('PROJECT_IMPORT_CALLBACK_MAX_REQUESTS_PER_MINUTE', 60),
    ],
];
