<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'scrap_tickets_db');
define('DB_USER', 'scrap_user');
define('DB_PASS', 'scrap_pass');
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
