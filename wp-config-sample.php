<?php
/**
 * Clean Room CMS - Configuration File (Sample)
 *
 * Copy this file to wp-config.php and fill in the values.
 */

// Database settings
define('DB_NAME', 'cleanroom');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_HOST', 'localhost');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', 'utf8mb4_unicode_ci');

// Table prefix - change this if running multiple installations in one database
$table_prefix = 'cr_';

// Authentication keys and salts - CHANGE THESE to unique random phrases
define('AUTH_KEY',         'put-your-unique-phrase-here');
define('SECURE_AUTH_KEY',  'put-your-unique-phrase-here');
define('LOGGED_IN_KEY',    'put-your-unique-phrase-here');
define('NONCE_KEY',        'put-your-unique-phrase-here');
define('AUTH_SALT',        'put-your-unique-phrase-here');
define('SECURE_AUTH_SALT', 'put-your-unique-phrase-here');
define('LOGGED_IN_SALT',   'put-your-unique-phrase-here');
define('NONCE_SALT',       'put-your-unique-phrase-here');

// Debug mode
define('CR_DEBUG', false);
define('CR_DEBUG_LOG', false);
define('CR_DEBUG_DISPLAY', false);

// Site URLs - change to your domain
define('CR_SITE_URL', 'http://localhost:8080');
define('CR_HOME_URL', 'http://localhost:8080');

// Filesystem paths (auto-configured)
define('CR_BASE_PATH', __DIR__);
define('CR_CORE_PATH', CR_BASE_PATH . '/core');
define('CR_CONTENT_PATH', CR_BASE_PATH . '/content');
define('CR_ADMIN_PATH', CR_BASE_PATH . '/admin');
define('CR_PLUGIN_PATH', CR_CONTENT_PATH . '/plugins');
define('CR_THEME_PATH', CR_CONTENT_PATH . '/themes');
define('CR_UPLOAD_PATH', CR_CONTENT_PATH . '/uploads');

// Content URLs
define('CR_CONTENT_URL', CR_SITE_URL . '/content');
define('CR_PLUGIN_URL', CR_CONTENT_URL . '/plugins');
define('CR_THEME_URL', CR_CONTENT_URL . '/themes');
define('CR_UPLOAD_URL', CR_CONTENT_URL . '/uploads');

// Trusted proxies (set to array of IPs if behind reverse proxy)
// define('CR_TRUSTED_PROXIES', ['10.0.0.1']);

// Default timezone
date_default_timezone_set('UTC');

// Load the CMS
require_once CR_CORE_PATH . '/bootstrap.php';
