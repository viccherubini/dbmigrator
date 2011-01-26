<?php

declare(encoding='UTF-8');

class DbMigrator {

	private $latest_timestamp = -1;

	private $pdo = NULL;
	private $migration_path = NULL;

	private $messages = array();
	private $migrations_in_db = array();
	private $migrations_on_disk = array();

	private $change_log_table = '_schema_changelog';
	private $class_prefix = 'Migration';
	private $db_type = 'MYSQL';
	private $ext = 'php';

	private $set_up_method = 'setUp';
	private $tear_down_method = 'tearDown';

	const MESSAGE_SUCCESS = 'success';
	const MESSAGE_ERROR = 'error';

	const SQL_CREATE_TABLE = 'create-table';
	const SQL_CREATE_DATABASE = 'create-database';

	public function __construct($db_type) {
		$this->messages = array();
		$this->db_type = strtoupper($db_type);
	}

	public function __destruct() {

	}

	public function attach_pdo(PDO $pdo) {
		$this->pdo = $pdo;
		return $this;
	}

	public function create($script_name) {
		$timestamp = $this->get_utc_time();

		$migration_file_path = $this->build_migration_script_name($timestamp, $script_name);
		$migration_class_name = $this->build_migration_script_class($timestamp, $script_name);

		$tear_down_code = NULL;
		if (0 === stripos($script_name, self::SQL_CREATE_TABLE)) {
			$table_name = substr($script_name, strlen(self::SQL_CREATE_TABLE)+1);
			$table_name = str_replace('-', '_', $table_name);

			$tear_down_code = "DROP TABLE IF EXISTS ".$table_name;
		} elseif (0 === stripos($script_name, self::SQL_CREATE_DATABASE)) {
			$db_name = substr($script_name, strlen(self::SQL_CREATE_DATABASE)+1);
			$db_name = str_replace('-', '_', $db_name);

			$tear_down_code = "DROP DATABASE IF EXISTS ".$db_name;
		}

		$now = date('c');

		$fh = fopen($migration_file_path, 'w');
			fwrite($fh, "<?php" . PHP_EOL . PHP_EOL);
			fwrite($fh, "// This file was automatically generated, ADD YOUR QUERIES BELOW." . PHP_EOL);
			fwrite($fh, "// CREATION DATE: {$now}" . PHP_EOL . PHP_EOL);
			fwrite($fh, "\$__migration_object = new {$class_name};" . PHP_EOL . PHP_EOL);
			fwrite($fh, "class {$class_name} {" . PHP_EOL . PHP_EOL);
			fwrite($fh, "\tpublic \$timestamp = '{$timestamp}';" . PHP_EOL . PHP_EOL);
			fwrite($fh, "\tpublic function {$this->set_up_method}() {" . PHP_EOL);
			fwrite($fh, "\t\treturn \"\";" . PHP_EOL);
			fwrite($fh, "\t}" . PHP_EOL . PHP_EOL);
			fwrite($fh, "\tpublic function {$this->tear_down_method}() {" . PHP_EOL);
			fwrite($fh, "\t\treturn \"{$tear_down_code}\";" . PHP_EOL);
			fwrite($fh, "\t}" . PHP_EOL);
			fwrite($fh, "}");
		fclose($fh);

		$migration_script = basename($migration_file_path);
		//$this->success("New migration script, {$migrationScript}, was successfully created.");

		return $migration_script;
	}

	/*public function createSnapshot($snapshot) {
		$migrations_on_disk = $this->buildmigrations_on_disk();
		$migrationsInSnapshots = array()
		$snapshotsOnDisk = array();

		$migration_path = $this->getmigration_path();
		$snapshots = glob($migration_path . "*.snap");
		if (is_array($snapshots)) {
			foreach ($snapshots as $snapshot) {
				$snapshotFile = trim(basename($snapshot));
				$timestamp = current(explode('-', $snapshotFile));

				$snapshotsOnDisk[$timestamp] = $snapshotFile;
			}
		}

		foreach ($snapshotsOnDisk as $snapshotFile) {
			// Open the file
			$snapshotContents = file_get_contents($migration_path . $snapshotFile);
			$snapshots = explode(PHP_EOL, $snapshotContents);
		}
	}*/

