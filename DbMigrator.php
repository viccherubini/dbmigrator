<?php

declare(encoding='UTF-8');

class DbMigrator {

	private $latestTimestamp = -1;

	private $pdo = NULL;
	private $migrationPath = NULL;

	private $messageList = array();
	private $migrationsInDb = array();
	private $migrationsOnDisk = array();

	private $changeLogTable = '_schema_changelog';
	private $classPrefix = 'Migration';
	private $dbType = 'MYSQL';
	private $ext = 'php';
	private $setUpMethod = 'setUp';
	private $tearDownMethod = 'tearDown';

	const MESSAGE_SUCCESS = 'success';
	const MESSAGE_ERROR = 'error';

	const SQL_CREATE_TABLE = 'create-table';
	const SQL_CREATE_DATABASE = 'create-database';

	public function __construct($dbType) {
		$this->messageList = array();
		$this->dbType = strtoupper($dbType);
	}

	public function __destruct() {

	}

	public function attachPdo(PDO $pdo) {
		$this->pdo = $pdo;
		return $this;
	}

	public function create($scriptName) {
		$timestamp = (microtime(true)*10000);

		$migrationFilePath = $this->buildMigrationScriptFileName($timestamp, $scriptName);
		$className = $this->buildMigrationScriptClassName($timestamp, $scriptName);

		$tearDownCode = NULL;
		if ( 0 === stripos($scriptName, self::SQL_CREATE_TABLE) ) {
			$tableName = substr($scriptName, strlen(self::SQL_CREATE_TABLE)+1);
			$tableName = str_replace('-', '_', $tableName);

			$tearDownCode = "DROP TABLE IF EXISTS {$tableName}";
		} elseif ( 0 === stripos($scriptName, self::SQL_CREATE_DATABASE) ) {
			$dbName = substr($scriptName, strlen(self::SQL_CREATE_DATABASE)+1);
			$dbName = str_replace('-', '_', $dbName);

			$tearDownCode = "DROP DATABASE IF EXISTS {$dbName}";
		}

		$now = date('Y-m-d H:i:s');

		$fh = fopen($migrationFilePath, 'w');
			fwrite($fh, "<?php" . PHP_EOL . PHP_EOL);
			fwrite($fh, "// This file was automatically generated, ADD YOUR QUERIES BELOW." . PHP_EOL);
			fwrite($fh, "// CREATION DATE: {$now}" . PHP_EOL . PHP_EOL);
			fwrite($fh, "\$__migrationObject = new {$className};" . PHP_EOL . PHP_EOL);
			fwrite($fh, "class {$className} {" . PHP_EOL . PHP_EOL);
			fwrite($fh, "\tpublic \$timestamp = '{$timestamp}';" . PHP_EOL . PHP_EOL);
			fwrite($fh, "\tpublic function {$this->setUpMethod}() {" . PHP_EOL);
			fwrite($fh, "\t\treturn \"\";" . PHP_EOL);
			fwrite($fh, "\t}" . PHP_EOL . PHP_EOL);
			fwrite($fh, "\tpublic function {$this->tearDownMethod}() {" . PHP_EOL);
			fwrite($fh, "\t\treturn \"{$tearDownCode}\";" . PHP_EOL);
			fwrite($fh, "\t}" . PHP_EOL);
			fwrite($fh, "}");
		fclose($fh);

		$migrationScript = basename($migrationFilePath);
		$this->success("New migration script, {$migrationScript}, was successfully created.");

		return $migrationScript;
	}

	public function createSnapshot($snapshot) {
		$migrationsOnDisk = $this->buildMigrationsOnDisk();
		$migrationsInSnapshots = array()
		$snapshotsOnDisk = array();

		$migrationPath = $this->getMigrationPath();
		$snapshots = glob($migrationPath . "*.snap");
		if (is_array($snapshots)) {
			foreach ($snapshots as $snapshot) {
				$snapshotFile = trim(basename($snapshot));
				$timestamp = current(explode('-', $snapshotFile));

				$snapshotsOnDisk[$timestamp] = $snapshotFile;
			}
		}

		foreach ($snapshotsOnDisk as $snapshotFile) {
			// Open the file
			$snapshotContents = file_get_contents($migrationPath . $snapshotFile);
			$snapshots = explode(PHP_EOL, $snapshotContents);
		}

	}

