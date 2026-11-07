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
    'dashboard' => [
        'path' => './assets/dashboard.js',
        'entrypoint' => true,
    ],
    'courses' => [
        'path' => './assets/courses.js',
        'entrypoint' => true,
    ],
    'lesson' => [
        'path' => './assets/lesson.js',
        'entrypoint' => true,
    ],
    'studio' => [
        'path' => './assets/studio.js',
        'entrypoint' => true,
    ],
    // Stimulus 3.2.2, copied from the npm package's dist/stimulus.js into assets/lib (assets/vendor is not shipped).
    '@hotwired/stimulus' => [
        'path' => './assets/lib/stimulus.js',
    ],
];
