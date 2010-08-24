<?php

declare(encoding='UTF-8');

/**
 * Class to facilitate handling database transactions.
 *
 * @author Vic Cherubini <vic.cherubini@quickoffice.com>
 */
class DbMigrator {

	private $currentVersion = 0;

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
	
	const SCRIPT_ATTEMPTS = 10;
	
	public function __construct($dbType) {
		$this->messageList = array();
		$this->dbType = strtoupper($dbType);
	}

	public function __destruct() {
	
	}
	
	/**
	 * Attach the PDO object to the migration object.
	 * 
	 * @param $pdo The PDO object.
	 * @retval DbMigrator Returns this for chaining.
	 */
	public function attachPdo(\PDO $pdo) {
		$this->pdo = $pdo;
		return $this;
	}
	
	/**
	 * Build a new migration script. Creates a new class based on the script name and sets up the
	 * default methods.
	 * 
	 * @param $scriptName The name of the script to create. Will be named <current-version>-<script-name>.<ext>.
	 * @retval string Returns the name of the newly created script.
	 */
	public function create($scriptName) {
		$migrationsOnDisk = $this->buildMigrationsOnDisk();
		
		$version = 0;
		foreach ( $migrationsOnDisk as $migrationFile ) {
			if ( preg_match('/^(\d+).*/i', $migrationFile, $match) ) {
				$version = intval($match[1]);
			}
		}
		
		$version++;
		
		$migrationFilePath = $this->buildMigrationScriptFileName($version, $scriptName);
		$className = $this->buildMigrationScriptClassName($version, $scriptName);
		
		
		$tearDownCode = NULL;
		if ( 0 === stripos($scriptName, self::SQL_CREATE_TABLE) ) {
			$tableName = substr($scriptName, strlen(self::SQL_CREATE_TABLE)+1);
			$tableName = str_replace('-', '_', $tableName);
			
			$tearDownCode = "DROP TABLE {$tableName}";
		} elseif ( 0 === stripos($scriptName, self::SQL_CREATE_DATABASE) ) {
			$dbName = substr($scriptName, strlen(self::SQL_CREATE_DATABASE)+1);
			$dbName = str_replace('-', '_', $dbName);
			
			$tearDownCode = "DROP DATABASE {$dbName}";
		}
		
		$now = date('Y-m-d H:i:s');
		
		$fh = fopen($migrationFilePath, 'w');
			fwrite($fh, "<?php" . PHP_EOL . PHP_EOL);
			fwrite($fh, "// This file was automatically generated, ADD YOUR QUERIES BELOW." . PHP_EOL);
			fwrite($fh, "// CREATION DATE: {$now}" . PHP_EOL . PHP_EOL);
			fwrite($fh, "\$__migrationObject = new {$className};" . PHP_EOL . PHP_EOL);
			fwrite($fh, "class {$className} {" . PHP_EOL . PHP_EOL);
			fwrite($fh, "\tpublic function {$this->setUpMethod}() {" . PHP_EOL);
			fwrite($fh, "\t\treturn \"\";" . PHP_EOL);
			fwrite($fh, "\t}" . PHP_EOL . PHP_EOL);
			fwrite($fh, "\tpublic function {$this->tearDownMethod}() {" . PHP_EOL);
			fwrite($fh, "\t\treturn \"{$tearDownCode}\";" . PHP_EOL);
			fwrite($fh, "\t}" . PHP_EOL);
			fwrite($fh, "}");
		fclose($fh);
		
		$migrationScript = basename($migrationFilePath);
		$this->addSuccessMessage("New migration script, {$migrationScript}, was successfully created.");
		
		return $migrationScript;
	}
	