	public function update($timestamp=-1) {
		$this->buildChangelogTable();

		$pdo = $this->getPdo();
		$migrationsOnDisk = $this->buildMigrationsOnDisk();
		$migrationsInDb = $this->buildMigrationsInDb();
		$migrationPath = $this->getMigrationPath();
		$latestTimestamp = $this->determineLatestTimestamp();

		$migrationMethod = NULL;
		$migrationScriptList = array();

		if (-1 == $timestamp) {
			$timestamp = (microtime(true)*10000);
		}

		$migrationsOnDiskCount = count($migrationsOnDisk);
		$migrationsInDbCount = count($migrationsInDb);

		if ($timestamp <= $latestTimestamp) {
			$this->message("ROLLING BACK TO TIMESTAMP {$timestamp}");

			// PHP5.3 Goodness
			//$migrationScriptList = array_filter($migrationsInDb, function($m) use ($version, $currentVersion) {
			//	return ( $m['version'] > $version && $m['version'] <= $currentVersion );
			//});
			$migrationScriptList = array();
			foreach ( $migrationsInDb as $m ) {
				if ( $m['timestamp'] > $timestamp && $m['timestamp'] <= $latestTimestamp ) {
					$migrationScriptList[] = $m;
				}
			}

			// Need to execute the scripts in reverse order
			krsort($migrationScriptList);

			$migrationMethod = $this->tearDownMethod;
		} elseif ($timestamp > $latestTimestamp && $migrationsOnDiskCount != $migrationsInDbCount) {
			$this->message("UPDATING TO TIMESTAMP {$timestamp}");

			// PHP5.3 Goodness
			//$migrationScriptList = array_filter($migrationsOnDisk, function($m) use ($version, $currentVersion) {
			//	return ( $m['version'] <= $version && $m['version'] > $currentVersion );
			//});
			$migrationScriptList = array();
			foreach ( $migrationsOnDisk as $m ) {
				if ( $m['timestamp'] <= $timestamp && $m['timestamp'] > $latestTimestamp ) {
					$migrationScriptList[] = $m;
				}
			}

			$migrationMethod = $this->setUpMethod;
		} else {
			$this->message("You are already at the latest version of the database.");
			return false;
		}

		$error = false;
		$startTime = microtime(true);
		$execTime = 0;

		foreach ($migrationScriptList as $migration) {
			$migrationFile = $migration['script'];
			$migrationFilePath = $migrationPath . $migrationFile;

			$query = NULL;
			if (is_file($migrationFilePath)) {
				require_once($migrationFilePath);

				if (is_object($__migrationObject) && method_exists($__migrationObject, $migrationMethod)) {
					$migrationClass = get_class($__migrationObject);
					$query = $__migrationObject->$migrationMethod();
				}
			} else {
				$query = $migration[$migrationMethod];
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
				$pdo->prepare("DELETE FROM {$this->changeLogTable} WHERE change_id = ?")
					->execute(array($migration['change_id']));
			} else {
				$setUpQuery = NULL;
				$tearDownQuery = NULL;

				if ( method_exists($__migrationObject, $this->setUpMethod) ) {
					$setUpQuery = $__migrationObject->{$this->setUpMethod}();
				}

				if ( method_exists($__migrationObject, $this->tearDownMethod) ) {
					$tearDownQuery = $__migrationObject->{$this->tearDownMethod}();
				}

				$pdo->prepare("INSERT INTO {$this->changeLogTable} VALUES(NULL, NOW(), ?, ?, ?, ?)")
					->execute(array($__migrationObject->timestamp, $migrationFile, $setUpQuery, $tearDownQuery));
			}

			$execTime = microtime(true);
			$execTime -= $startTime;
		}

		$pdo->query("OPTIMIZE TABLE {$this->changeLogTable}");

		if ( $error ) {
			$this->error("FAILURE! DbMigrator COULD NOT UPDATE TO THE LATEST VERSION OF THE DATABASE!");
		} else {
			$this->success("Successfully migrated the database to timestamp {$timestamp}.");
			$this->success(sprintf("Migration took %01.3f seconds.", $execTime));
		}

		return (!$error);
	}

	public function setMigrationPath($migrationPath) {
		$pathLength = strlen($migrationPath);
		if ($pathLength > 0 && $migrationPath[$pathLength-1] != DIRECTORY_SEPARATOR) {
			$migrationPath .= DIRECTORY_SEPARATOR;
		}

		$this->migrationPath = $migrationPath;
		return $this;
	}

	public function getCurrentVersion() {
		return $this->currentVersion;
	}

	public function getMessageList() {
		return $this->messageList;
	}

