<?php
// Load local env overrides (.env.local preferred, else .env) into getenv().
(function (): void {
    foreach (['/.env.local', '/.env'] as $file) {
        $path = __DIR__ . $file;
        if (!is_readable($path)) {
            continue;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if (getenv($key) === false) {
                putenv($key . '=' . trim($value));
            }
        }
        break;
    }
})();

// Database configuration (env-driven, shared PFEP server / "materials" DB)
define('DB_HOST',    getenv('DB_HOST')    ?: 'localhost');
define('DB_PORT',    getenv('DB_PORT')    ?: '3306');
define('DB_NAME',    getenv('DB_NAME')    ?: 'materials');
define('DB_USER',    getenv('DB_USER')    ?: 'root');
define('DB_PASS',    getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'L3aNnM43@ja!');
define('DB_SOCKET',  getenv('DB_SOCKET')  ?: '');
define('DB_CHARSET', 'utf8mb4');

// Application settings
define('APP_NAME', 'Scrap Ticket System');
define('APP_VERSION', '1.0.0');

// Ticket status constants
define('STATUS_PENDING',            'pending');
define('STATUS_PARTIALLY_APPROVED', 'partially_approved');
define('STATUS_APPROVED',           'approved');
define('STATUS_REJECTED',           'rejected');

// Approval action constants
define('ACTION_PENDING',  'pending');
define('ACTION_APPROVED', 'approved');
define('ACTION_REJECTED', 'rejected');
