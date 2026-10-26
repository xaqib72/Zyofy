<?php
/**
 * Pickwell application configuration — TEMPLATE.
 *
 * Copy this file to config.php on the server and fill in the values for the
 * hosting account. config.php itself must never be committed with real
 * credentials, and is blocked from direct web access by .htaccess.
 *
 * Required: app.url, db.host, db.name, db.user, db.password.
 */
return [
    'app' => [
        // Public brand name, used by the application code (not the hard-coded templates).
        'name' => 'Pickwell',
        // Canonical origin, no trailing slash. Every generated URL, canonical tag,
        // sitemap entry and Open Graph URL is built from this value.
        'url' => 'https://pickwell.t86.site',
        'environment' => 'production',
        // Never leave debug enabled on a public server.
        'debug' => false,
        // Set to true only for a staging copy that must stay out of search results.
        'noindex' => false,
        'support_email' => 'you@example.com',
        'support_phone' => '+10000000000',
    ],
    'db' => [
        'host' => 'localhost',
        // Hostinger names databases and users with the account prefix, e.g.
        // u123456789_pickwell — copy the exact values from hPanel → Databases.
        'name' => 'u000000000_pickwell',
        'user' => 'u000000000_pickwell',
        'password' => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],
];
