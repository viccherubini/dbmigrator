<?php declare(encoding='UTF-8');

require_once(__DIR__.'/dbmigrator.methods.php');

class dbmigrator {

	private $latest_timestamp = -1;

	private $pdo = null;
	private $object_path = null;

	private $migrations_in_db = array();
	private $migrations_on_disk = array();
	private $snapshots_on_disk = array();
	private $snapshot_migrations_on_disk = array();
	
	private $change_log_table_search_scripts = array();
	private $change_log_table_create_scripts = array();

	private $class_prefix = 'Migration';
	private $db_type = 'mysql';
	private $migration_ext = '.php';
	private $snapshot_ext = '.snap';
	private $snapshots_file = 'snapshots';

	private $set_up_method = 'set_up';
	private $tear_down_method = 'tear_down';

	const sql_create_table = 'create-table';
	const sql_create_database = 'create-database';

	
	public function __construct($db_host, $db_name, $db_user, $db_password, $db_type, $object_path) {
		$this->db_type = strtolower(DB_TYPE);
		$dsn = $this->db_type.':host='.$db_host.';dbname='.$db_name.';user='.$db_user.';password='.$db_password;
		
		$pdo = new \PDO($dsn, $db_user, $db_password);
		if ('mysql' === $db_type) {
			$pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, 1);
		}
		
		$this->change_log_table_search_scripts = array(
			'mysql' => file_get_contents(__DIR__.'/../sql/mysql.show_tables.sql'),
			'pgsql' => file_get_contents(__DIR__.'/../sql/pgsql.show_tables.sql')
		);
		
		$this->change_log_table_create_scripts = array(
			'mysql' => file_get_contents(__DIR__.'/../sql/mysql.create_table.sql'),
			'pgsql' => file_get_contents(__DIR__.'/../sql/pgsql.create_table.sql')
		);