	public function update($timestamp=-1) {
		$this->build_change_log_table();
		$pdo = $this->get_pdo();

		$migrations_on_disk = $this->build_migrations_on_disk();
		$migrations_in_db = $this->build_migrations_in_db();
		$migration_path = $this->get_migration_path();
		$latest_timestamp = $this->find_latest_timestamp();

		$migration_method = NULL;
		$migration_scripts = array();

		if (-1 == $timestamp) {
			$timestamp = $this->get_utc_time();
		}

		$migrations_on_disk_count = count($migrations_on_disk);
		$migrations_in_db_count = count($migrations_in_db);

		if ($timestamp <= $latest_timestamp) {
			//$this->message("ROLLING BACK TO TIMESTAMP {$timestamp}");

			// PHP5.3 Goodness
			$migration_scripts = array_filter($migrations_in_db, function($m) use ($timestamp, $latest_timestamp) {
				return ($m['timestamp'] > $latest_timestamp && $m['timestamp'] <= $latest_timestamp);
			});

			// Need to execute the scripts in reverse order
			krsort($migration_scripts);
			$migration_method = $this->tear_down_method;
		} elseif ($timestamp > $latest_timestamp) {
			//$this->message("UPDATING TO TIMESTAMP {$timestamp}");

			// PHP5.3 Goodness
			$migration_scripts = array_filter($migrations_on_disk, function($m) use ($timestamp, $latest_timestamp) {
				return ($m['timestamp'] <= $timestamp && $m['timestamp'] > $latest_timestamp);
			});

			$migration_method = $this->set_up_method;
		} else {
			$this->message("You are already at the latest version of the database.");
			return false;
		}


print_r($migration_scripts);

		$error = false;
		$start_time = microtime(true);
		$execution_time = 0;

		if (false) {
		foreach ($migrationScriptList as $migration) {
			$migrationFile = $migration['script'];
			$migrationFilePath = $migration_path . $migrationFile;

			$query = NULL;
			if (is_file($migrationFilePath)) {
				require_once($migrationFilePath);

				if (is_object($__migration_object) && method_exists($__migration_object, $migration_method)) {
					$migrationClass = get_class($__migration_object);
					$query = $__migration_object->$migration_method();
				}
			} else {
				$query = $migration[$migration_method];
			}

			$executed = false;
			if (!empty($query)) {
				$pdoStatement = $pdo->query($query);
				if (false !== $pdoStatement) {
					$executed = true;
					$pdoStatement->closeCursor();
				}
			}

			if (false === $executed) {
				$errorInfo = $pdo->errorInfo();

				$this->error("##################################################");
				$this->error("FAILED TO EXECUTE: {$migrationFile}");
				if ( is_array($errorInfo) && count($errorInfo) > 2 ){
					$this->error("ERROR: {$errorInfo[2]}");
				} else {
					$this->error("ERROR: Empty query");
				}
				$this->error("##################################################");

				$error = true;
				break;
			} else {
				$this->success(sprintf("%01.3fs - %-10s", $execTime, $migrationFile));
			}

			if ($migration['change_id'] > 0) {
				$pdo->prepare("DELETE FROM {$this->change_log_table} WHERE change_id = ?")
					->execute(array($migration['change_id']));
			} else {
				$setUpQuery = NULL;
				$tearDownQuery = NULL;

				if ( method_exists($__migration_object, $this->set_up_method) ) {
					$setUpQuery = $__migration_object->{$this->set_up_method}();
				}

				if ( method_exists($__migration_object, $this->tear_down_method) ) {
					$tearDownQuery = $__migration_object->{$this->tear_down_method}();
				}

				$pdo->prepare("INSERT INTO {$this->change_log_table} VALUES(NULL, NOW(), ?, ?, ?, ?)")
					->execute(array($__migration_object->timestamp, $migrationFile, $setUpQuery, $tearDownQuery));
			}

			$execTime = microtime(true);
			$execTime -= $startTime;
		}

		$pdo->query("OPTIMIZE TABLE {$this->change_log_table}");

		if ( $error ) {
			$this->error("FAILURE! DbMigrator COULD NOT UPDATE TO THE LATEST VERSION OF THE DATABASE!");
		} else {
			$this->success("Successfully migrated the database to timestamp {$timestamp}.");
			$this->success(sprintf("Migration took %01.3f seconds.", $execTime));
		}
		}

		return (!$error);
	}

	public function rollback($timestamp) {


	}

	public function setmigration_path($migration_path) {
		$pathLength = strlen($migration_path);
		if ($pathLength > 0 && $migration_path[$pathLength-1] != DIRECTORY_SEPARATOR) {
			$migration_path .= DIRECTORY_SEPARATOR;
		}

		$this->migration_path = $migration_path;
		return $this;
	}

	public function getCurrentVersion() {
		return $this->currentVersion;
	}

	public function getmessages() {
		return $this->messages;
	}

	public function getmigrations_in_db() {
		return $this->migrations_in_db;
	}

