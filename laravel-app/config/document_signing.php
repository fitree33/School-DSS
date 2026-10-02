<?php

return [
    'node_binary' => env('DOCUMENT_SIGNING_NODE_BINARY', 'node'),
    'timeout_seconds' => 45,
    'max_pdf_bytes' => 100_000_000,
    'storage_disk' => 'signed-documents',
];
