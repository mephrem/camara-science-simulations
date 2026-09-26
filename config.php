<?php
/**
 * PhET Offline Library — settings.
 * Edit this file, then reload the admin page.
 */
return [
    // Shown at the top of the landing page.
    'site_title'    => 'Camara Science Simulations',
    'site_subtitle' => 'Interactive science & math simulations',

    // Password for admin.php (the update page).
    // Don't change it here: set it in config.local.php (see config.local.example.php).
    'admin_password' => 'change-me',

    // Languages to download. Each sim is saved as <sim>_<locale>.html.
    // Examples: 'en' English, 'am' Amharic, 'om' Afaan Oromo, 'ti' Tigrinya,
    // 'fr' French, 'ar' Arabic, 'sw' Swahili, 'pt_BR' Portuguese (Brazil).
    // Only sims that have been translated into a language are downloaded for it.
    'locales'        => ['en', 'am', 'om', 'ti'],
    'default_locale' => 'en',

    // Download a preview picture for every sim (about 100 KB each).
    'download_thumbnails' => true,

    // Sims added to this library within this many days get a "New" badge.
    'new_days' => 30,

    // Where updates come from. Leave as is.
    'phet_base' => 'https://phet.colorado.edu',

    // Seconds to wait for a single file before giving up.
    'download_timeout' => 300,
];