	public function getmigrations_on_disk() {
		return $this->migrations_on_disk;
	}

	public function getmigration_path() {
		return $this->migration_path;
	}

	public function get_pdo() {
		return $this->pdo;
	}

	public function error($message) {
		echo "  ## \033[1;31m{$message}\033[m", PHP_EOL;
	}

	public function success($message) {
		echo "  ## \033[1;32m{$message}\033[m", PHP_EOL;
	}

	public function message($message) {
		echo "  ## \033[1;34m{$message}\033[m", PHP_EOL;
	}








	protected function buildmigrations_in_db() {
		$pdo = $this->get_pdo();

		$sql = "SELECT change_id, timestamp, script, set_up, tear_down FROM {$this->change_log_table} ORDER BY timestamp ASC";
		$migrations = $pdo->query($sql)
			->fetchAll(PDO::FETCH_ASSOC);

		if ( is_array($migrations) ) {
			foreach ( $migrations as $migration ) {
				$this->migrations_in_db[] = $migration;
			}
		}

		return $this->migrations_in_db;
	}

	protected function buildmigrations_on_disk() {
		$migration_path = $this->getmigration_path();

		$migrations = glob($migration_path . "*.{$this->ext}");
		if (is_array($migrations)) {
			foreach ($migrations as $migration) {
				$migrationFile = trim(basename($migration));
				$timestamp = current(explode('-', $migrationFile));

				$this->migrations_on_disk[$timestamp] = array(
					'change_id' => 0,
					'timestamp' => $timestamp,
					'script' => $migrationFile,
					'set_up' => NULL,
					'tear_down' => NULL
				);
			}
		}

		ksort($this->migrations_on_disk);

		return $this->migrations_on_disk;
	}

	protected function find_latest_timestamp() {
		$pdo = $this->get_pdo();

		$this->latest_timestamp = $pdo->query("SELECT MAX(timestamp) AS latest_timestamp FROM ".$this->change_log_table)->fetchColumn(0);
		return $this->latest_timestamp;
	}

	protected function build_migration_script_name($utc_timestamp, $script_name) {
		$migration_path = $this->get_migration_path();
		$script_name = $this->sanitize_migration_script_name($script_name);

		$migration_file = implode('-', array($timestamp, $script_name)).'.'.$this->ext;
		$migration_file_path = $migration_path . $migration_file;

		return $migration_file_path;
	}

	protected function sanitize_migration_script_name($script_name) {
		$script_name = preg_replace('/[^a-z0-9\-\.]/i', '-', $script_name);
		$script_name = preg_replace('/\-{2,}/', NULL, $script_name);
		$script_name = trim($script_name);

		return $script_name;
	}

	protected function build_migration_script_class($timestamp, $class_name) {
		$class_name = $this->sanitize_class_name($class_name);
		$class_name = implode('_', array($this->class_prefix, $timestamp, $class_name));
		return $class_name;
	}

	protected function sanitize_class_name($class_name) {
		$class_name = str_replace('-', ' ', $class_name);
		$class_name = ucwords($class_name);
		$class_name = str_replace(' ', '_', $class_name);
		return $class_name;
	}





	private function get_utc_time() {
		$utc_timestamp = (time() + (-1 * (int)date('Z')));
		$utc_time = date('YmdHisu', $utc_timestamp);

		return $utc_time;
	}

	private function build_change_log_table() {
		$method = __FUNCTION__ . '_' . $this->db_type;
		if (method_exists($this, $method)) {
			$this->$method();
		}
		return $this;
	}

	private function build_change_log_table_MYSQL() {
		$pdo = $this->get_pdo();

		$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_ASSOC);
		$install_table = true;

		foreach ($tables as $table) {
			$table_name = current($table);
			if ($table_name == $this->change_log_table) {
				$install_table = false;
			}
		}

		if ($install_table) {
			$table_sql = "CREATE TABLE ".$this->change_log_table." (
					change_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
					created DATETIME NOT NULL,
					timestamp VARCHAR(16) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
					script VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
					set_up TEXT CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
					tear_down TEXT CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL
				) ENGINE = MYISAM CHARACTER SET utf8 COLLATE utf8_unicode_ci;";
			$pdo->exec($table_sql);
		}
		return true;
	}

	private function buildchange_log_tablePOSTGRES() {
		throw new \Exception(__CLASS__ . '::' . __FUNCTION__ . ' not yet implemented.');
	}

	private function buildchange_log_tableSQLITE() {
		throw new \Exception(__CLASS__ . '::' . __FUNCTION__ . ' not yet implemented.');
	}

}