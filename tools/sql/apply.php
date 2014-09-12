<?php // For help, invoke as $ php apply.php --help

define('SYSPATH', 'foo'); // A syspath (any syspath) is required to access kohana config files for security

// Path to directory containing patches to apply
$patch_directory_path = __DIR__ . "/../../sql/active";

// Path to database config file
$database_config_path = __DIR__ . "/../../application-auth/config/database.php";

// Run the apply script
require(__DIR__ . '/../../vendor/mshanken/sql-versioning/apply.php');