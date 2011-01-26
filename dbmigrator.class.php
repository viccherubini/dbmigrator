<?php

declare(encoding='UTF-8');

class dbmigrator {

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

	private $set_up_method = 'set_up';
	private $tear_down_method = 'tear_down';

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
		$utc_timestamp = $this->get_utc_time();

		$migration_file_path = $this->build_migration_script_name($utc_timestamp, $script_name);
		$migration_class_name = $this->build_migration_script_class($utc_timestamp, $script_name);

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
			fwrite($fh, "\$__migration_object = new {$migration_class_name};" . PHP_EOL . PHP_EOL);
			fwrite($fh, "class {$migration_class_name} {" . PHP_EOL . PHP_EOL);
			fwrite($fh, "\tpublic \$utc_timestamp = '{$utc_timestamp}';" . PHP_EOL . PHP_EOL);
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
				$utc_timestamp = current(explode('-', $snapshotFile));

				$snapshotsOnDisk[$utc_timestamp] = $snapshotFile;
			}
		}

		foreach ($snapshotsOnDisk as $snapshotFile) {
			// Open the file
			$snapshotContents = file_get_contents($migration_path . $snapshotFile);
			$snapshots = explode(PHP_EOL, $snapshotContents);
		}
	}*/

	public function update($utc_timestamp=-1) {
		$this->build_change_log_table();
		$pdo = $this->get_pdo();

		$latest_timestamp = $this->find_latest_timestamp();
		if (-1 == $utc_timestamp) {
			$utc_timestamp = $this->get_utc_time();
		}

		if ($utc_timestamp < $latest_timestamp) {
			return false;
		}

		$migrations_on_disk = $this->build_migrations_on_disk();
		$migration_path = $this->get_migration_path();

		$migration_scripts = array();
		$migration_scripts = array_filter($migrations_on_disk, function($m) use ($utc_timestamp, $latest_timestamp) {
			return ($m['timestamp'] <= $utc_timestamp && $m['timestamp'] > $latest_timestamp);
		});

		$migrations_executed_successfully = $this->execute_migration_scripts($migration_scripts, $this->set_up_method);
		return $migrations_executed_successfully;
	}

	public function rollback($utc_timestamp=0) {
		$this->build_change_log_table();
		$pdo = $this->get_pdo();

		// Find the latest timestamp and if it's less than the utc_timestamp
		// we're rolling back to, just return false
		$latest_timestamp = $this->find_latest_timestamp();
		if ($utc_timestamp > $latest_timestamp) {
			return false;
		}

		$migrations_in_db = $this->build_migrations_in_db();
		$migration_path = $this->get_migration_path();

		$migration_scripts = array();
		$migration_scripts = array_filter($migrations_in_db, function($m) use ($utc_timestamp, $latest_timestamp) {
			return ($m['timestamp'] > $utc_timestamp && $m['timestamp'] <= $latest_timestamp);
		});

		// Need to execute the scripts in reverse order
		krsort($migration_scripts);

		$migrations_executed_successfully = $this->execute_migration_scripts($migration_scripts, $this->tear_down_method);
		return $migrations_executed_successfully;
	}

	public function set_migration_path($migration_path) {
		$path_length = strlen($migration_path);
		if ($path_length > 0 && $migration_path[$path_length-1] != DIRECTORY_SEPARATOR) {
			$migration_path .= DIRECTORY_SEPARATOR;
		}
		$this->migration_path = $migration_path;
		return $this;
	}

	public function getCurrentVersion() {
		return $this->currentVersion;
	}

	public function get_messages() {
		return $this->messages;
	}

	public function get_migrations_in_db() {
		return $this->migrations_in_db;
	}

	public function get_migrations_on_disk() {
		return $this->migrations_on_disk;
	}

	public function get_migration_path() {
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
















	private function execute_migration_scripts($migration_scripts, $migration_method) {
		$pdo = $this->get_pdo();

		$migration_scripts_run_successfully = true;
		if (0 === count($migration_scripts)) {
			return $migration_scripts_run_successfully;
		}

		$migration_path = $this->get_migration_path();
		foreach ($migration_scripts as $migration) {
			$migration_file = $migration['script'];
			$migration_file_path = $migration_path . $migration_file;

			$query = NULL;
			if (is_file($migration_file_path)) {
				require_once($migration_file_path);

				if (isset($__migration_object) && is_object($__migration_object) && method_exists($__migration_object, $migration_method)) {
					$migration_script_class = get_class($__migration_object);
					$query = $__migration_object->$migration_method();
				}
			}

			$executed = false;
			if (!empty($query)) {
				$statement = $pdo->query($query);
				if (false !== $statement) {
					$executed = true;
					$statement->closeCursor();
				}
			}

			if (false === $executed) {
				$migration_scripts_run_successfully = false;
				break;
			}

			if ($migration['change_id'] > 0) {
				$statement = $pdo->prepare("DELETE FROM ".$this->change_log_table." WHERE change_id = :change_id");
				$statement->bindValue(':change_id', $migration['change_id'], \PDO::PARAM_INT);
				$statement->execute();
			} else {
				$set_up_query = NULL;
				$tear_down_query = NULL;

				if (method_exists($__migration_object, $this->set_up_method)) {
					$set_up_query = $__migration_object->{$this->set_up_method}();
				}

				if (method_exists($__migration_object, $this->tear_down_method)) {
					$tear_down_query = $__migration_object->{$this->tear_down_method}();
				}

				$statement = $pdo->prepare("INSERT INTO ".$this->change_log_table."(created, timestamp, script, set_up, tear_down) VALUES(NOW(), :timestamp, :migration_file, :set_up_query, :tear_down_query)");
				$statement->bindValue(':timestamp', $__migration_object->utc_timestamp, \PDO::PARAM_STR);
				$statement->bindValue(':migration_file', $migration_file, \PDO::PARAM_STR);
				$statement->bindValue(':set_up_query', $set_up_query, \PDO::PARAM_STR);
				$statement->bindValue(':tear_down_query', $tear_down_query, \PDO::PARAM_STR);
				$statement->execute();
			}

			// Reset to NULL so the last script doesn't get run on successive tries
			// if the object is invalid.
			$__migration_object = NULL;
		}

		$pdo->query("OPTIMIZE TABLE ".$this->change_log_table);
		return $migration_scripts_run_successfully;
	}
















	protected function build_migrations_in_db() {
		$pdo = $this->get_pdo();

		$migrations = $pdo->query("SELECT change_id, timestamp, script, set_up, tear_down FROM ".$this->change_log_table." ORDER BY timestamp ASC")
			->fetchAll(PDO::FETCH_ASSOC);

		if (is_array($migrations)) {
			foreach ($migrations as $migration) {
				$this->migrations_in_db[] = $migration;
			}
		}

		return $this->migrations_in_db;
	}

	protected function build_migrations_on_disk() {
		$migration_path = $this->get_migration_path();

		$migrations = glob($migration_path.'*'.$this->ext);
		if (is_array($migrations)) {
			foreach ($migrations as $migration) {
				$migration_file = trim(basename($migration));
				$utc_timestamp = current(explode('-', $migration_file));

				$this->migrations_on_disk[$utc_timestamp] = array(
					'change_id' => 0,
					'timestamp' => $utc_timestamp,
					'script' => $migration_file,
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

		$migration_file = implode('-', array($utc_timestamp, $script_name)).'.'.$this->ext;
		$migration_file_path = $migration_path . $migration_file;

		return $migration_file_path;
	}

	protected function sanitize_migration_script_name($script_name) {
		$script_name = preg_replace('/[^a-z0-9\-\.]/i', '-', $script_name);
		$script_name = preg_replace('/\-{2,}/', NULL, $script_name);
		$script_name = trim($script_name);

		return $script_name;
	}

	protected function build_migration_script_class($utc_timestamp, $class_name) {
		$class_name = $this->sanitize_class_name($class_name);
		$class_name = implode('_', array($this->class_prefix, $utc_timestamp, $class_name));
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
		$utc_time = date('YmdHis', $utc_timestamp);

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