	/**
	 * Update the database to the latest version. Compares the migrations in the database to those
	 * on the disk. If there are new migration scripts on the disk, they are loaded and the setUp()
	 * method is executed to get the query. The query is then executed.
	 * 
	 * @retval boolean true
	 */
	public function update($version=-1) {
		$this->buildChangelogTable();
		
		$pdo = $this->getPdo();
		$migrationsOnDisk = $this->buildMigrationsOnDisk();
		$migrationsInDb = $this->buildMigrationsInDb();
		$migrationPath = $this->getMigrationPath();
		$currentVersion = $this->determineCurrentVersion();
		
		
		
		//$updateMigrations = array_diff($migrationsOnDisk, $migrationsInDb);
		$method = NULL;
		$version = intval($version);
		$scripts = array();
		
		if ( -1 == $version ) {
			
			
			
		} elseif ( $version < $currentVersion ) {
			// Rolling back to $version
			// Get the scripts between $version and $currentVersion
			$version++;
			$sql = "SELECT change_id, script FROM {$this->changeLogTable}
				WHERE version BETWEEN ? AND ?
				ORDER BY version DESC";
			$statement = $pdo->prepare($sql);
			$executed = $statement->execute(array($version, $currentVersion));
		
			if ( !$executed ) {
				$this->error("Failed to select scripts from Change Log table to rollback.");
				return false;
			}
		
			$scripts = $statement->fetchAll(\PDO::FETCH_ASSOC);
			$method = $this->tearDownMethod;
			
		} elseif ( $version > $currentVersion ) {
			// Updating to $version
			// We have a list of scripts in the database
			// We have a list of scripts on the disk
			// The number of scripts on the disk should be greater than the currentVersion.
			
			
			
			
			
			
		}
		
		
		
		print_r($scripts);
		echo $method, PHP_EOL;
		
		
		
		
/*
		
		
		
		
		
		
		$error = false;
		$startTime = microtime(true);
		$execTime = 0;
		
		foreach ( $updateMigrations as $migrationFile ) {
			$migrationFilePath = $migrationPath . $migrationFile;
			if ( is_file($migrationFilePath) ) {
				require_once $migrationFilePath;
				
				$setUpMethod = $this->setUpMethod;
				if ( is_object($__migrationObject) && method_exists($__migrationObject, $setUpMethod) ) {
					$migrationClass = get_class($__migrationObject);
					$query = $__migrationObject->$setUpMethod();
					
					$executed = false;
					if ( !empty($query) ) {
						$executed = $pdo->exec($query);
					}
					
					if ( false === $executed ) {
						$errorInfo = $pdo->errorInfo();
						
						$this->error("##################################################");
						$this->error("FAILED TO EXECUTE: {$migrationFile}");
						$this->error("ERROR: {$errorInfo[2]}");
						$this->error("##################################################");
						
						$error = true;
						break;
					} else {
						$this->success(sprintf("%01.3fs - %-10s", $execTime, $migrationFile));
					}
					
					$pdo->prepare("INSERT INTO {$this->changeLogTable} VALUES(NULL, NOW(), ?, ?)")
						->execute(array(++$currentVersion, $migrationFile));
				}
			}
			
			$execTime = microtime(true);
			$execTime -= $startTime;
		}

		if ( $error ) {
			$this->error("FAILURE! DbMigrator COULD NOT UPDATE TO THE LATEST VERSION OF THE DATABASE!");
		} else {
			$this->success("Successfully migrated the database to the most current version, {$currentVersion}.");
		}
*/
		
		return true;
	}
	
	
	
	
	
	/**
	 * Sets the path to where migrations are stored on the disk. Automatically appends the DIRECTORY_SEPARATOR to the
	 * path if it is not already on there.
	 * 
	 * @param $migrationPath The path to store migrations.
	 * @retval DbMigrator Returns $this for chaining.
	 */
	public function setMigrationPath($migrationPath) {
		$pathLength = strlen($migrationPath);
		if ( $migrationPath[$pathLength-1] != DIRECTORY_SEPARATOR ) {
			$migrationPath .= DIRECTORY_SEPARATOR;
		}
		
		$this->migrationPath = $migrationPath;
		return $this;
	}
	
	/**
	 * Returns the current version the database is installed to.
	 * 
	 * @retval integer Returns the databases version.
	 */
	public function getCurrentVersion() {
		return $this->currentVersion;
	}
	
	/**
	 * Returns the list of messages generated by normal operation.
	 * 
	 * @retval array List of messages.
	 */
	public function getMessageList() {
		return $this->messageList;
	}
	
	/**
	 * Returns a list of migrations that have been installed in the database.
	 * 
	 * @retval array Returns the migrations in the database.
	 */
	public function getMigrationsInDb() {
		return $this->migrationsInDb;
	}
	
	/**
	 * Returns a list of migrations that have been installed on the disk.
	 * 
	 * @retval array Returns the migrations on the disk.
	 */
	public function getMigrationsOnDisk() {
		return $this->migrationsOnDisk;
	}
	
