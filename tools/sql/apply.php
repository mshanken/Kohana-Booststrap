<?php // For help, invoke as $ php apply.php --help

/**
 * This parameter is the path where all the patches that should be part of the database are
 * found.  All files with names matching *.sql in that directory will be considered as patches;
 * which ones need to be applied, and in what order, will be computed automatically, taking the
 * current state of the database into account.
 */
$patch_directory_path = __DIR__ . "/../../sql/active";

/**
 * This parameter should be FALSE for normal usage; TRUE for debugging this script itself by
 * creating a database from scratch.  It may also have other uses.  When the parameter is TRUE,
 * the script connects first to the postgres database and issues an appropriate CREATE DATABASE
 * command, then reconnects to the new one.
 */
$create_database = FALSE;

/**
 * This parameter should be FALSE for normal usage; TRUE for debugging this script itself by
 * dropping the entire database (!!!) after applying patches to it.  It has no effect unless
 * $create_database is also TRUE.
 */
$drop_database = FALSE;

/*********************************************************************************************/



$verbose = FALSE;
$rollback = FALSE;
$mode = 'apply';

$succeeded = TRUE;

$script_file = array_shift($argv);
while(count($argv) > 0)
{
    $option = array_shift($argv);
    if($option == "--verbose")
    {
        $verbose = TRUE;
    }
    else if($option == "--terse")
    {
        $verbose = FALSE;
    }
    else if($option == "--rollback")
    {
        $rollback = TRUE;
    }
    else if($option = "--commit")
    {
        $rollback = FALSE;
    }
    else if($option == "--apply")
    {
        $mode = 'apply';
    }
    else if($option == "--help")
    {
        $mode = 'help';
    }
    else
    {
        echo "Unknown option " . $option . "\n";
        $succeeded = FALSE;
    }
}

if(!$succeeded)
{
    echo "No actions performed.\n";
    exit(1);
}

if($mode == 'help')
{
    echo "\n";
    echo "Usage: php " . $script_file . " [option...]\n";
    echo "\n";
    echo "--help                This message.\n";
    echo "--apply               Normal mode (default).\n";
    echo "--verbose             Show success messages as well as errors.\n";
    echo "--terse               Show only error messages (default).\n";
    echo "--rollback	        Rollback after applying any patches.\n";
    echo "--commit	        Commit after applying any patches (default).\n";
    echo "\n";
    exit(0);
}


$database_config = include __DIR__ . "/../../application/config/database.php";

if(!$create_database) {
    $pdo_name = $database_config['default']['connection']['dsn'];
    $pdo_name .= ";user=" . $database_config['default']['connection']['username'];
    $pdo_name .= ";password=" . $database_config['default']['connection']['password'];
    $pgsql = new PDO($pdo_name);

    if(!($pgsql instanceof PDO))
    {
        echo "Cannot connect to database at \"" . $pdo_name . "\".\n";
        exit(1);
    }
    
    $query = $pgsql->query("SELECT patch_name FROM _v.patches");
    if($query)
    {
        $results = $query->fetchAll();
    }
    else
    {
        $results = array();
    }
    $active_patches = array();
    foreach($results as $result) {
        $active_patches[] = $result['patch_name'];
    }
    
    echo "Currently active patches are: " . implode(' ', $active_patches) . "\n";
} else {
    $active_patches = array();
    
    echo "Starting from an empty database.\n";
}

$directory_path = $patch_directory_path;
$directory = opendir($directory_path);
$available_migrations = array();
while($entry = readdir($directory)) {
    if(in_array($entry, array(".", ".."))) continue;
    
    if(!is_file($directory_path . "/" . $entry)) continue;
    
    $matches = array();
    if(!preg_match("/^([0-9]{4}-.*)\\.sql$/", $entry, $matches)) continue;
    
    if(in_array($matches[1], $active_patches)) continue;
    
    $available_migrations[] = $directory_path . "/" . $entry;
}
closedir($directory);
sort($available_migrations, SORT_STRING);

if(!count($available_migrations)) {
    echo "Already current.\n";
    exit(0);
}

$script_pipes = array();
$script = proc_open(__DIR__ . "/versioning/tools/list-dependencies-from-patches.sh " . implode(' ', $available_migrations), array(array("pipe", "r"), array("pipe", "w"), STDOUT), $script_pipes);
$tsort_pipes = array();
$tsort = proc_open("tsort", array($script_pipes[1], array("pipe", "w"), STDOUT), $tsort_pipes);
fclose($script_pipes[0]);
$sorted_migrations = array_diff(array_reverse(explode("\n", stream_get_contents($tsort_pipes[1]))), $active_patches);
fclose($script_pipes[1]);
fclose($tsort_pipes[1]);
proc_close($script);
proc_close($tsort);

$temporary = array();
foreach($sorted_migrations as $migration) {
    if($migration == "") continue;
    $temporary[] = $migration;
}
$sorted_migrations = $temporary;

$hostname = NULL;
$port = NULL;
$database_name = NULL;
$user = NULL;
$password = NULL;

$conninfo_string = $database_config['default']['connection']['dsn'];

$matches = array();
if(preg_match("/host=([^ ;]+)/", $conninfo_string, $matches)) $hostname = $matches[1];

$matches = array();
if(preg_match("/port=([^ ;]+)/", $conninfo_string, $matches)) $port = $matches[1];

$matches = array();
if(preg_match("/dbname=([^ ;]+)/", $conninfo_string, $matches)) $database_name = $matches[1];

$matches = array();
if(preg_match("/user=([^ ;]+)/", $conninfo_string, $matches)) $user = $matches[1];

$matches = array();
if(preg_match("/password=([^ ;]+)/", $conninfo_string, $matches)) $password = $matches[1];

