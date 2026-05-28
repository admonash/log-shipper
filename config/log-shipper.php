<?php

// Default config for the log-shipper package. Consuming projects can publish
// + override this. Values fall back to env() so a fresh install only needs
// LOG_SHIPPER_TOKEN in .env to work.
return [
    'url'   => env('LOG_SHIPPER_URL', 'https://logs.rivion.ai'),
    'token' => env('LOG_SHIPPER_TOKEN'),
];