	/**
	 * Returns the path that migrations are placed. The migration scripts are located here.
	 * 
	 * @retval string Returns the path the migrations are stored in.
	 */
	public function getMigrationPath() {
		return $this->migrationPath;
	}
	
	/**
	 * Returns the PDO object.
	 * 
	 * @retval object The PDO object.
	 */
	public function getPdo() {
		return $this->pdo;
	}
	
	/**
	 * Print an error message directly to the console. It is colored red and bold.
	 * 
	 * @param $message The message to print.
	 */
	public function error($message) {
		echo "  ## \033[1;31m{$message}\033[m", PHP_EOL;
	}
	
	/**
	 * Print a success message directly to the console. It is colored gree and bold.
	 * 
	 * @param $message The message to print.
	 */
	public function success($message) {
		echo "  ## \033[1;32m{$message}\033[m", PHP_EOL;
	}
	
	/**
	 * Print a neutral message directly to the console. It is colored blue and bold.
	 * 
	 * @param $message The message to print.
	 */
	public function message($message) {
		echo "  ## \033[1;34m{$message}\033[m", PHP_EOL;
	}
	
	/**
	 * Print all messages to the console.
	 * 
	 * @param $message The message to print.
	 */
	public function printMessageList() {
		foreach ( $this->messageList as $message ) {
			switch ( $message['type'] ) {
				case DbMigrator::MESSAGE_SUCCESS: {
					$this->success($message['message']);
					break;
				}
				
				case DbMigrator::MESSAGE_ERROR: {
					$this->error($message['message']);
					break;
				}
			}
		}
		return true;
	}
	
	
	
	/**
	 * ################################################################################
	 * PROTECTED METHODS
	 * ################################################################################
	 */
	
	protected function addErrorMessage($message) {
		$this->addMessage(self::MESSAGE_ERROR, $message);
		return $this;
	}

	protected function addSuccessMessage($message) {
		$this->addMessage(self::MESSAGE_SUCCESS, $message);
		return $this;
	}

	protected function addMessage($type, $message) {
		$this->messageList[] = array('type' => $type, 'message' => $message);
		return $this;
	}
	
	protected function buildMigrationsInDb() {
		$pdo = $this->getPdo();
		
		$migrations = $pdo->query("SELECT change_id, version, script FROM {$this->changeLogTable} ORDER BY version ASC")
			->fetchAll(\PDO::FETCH_ASSOC);
		
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
		if ( is_array($migrations) ) {
			foreach ( $migrations as $migration ) {
				$this->migrationsOnDisk[] = trim(basename($migration));
			}
		}
		
		natsort($this->migrationsOnDisk);
		
		return $this->migrationsOnDisk;
	}
	
	protected function determineCurrentVersion() {
		$pdo = $this->getPdo();

		$currentVersion = $pdo->query("SELECT MAX(version) AS current_version FROM {$this->changeLogTable}")
			->fetchColumn(0);

		$this->currentVersion = intval($currentVersion);
		
		return $this->currentVersion;
	}
	
	protected function buildMigrationScriptFileName($version, $scriptName) {
		$migrationPath = $this->getMigrationPath();
		
		$scriptName = $this->sanitizeMigrationScriptName($scriptName);
		$randomString = $this->buildRandomString();
		
		$migrationFile = implode('-', array($version, $scriptName, $randomString)) . ".{$this->ext}";
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
	
	protected function buildMigrationScriptClassName($version, $className) {
		$cleanClassName = $this->sanitizeClassName($className);
		$className = "{$this->classPrefix}_{$version}_{$cleanClassName}";
		
		return $className;
	}
	
	protected function sanitizeClassName($className) {
		$className = str_replace('-', ' ', $className);
		$className = ucwords($className);
		$className = str_replace(' ', '_', $className);
		
		return $className;
	}
	
	
	/**
	 * ##################################################
	 * PRIVATE METHODS
	 * ##################################################
	 */
	
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
			->fetchAll(\PDO::FETCH_ASSOC);
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
					version INT NOT NULL DEFAULT 0,
					script VARCHAR(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL
				) ENGINE = MYISAM CHARACTER SET utf8 COLLATE utf8_unicode_ci;";
			$pdo->exec($tableSql);
		}
	}
	
}