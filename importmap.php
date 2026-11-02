<?php

/**
 * Returns the importmap for this application.
 * Only local files: nothing is fetched from a CDN at build or run time.
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
    'chat' => [
        'path' => './assets/chat.js',
        'entrypoint' => true,
    ],
];