	public function getMigrationsInDb() {
		return $this->migrationsInDb;
	}

	public function getMigrationsOnDisk() {
		return $this->migrationsOnDisk;
	}

	public function getMigrationPath() {
		return $this->migrationPath;
	}

	public function getPdo() {
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



	protected function buildMigrationsInDb() {
		$pdo = $this->getPdo();

		$sql = "SELECT change_id, timestamp, script, set_up, tear_down FROM {$this->changeLogTable} ORDER BY timestamp ASC";
		$migrations = $pdo->query($sql)
			->fetchAll(PDO::FETCH_ASSOC);

		if ( is_array($migrations) ) {
			foreach ( $migrations as $migration ) {
				$this->migrationsInDb[] = $migration;
			}
		}

		return $this->migrationsInDb;
	}

	protected function buildMigrationsOnDisk() {
		$migrationPath = $this->getMigrationPath();

		$migrations = glob($migrationPath . "*.{$this->ext}");
		if (is_array($migrations)) {
			foreach ($migrations as $migration) {
				$migrationFile = trim(basename($migration));
				$timestamp = current(explode('-', $migrationFile));

				$this->migrationsOnDisk[$timestamp] = array(
					'change_id' => 0,
					'timestamp' => $timestamp,
					'script' => $migrationFile,
					'set_up' => NULL,
					'tear_down' => NULL
				);
			}
		}

		ksort($this->migrationsOnDisk);

		return $this->migrationsOnDisk;
	}

	protected function determineLatestTimestamp() {
		$pdo = $this->getPdo();

		$latestTimestamp = $pdo->query("SELECT MAX(timestamp) AS latest_timestamp FROM {$this->changeLogTable}")
			->fetchColumn(0);

		$this->latestTimestamp = $latestTimestamp;

		return $this->latestTimestamp;
	}

	protected function buildMigrationScriptFileName($timestamp, $scriptName) {
		$migrationPath = $this->getMigrationPath();

		$scriptName = $this->sanitizeMigrationScriptName($scriptName);
		$randomString = $this->buildRandomString();

		$migrationFile = implode('-', array($timestamp, $scriptName, $randomString)) . ".{$this->ext}";
		$migrationFilePath = $migrationPath . $migrationFile;

		return $migrationFilePath;
	}

	protected function buildRandomString() {
		return substr(sha1((string)microtime(true)), 0, 12);
	}

	protected function sanitizeMigrationScriptName($scriptName) {
		$scriptName = preg_replace('/[^a-z0-9\-\.]/i', '-', $scriptName);
		$scriptName = preg_replace('/\-{2,}/', NULL, $scriptName);
		$scriptName = trim($scriptName);

		return $scriptName;
	}

	protected function buildMigrationScriptClassName($timestamp, $className) {
		$cleanClassName = $this->sanitizeClassName($className);
		$className = "{$this->classPrefix}_{$timestamp}_{$cleanClassName}";

		return $className;
	}

	protected function sanitizeClassName($className) {
		$className = str_replace('-', ' ', $className);
		$className = ucwords($className);
		$className = str_replace(' ', '_', $className);

		return $className;
	}



	private function buildChangelogTable() {
		$method = __FUNCTION__ . $this->dbType;
		if ( method_exists($this, $method) ) {
			$this->$method();
		}
		return $this;
	}

	private function buildChangelogTableMYSQL() {
		$pdo = $this->getPdo();

		$tableList = $pdo->query('SHOW TABLES')
			->fetchAll(PDO::FETCH_ASSOC);
		$installTable = true;

		foreach ( $tableList as $table ) {
			$tableName = current($table);
			if ( $tableName == $this->changeLogTable ) {
				$installTable = false;
			}
		}

		if ( $installTable ) {
			$tableSql = "CREATE TABLE {$this->changeLogTable} (
					change_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
					created DATETIME NOT NULL,
					timestamp VARCHAR(16) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
					script VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
					set_up TEXT CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
					tear_down TEXT CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL
				) ENGINE = MYISAM CHARACTER SET utf8 COLLATE utf8_unicode_ci;";
			$pdo->exec($tableSql);
		}

		return true;
	}

	private function buildChangelogTablePOSTGRES() {
		throw new \Exception(__CLASS__ . '::' . __FUNCTION__ . ' not yet implemented.');
	}

	private function buildChangelogTableSQLITE() {
		throw new \Exception(__CLASS__ . '::' . __FUNCTION__ . ' not yet implemented.');
	}

}