		$this->attach_pdo($pdo)
			->set_object_path($object_path)
			->build_change_log_table()
			->build_migrations_in_db()
			->build_migrations_on_disk()
			->build_snapshots_on_disk()
			->build_snapshot_migrations_on_disk();
	}

	public function __destruct() {
		$this->pdo = null;
	}



	public function attach_pdo(\PDO $pdo) {
		$this->pdo = $pdo;
		return $this;
	}



	public function create($script_name) {
		$object_path = $this->get_object_path();
		$utc_timestamp = $this->get_utc_time();

		$sanitized_script_name = $this->sanitize_input_value($script_name);
		$migration_file = $this->build_file_name($utc_timestamp, $sanitized_script_name, $this->migration_ext);
		$migration_file_path = $object_path . $migration_file;
		$migration_class_name = $this->build_migration_script_class($utc_timestamp, $script_name);

		$tear_down_code = null;
		if (0 === stripos($script_name, self::sql_create_table)) {
			$table_name = substr($script_name, strlen(self::sql_create_table)+1);
			$table_name = str_replace('-', '_', $table_name);

			$tear_down_code = "DROP TABLE IF EXISTS {$table_name};";
		} elseif (0 === stripos($script_name, self::sql_create_database)) {
			$db_name = substr($script_name, strlen(self::sql_create_database)+1);
			$db_name = str_replace('-', '_', $db_name);

			$tear_down_code = "DROP DATABASE IF EXISTS {$db_name};";
		}

		$now = date('c');
		$fh = fopen($migration_file_path, 'w');
			fwrite($fh, "<?php".PHP_EOL.PHP_EOL);
			fwrite($fh, "// This file was automatically generated, ADD YOUR QUERIES BELOW.".PHP_EOL);
			fwrite($fh, "// CREATION DATE: {$now}".PHP_EOL.PHP_EOL);
			fwrite($fh, "\$__migration_object = new {$migration_class_name};".PHP_EOL.PHP_EOL);
			fwrite($fh, "class {$migration_class_name} {" .PHP_EOL.PHP_EOL);
			fwrite($fh, "\tpublic \$utc_timestamp = '{$utc_timestamp}';".PHP_EOL.PHP_EOL);
			fwrite($fh, "\tpublic function {$this->set_up_method}() {".PHP_EOL);
			fwrite($fh, "\t\treturn \"\";".PHP_EOL);
			fwrite($fh, "\t}".PHP_EOL.PHP_EOL);
			fwrite($fh, "\tpublic function {$this->tear_down_method}() {".PHP_EOL);
			fwrite($fh, "\t\treturn \"{$tear_down_code}\";".PHP_EOL);
			fwrite($fh, "\t}".PHP_EOL);
			fwrite($fh, "}");
		fclose($fh);

		return $migration_file;
	}

	public function snapshot($snapshot) {
		$snapshot_exists = false;
		$migrations = array();

		$sanitized_snapshot = $this->sanitize_input_value($snapshot);

		$snapshots_on_disk = $this->get_snapshots_on_disk();
		array_walk($snapshots_on_disk, function($v, $k) use(&$snapshot_exists, $sanitized_snapshot) {
			if ($v['snapshot'] === $sanitized_snapshot) {
				$snapshot_exists = true;
			}
		});

		if ($snapshot_exists) {
			return false;
		}

		$migrations_on_disk = $this->get_migrations_on_disk();
		foreach ($migrations_on_disk as $utc_timestamp => $migration) {
			$migrations[$utc_timestamp] = $migration['script'];
		}
		
		$snapshot_migrations_on_disk = $this->get_snapshot_migrations_on_disk();
		
		$migrations_to_be_snapshotted = array_diff($migrations, $snapshot_migrations_on_disk);

		if (count($migrations_to_be_snapshotted) > 0) {
			$utc_timestamp = $this->get_utc_time();
			$snapshots_on_disk[] = array(
				'script_timestamp' => $utc_timestamp,
				'snapshot' => $sanitized_snapshot,
				'migrations' => $migrations_to_be_snapshotted
			);
			
			$this->set_snapshots_on_disk($snapshots_on_disk)
				->write_snapshots_to_disk();
		}

		return true;
	}

	public function update($utc_timestamp=-1) {
		$pdo = $this->get_pdo();

		if (!is_numeric($utc_timestamp)) {
			// We're dealing with a snapshot, so get all snapshots from the start of time until this one
			// and get the utc time of the latest migration, then just use that. brilliant!
			$sanitized_snapshot = $this->sanitize_input_value($utc_timestamp);
			$snapshots_on_disk = $this->get_snapshots_on_disk();

			$found_snapshot = false;
			foreach ($snapshots_on_disk as $snapshot) {
				if ($snapshot['snapshot'] === $sanitized_snapshot) {
					// Get the last element of the migrations array in this snapshot.
					$found_snapshot = true;

					$utc_timestamps = array_keys($snapshot['migrations']);
					$utc_timestamp = end($utc_timestamps);
				}
			}

			if (!$found_snapshot) {
				return false;
			}
		}

		if (-1 == $utc_timestamp) {
			$utc_timestamp = $this->get_utc_time();
		}
		
		$latest_timestamp = $this->find_latest_timestamp_in_db();
		if ($utc_timestamp < $latest_timestamp) {
			return false;
		}

		$migrations_on_disk = $this->get_migrations_on_disk();

		$migration_scripts = array();
		$migration_scripts = array_filter($migrations_on_disk, function($mig) use ($utc_timestamp, $latest_timestamp) {
			return ($mig['script_timestamp'] <= $utc_timestamp && $mig['script_timestamp'] > $latest_timestamp);
		});

		$migrations_executed_successfully = $this->execute_migration_scripts($migration_scripts, $this->set_up_method);
		return $migrations_executed_successfully;
	}

	public function rollback($utc_timestamp=0) {
		$pdo = $this->get_pdo();

		if (!is_numeric($utc_timestamp)) {
			// start from the latest snapshot to the first one, if a snapshot is found
			// get the first element in the migrations array as the utc timestamp.
			$sanitized_snapshot = $this->sanitize_input_value($utc_timestamp);
			$snapshots_on_disk = $this->get_snapshots_on_disk();

			krsort($snapshots_on_disk);

			$found_snapshot = false;
			foreach ($snapshots_on_disk as $snapshot) {
				if ($snapshot['snapshot'] === $sanitized_snapshot) {
					// Get the last element of the migrations array in this snapshot.
					$found_snapshot = true;

					reset($snapshot['migrations']);
					$utc_timestamp = key($snapshot['migrations']);
				}
			}

			if (!$found_snapshot) {
				return false;
			}
		}

		// Find the latest timestamp and if it's less than the utc_timestamp
		// we're rolling back to, just return false
		$latest_timestamp = $this->find_latest_timestamp_in_db();
		if ($utc_timestamp > $latest_timestamp) {
			return false;
		}

		$migrations_in_db = $this->get_migrations_in_db();

		$migration_scripts = array();
		$migration_scripts = array_filter($migrations_in_db, function($m) use ($utc_timestamp, $latest_timestamp) {
			return ($m['script_timestamp'] >= $utc_timestamp && $m['script_timestamp'] <= $latest_timestamp);
		});

		// Need to execute the scripts in reverse order
		krsort($migration_scripts);

		$migrations_executed_successfully = $this->execute_migration_scripts($migration_scripts, $this->tear_down_method);
		return $migrations_executed_successfully;
	}
	
	

	public function set_object_path($object_path) {
		$object_path_length = strlen($object_path);
		if ($object_path_length > 0 && $object_path[$object_path_length-1] != DIRECTORY_SEPARATOR) {
			$object_path .= DIRECTORY_SEPARATOR;
		}
		$this->object_path = $object_path;
		return $this;
	}

	public function set_migrations_in_db(array $migrations_in_db) {
		ksort($migrations_in_db);
		$this->migrations_in_db = $migrations_in_db;
		return $this;
	}

	public function set_migrations_on_disk(array $migrations_on_disk) {
		ksort($migrations_on_disk);
		$this->migrations_on_disk = $migrations_on_disk;
		return $this;
	}

	public function set_snapshots_on_disk(array $snapshots_on_disk) {
		$this->snapshots_on_disk = $snapshots_on_disk;
		return $this;
	}

	public function set_snapshot_migrations_on_disk(array $snapshot_migrations_on_disk) {
		ksort($snapshot_migrations_on_disk);
		$this->snapshot_migrations_on_disk = $snapshot_migrations_on_disk;
		return $this;
	}



	public function get_migrations_in_db() {
		return $this->migrations_in_db;
	}

	public function get_migrations_on_disk() {
		return $this->migrations_on_disk;
	}

	public function get_snapshots_on_disk() {
		return $this->snapshots_on_disk;
	}

	public function get_snapshot_migrations_on_disk() {
		return $this->snapshot_migrations_on_disk;
	}

	public function get_object_path() {
		return $this->object_path;
	}

	public function get_pdo() {
		return $this->pdo;
	}



	private function execute_migration_scripts($migration_scripts, $migration_method) {
		$pdo = $this->get_pdo();

		$migration_scripts_run_successfully = true;
		if (0 === count($migration_scripts)) {
			return $migration_scripts_run_successfully;
		}

		$object_path = $this->get_object_path();
		foreach ($migration_scripts as $migration) {
			$migration_file = $migration['script'];
			$migration_file_path = $object_path.$migration_file;
			
			message("Executing migration script {$migration_file}.");

			$query = null;
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

			if ($migration['id'] > 0) {
				$statement = $pdo->prepare("DELETE FROM _schema_changelog WHERE id = :id");
				$statement->bindValue('id', $migration['id'], \PDO::PARAM_INT);
				$statement->execute();
			} else {
				$set_up_query = null;
				$tear_down_query = null;

				if (method_exists($__migration_object, $this->set_up_method)) {
					$set_up_query = $__migration_object->{$this->set_up_method}();
				}

				if (method_exists($__migration_object, $this->tear_down_method)) {
					$tear_down_query = $__migration_object->{$this->tear_down_method}();
				}

				$statement = $pdo->prepare("INSERT INTO _schema_changelog(created, script_timestamp, script, set_up, tear_down) VALUES(NOW(), :script_timestamp, :migration_file, :set_up_query, :tear_down_query)");
				$statement->bindValue('script_timestamp', $__migration_object->utc_timestamp, \PDO::PARAM_STR);
				$statement->bindValue('migration_file', $migration_file, \PDO::PARAM_STR);
				$statement->bindValue('set_up_query', $set_up_query, \PDO::PARAM_STR);
				$statement->bindValue('tear_down_query', $tear_down_query, \PDO::PARAM_STR);
				$statement->execute();
			}

			// Reset to null so the last script doesn't get run on successive tries
			// if the object is invalid.
			$__migration_object = null;
		}

		return $migration_scripts_run_successfully;
	}

	private function build_migration_script_class($utc_timestamp, $class_name) {
		$class_name = $this->sanitize_class_name($class_name);
		$class_name = implode('_', array($this->class_prefix, $utc_timestamp, $class_name));
		return $class_name;
	}

	private function build_migrations_in_db() {
		$pdo = $this->get_pdo();

		$migrations_in_db = array();
		$migrations = $pdo->query('SELECT id, script_timestamp, script, set_up, tear_down FROM _schema_changelog ORDER BY script_timestamp ASC')
			->fetchAll(PDO::FETCH_ASSOC);
			
		if (is_array($migrations)) {
			foreach ($migrations as $migration) {
				$migrations_in_db[$migration['script_timestamp']] = $migration;
			}
		}

		$this->set_migrations_in_db($migrations_in_db);
		return $this;
	}

	private function build_migrations_on_disk() {
		$object_path = $this->get_object_path();

		$migrations_on_disk = array();
		$migrations = glob($object_path.'*'.$this->migration_ext);
		if (is_array($migrations)) {
			foreach ($migrations as $migration) {
				$migration_file = trim(basename($migration));
				$utc_timestamp = current(explode('-', $migration_file));

				$migrations_on_disk[$utc_timestamp] = array(
					'id' => 0,
					'script_timestamp' => $utc_timestamp,
					'script' => $migration_file,
					'set_up' => null,
					'tear_down' => null
				);
			}
		}

		$this->set_migrations_on_disk($migrations_on_disk);
		return $this;
	}







	
	private function build_snapshots_file_path() {
		$object_path = $this->get_object_path();

		$snapshots_file_path = $object_path.$this->snapshots_file;
		return $snapshots_file_path;
	}
	
	private function build_snapshots_on_disk() {
		$snapshots_on_disk = array();
		
		$snapshots_file_path = $this->build_snapshots_file_path();
		if (is_file($snapshots_file_path)) {
			$existing_snapshots = trim(file_get_contents($snapshots_file_path));
			$snapshots_on_disk = unserialize($existing_snapshots);
		}

		$this->set_snapshots_on_disk($snapshots_on_disk);
		return $this;
	}

	private function build_snapshot_migrations_on_disk() {
		$snapshot_migrations_on_disk = array();
		$snapshots_on_disk = $this->get_snapshots_on_disk();

		foreach ($snapshots_on_disk as $snapshot) {
			foreach ($snapshot['migrations'] as $utc_timestamp => $snapshot_migration) {
				$snapshot_migrations_on_disk[$utc_timestamp] = $snapshot_migration;
			}
		}

		$this->set_snapshot_migrations_on_disk($snapshot_migrations_on_disk);
		return $this;
	}

	private function write_snapshots_to_disk() {
		$snapshots_file_path = $this->build_snapshots_file_path();
		file_put_contents($snapshots_file_path, serialize($this->snapshots_on_disk));
		return true;
	}







	

	private function find_latest_timestamp_in_db() {
		$sql = "SELECT MAX(script_timestamp) AS latest_timestamp FROM _schema_changelog";
		$this->latest_timestamp = $this->get_pdo()
			->query($sql)
			->fetchColumn(0);
		
		return $this->latest_timestamp;
	}





	private function build_file_name($utc_timestamp, $file_name, $extension) {
		$file = implode('-', array($utc_timestamp, $file_name)).$extension;
		return $file;
	}

	private function sanitize_input_value($value) {
		$value = preg_replace('/[^a-z0-9\-\.]/i', '-', $value);
		$value = preg_replace('/\-{2,}/', null, $value);
		$value = trim($value);
		return $value;
	}

	private function sanitize_class_name($class_name) {
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
		if (array_key_exists($this->db_type, $this->change_log_table_create_scripts)) {
			$pdo = $this->get_pdo();

			$tables = $pdo->query($this->change_log_table_search_scripts[$this->db_type])
				->fetchAll(PDO::FETCH_ASSOC);
			$install_table = true;

			foreach ($tables as $table) {
				$table_name = $table;
				if (is_array($table)) {
					$table_name = current($table);
				}
				
				if ($table_name === '_schema_changelog') {
					$install_table = false;
					break;
				}
			}

			if ($install_table) {
				$pdo->exec($this->change_log_table_create_scripts[$this->db_type]);
			}
		}
		return $this;
	}

}