if(array_key_exists('username', $database_config['default']['connection']))
{
    $user = $database_config['default']['connection']['username'];
}

if(array_key_exists('password', $database_config['default']['connection']))
{
    $password = $database_config['default']['connection']['password'];
}

if($succeeded) {
    if($create_database) {
        echo "Creating database " . $database_name . ".\n";
    
        $psql_pipes = array();
        $psql = proc_open("psql -w --host=" . $hostname . " --port=" . $port . " --username=" . $user . " postgres", array(array("pipe", "r"), array("pipe", "w"), STDOUT), $psql_pipes, NULL, array('PGPASSWORD' => $password));
    
        fwrite($psql_pipes[0], "CREATE DATABASE " . $database_name . ";\n");
        fflush($psql_pipes[0]);
    
        fclose($psql_pipes[0]);
        fclose($psql_pipes[1]);
    
        proc_close($psql);
    }
}

if($succeeded) {
    if($create_database) {
        $psql_pipes = array();
        $psql = proc_open("psql -w --host=" . $hostname . " --port=" . $port . " --username=" . $user . " " . $database_name, array(array("pipe", "r"), array("pipe", "w"), array("pipe", "w")), $psql_pipes, NULL, array('PGPASSWORD' => $password));
    
        echo "Installing versioning schema into newly-created database.\n";
    
        $sql = file_get_contents(__DIR__ . "/versioning/install.versioning.sql");
        fwrite($psql_pipes[0], $sql . "\n");
        fflush($psql_pipes[0]);
        
        while(TRUE) {
            $read_pipes = array($psql_pipes[1]);
            $write_pipes = array();
            $exception_pipes = array();
            stream_select($read_pipes, $write_pipes, $exception_pipes, 0);
            $line = fgets($psql_pipes[1]);
            if($line == "COMMIT\n") break;
            if($line == "ROLLBACK\n") {
                echo "FAILED\n";
                $succeeded = FALSE;
                break;
            }
        }
        
        $errors = "";
        while(TRUE) {
            $read_pipes = array($psql_pipes[2]);
            $write_pipes = array();
            $exception_pipes = array();
            stream_select($read_pipes, $write_pipes, $exception_pipes, 0);
            if(!count($read_pipes)) break;
            $line = fgets($psql_pipes[2]);
            if($line == "ERROR:  current transaction is aborted, commands ignored until end of transaction block\n") continue;
            if(preg_match("/^ERROR:  /", $line)) $errors .= $line;
        }
        
        echo $errors;
        
        fclose($psql_pipes[0]);
        fclose($psql_pipes[1]);

        proc_close($psql);
    }

    if($succeeded) {
        echo "Will apply " . count($sorted_migrations) . " patches.\n";

        foreach($sorted_migrations as $migration) {
            $psql_pipes = array();
            $psql = proc_open("psql -w --host=" . $hostname . " --port=" . $port . " --username=" . $user . " " . $database_name, array(array("pipe", "r"), array("pipe", "w"), array("pipe", "w")), $psql_pipes, NULL, array('PGPASSWORD' => $password));
    
            echo("\nApplying " . $migration . "...\n");
            if(!is_file($patch_directory_path . "/" . $migration . ".sql")) {
                echo "File does not exist.\n";
                echo "FAILED\n";
                $succeeded = FALSE;
                continue;
            }
            $sql = file_get_contents($patch_directory_path . "/" . $migration . ".sql");
            fwrite($psql_pipes[0], "BEGIN;\n");
            fwrite($psql_pipes[0], $sql . "\n");
            if($rollback)
            {
                fwrite($psql_pipes[0], "ROLLBACK;\n");
            }
            else
            {
                fwrite($psql_pipes[0], "COMMIT;\n");
            }
            fflush($psql_pipes[0]);
    
            while(TRUE) {
                $read_pipes = array($psql_pipes[1]);
                $write_pipes = array();
                $exception_pipes = array();
                stream_select($read_pipes, $write_pipes, $exception_pipes, 0);
                $line = fgets($psql_pipes[1]);
            
                if($line == "COMMIT\n") break;
                if($line == "ROLLBACK\n") {
                    if($rollback)
                    {
                        echo "ROLLED BACK\n";
                    }
                    else
                    {
                        echo "FAILED\n";
                    }
                    $succeeded = FALSE;
                    break;
                }
                if($verbose) {
                    echo $line;
                }
            }
            
            $errors = "";
            while(TRUE) {
                $read_pipes = array($psql_pipes[2]);
                $write_pipes = array();
                $exception_pipes = array();
                stream_select($read_pipes, $write_pipes, $exception_pipes, 0);
                if(!count($read_pipes)) break;
                $line = fgets($psql_pipes[2]);
                if($line == "ERROR:  current transaction is aborted, commands ignored until end of transaction block\n") continue;
                if(preg_match("/^ERROR:  /", $line)) $errors .= $line;
            }
            
            echo $errors;
            
            fclose($psql_pipes[0]);
            fclose($psql_pipes[1]);

            proc_close($psql);
        }
    }
}

if($succeeded) {
    echo "\nDone with migrations.\n";
}

if($create_database && $drop_database) {
    echo "Dropping database " . $database_name . ".\n";
    
    $psql_pipes = array();
    $psql = proc_open("psql -w --host=" . $hostname . " --port=" . $port . " --username=" . $user . " postgres", array(array("pipe", "r"), array("pipe", "w"), STDOUT), $psql_pipes, NULL, array('PGPASSWORD' => $password));
    
    fwrite($psql_pipes[0], "DROP DATABASE " . $database_name . ";\n");
    fflush($psql_pipes[0]);
    
    fclose($psql_pipes[0]);
    fclose($psql_pipes[1]);
    
    proc_close($psql);
}
