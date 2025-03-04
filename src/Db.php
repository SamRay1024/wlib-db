<?php declare(strict_types=1);

/* ==== LICENCE AGREEMENT =====================================================
 *
 * © Cédric Ducarre (20/05/2010)
 * 
 * wlib is a set of tools aiming to help in PHP web developpement.
 * 
 * This software is governed by the CeCILL license under French law and
 * abiding by the rules of distribution of free software. You can use, 
 * modify and/or redistribute the software under the terms of the CeCILL
 * license as circulated by CEA, CNRS and INRIA at the following URL
 * "http://www.cecill.info".
 * 
 * As a counterpart to the access to the source code and rights to copy,
 * modify and redistribute granted by the license, users are provided only
 * with a limited warranty and the software's author, the holder of the
 * economic rights, and the successive licensors have only limited
 * liability.
 * 
 * In this respect, the user's attention is drawn to the risks associated
 * with loading, using, modifying and/or developing or reproducing the
 * software by the user in light of its specific status of free software,
 * that may mean that it is complicated to manipulate, and that also
 * therefore means that it is reserved for developers and experienced
 * professionals having in-depth computer knowledge. Users are therefore
 * encouraged to load and test the software's suitability as regards their
 * requirements in conditions enabling the security of their systems and/or 
 * data to be ensured and, more generally, to use and operate it in the 
 * same conditions as regards security.
 * 
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 * 
 * ========================================================================== */

namespace wlib\Db;

use Exception;
use \PDO, \PDOStatement;
use UnexpectedValueException;
use wlib\Tools\Hooks;

/**
 * Database access class.
 * 
 * ## Connections
 * 
 * ```php
 * $db = new Db(Db::DRV_SQLTE, '/path/to/db.sqlite');
 * // OR
 * $db = new Db(Db::DRV_MYSQL, 'database', 'user', 'password', 'host', $port, $timeout);
 * 
 * $db->connect();
 * ```
 * ## Save executed queries ( /!\ avoid in production)
 * 
 * ```php
 * $db->saveQueries();
 * var_dump($db->getSavedQueries());
 * ```
 * 
 * ## Get a query
 * 
 * ```php
 * // Helper
 * $query = $db->query();
 * 
 * // Manual instantiation
 * $query = new wlib\Db\Query($db);
 * 
 * $query->select('*')->from('table')->run();
 * 
 * while ($query->fetch()) { // ... }
 * ```
 *
 * @author Cédric Ducarre
 * @since 30/01/2012
 * @version 18/04/2023
 * @package wlib
 */
class Db
{
	const DRV_MYSQL = 'mysql';
	const DRV_PGSQL = 'pgsql';
	const DRV_SQLTE = 'sqlite';

	/**
	 * Current driver.
	 * @var string
	 */
	private $sDriver = '';

	/**
	 * Databse name of pathname (depending on driver).
	 * @var string
	 */
	private $sDatabase = '';

	/**
	 * Username (except SQLite).
	 * @var string
	 */
	private $sUsername = '';

	/**
	 * User password (except SQLite).
	 * @var string
	 */
	private $sPassword = '';

	/**
	 * Server address (DNS, IP, 'localhost').
	 * @var string
	 */
	private $sHost = '';

	/**
	 * Connection port.
	 * @var integer
	 */
	private $iPort = 0;

	/**
	 * Connection timeout (except SQLite).
	 * @var integer
	 */
	private $iTimeout = 0;

	/**
	 * Database connection instance.
	 * @var PDO
	 */
	private $oPdo = null;

	/**
	 * Save executed queries.
	 * @var boolean
	 */
	private $bSaveQueries = false;

	/**
	 * Array of saved queries.
	 * @var array
	 */
	private $aQueries = [];

	/**
	 * Query counter.
	 * @var integer
	 */
	private $iQueries = 0;

	/**
	 * Start time of query execution (is queries are saved).
	 * @var integer
	 */
	private $iTimerStart = 0;

	/**
	 * Configure database connection.
	 *
	 * Connection is opened by a call to `connect()` or at the first query ran.
	 *
	 * @param string $sDriver Driver among `self::DRV_*`.
	 * @param string $sDatabase Database name (or pathname for SQLite).
	 * @param string|null $sUsername Username (except for SQLite).
	 * @param string|null $sPassword User password (except for SQLite).
	 * @param string|null $sHost Server IP or URL. 'localhost' by default (useless for SQLite).
	 * @param integer|null $iPort Connection port (useless for SQLite).
	 * @param integer|null $iTimeout Connection timeout in seconds (useless for SQLite).
	 * @return self
	 */
	public function __construct(
		string $sDriver, string $sDatabase, string $sUsername = null, string $sPassword = null,
		string $sHost = null, int $iPort = null, int $iTimeout = null
	) {
		if (!in_array($sDriver, [self::DRV_MYSQL, self::DRV_PGSQL, self::DRV_SQLTE]))
			throw new UnexpectedValueException(
				"Unsupported driver \"{$sDriver}\"/"
			);

		if (empty($sDatabase))
			throw new UnexpectedValueException('Unspecified database.');

		$sHost !== null or $sHost = 'localhost';

		$this->sDriver		= $sDriver;
		$this->sDatabase	= trim($sDatabase);
		$this->sUsername	= $sUsername;
		$this->sPassword	= $sPassword;
		$this->sHost		= $sHost;
		$this->iPort		= (int) $iPort;
		$this->iTimeout		= (int) $iTimeout;
	}

	/**
	 * Open the bar !
	 *
	 * @param array $aOptions Tableau d'options supplémentaires à envoyer à PDO.
	 */
	public function connect(array $aOptions = array())
	{
		if (!is_null($this->oPdo))
			return;

		$this->oPdo = new PDO(
			$this->sDriver .':'. $this->buildConnectionString(),
			$this->sUsername,
			$this->sPassword,
			$this->buildConnexionOptions($aOptions)
		);

		$this->setDefaultAttributes();
	}

	/**
	 * Close the bar :-(
	 */
	public function close()
	{
		$this->oPdo = null;
	}

	/**
	 * Execute a SQL query.
	 *
	 * @param string|array $mSQL Query, or array of queries, to execute.
	 * @param array $aParams Parameters array if query must be prepared.
	 * @param array $aParamsTypes Types parameters array.
	 * @return PDOStatement|array|int Results set or array of results sets.
	 */
	public function execute(
		string|array $mSQL, array $aParams = array(), array $aParamsTypes = array()
	): PDOStatement|array|int
	{
		$this->connect();

		if (!is_array($mSQL))
			$mSQL = [$mSQL];

		$aStatements = [];
		
		foreach ($mSQL as $sSQL)
		{
			$oStatement = false;
			$sSQL = trim($sSQL);

			if ($aParams)
			{
				$oStatement = $this->oPdo->prepare($sSQL);

				if ($aParamsTypes) 
				{
					$this->bindValues($oStatement, $aParams, $aParamsTypes);

					$this->triggerBeforeExecute();
					$oStatement->execute();
					$this->triggerAfterExecute($oStatement->queryString, $aParams);
				}
				else
				{
					$this->triggerBeforeExecute();
					$oStatement->execute($aParams);
					$this->triggerAfterExecute($oStatement->queryString, $aParams);
				}
			}
			else
			{
				$this->triggerBeforeExecute();

				$oStatement = (preg_match('`^\s*(insert|delete|update|replace|truncate|create) `i', $sSQL)
					? (int) $this->oPdo->exec($sSQL)
					: $this->oPdo->query($sSQL)
				);

				$this->triggerAfterExecute($sSQL);
			}
	
			$aStatements[] = $oStatement;
			$this->iQueries++;
		}

		return (count($aStatements) > 1 ? $aStatements : $aStatements[0]);
	}

	/**
	 * Create a Query instance.
	 *
	 * @return Query
	 */
	public function query(): Query
	{
		return new Query($this);
	}

	/**
	 * Create a Table instance.
	 * 
	 * @param string $sName Table name.
	 * @return Table
	 */
	public function table(string $sName): Table
	{
		$aName = explode('_', $sName);
		array_walk($aName, function(&$sItem)
		{
			$sItem = ucfirst(strtolower($sItem));
		});

		$sName = implode('', $aName).'Table';

		return new $sName($this);
	}

	/**
	 * Secure a value for an SQL query.
	 * 
	 * @param mixed $mValue Value to secure.
	 * @param integer $iType Type to apply among PDO::PARAM_*.
	 */
	public function quote(mixed $mValue, int $iType = PDO::PARAM_STR)
	{
		return $this->oPdo->quote($mValue, $iType);
	}

	/**
	 * Get the current database driver.
	 * 
	 * @return string
	 */
	public function getDriver(): string
	{
		return $this->sDriver;
	}

	/**
	 * Retrieve a database connection attribute.
	 *
	 * @param integer $iAttribute One of the `PDO::ATTR_*` constants.
	 * @return mixed|null Value or `null` in case of error.
	 */
	public function getAttribute(int $iAttribute): mixed
	{
		return $this->oPdo->getAttribute($iAttribute);
	}

	/**
	 * Get last insert ID value.
	 *
	 * @param string $sSequenceName Sequence name (for SQLite only).
	 * @return int
	 */
	public function getLastInsertId(string $sSequenceName = ''): int
	{
		return (int) $this->oPdo->lastInsertId($sSequenceName);
	}

	/**
	 * Get the number of executed queries.
	 *
	 * @return integer
	 */
	public function getQueriesCount(): int
	{
		return $this->iQueries;
	}

	/**
	 * Get the array of executed queries.
	 *
	 * @return array
	 */
	public function getSavedQueries(): array
	{
		return $this->aQueries;
	}

	/**
	 * Save the executed queries.
	 * 
	 * @param integer $bActive `true` by default, `false` to disable.
	 */
	public function saveQueries(bool $bActive = true)
	{
		$this->bSaveQueries = $bActive;
	}

	/**
	 * Set a PDO connection attribute.
	 *
	 * @param integer $iAttribute One of the PDO::ATTR_* constants.
	 * @param mixed $mValue Attribute value (PDO::*_*).
	 * @return boolean `false` in case of error.
	 */
	public function setAttribute(int $iAttribute, mixed $mValue): bool
	{
		return $this->oPdo->setAttribute($iAttribute, $mValue);
	}

	/**
	 * Checks whether a table exists in database.
	 * 
	 * @param string $sTableName Table name.
	 * @return boolean
	 */
	public function isTable(string $sTableName): bool
	{
		$oQuery = $this->query();

		switch ($this->sDriver)
		{
			case self::DRV_MYSQL:
				$oQuery
					->select('EXISTS ('
						.'SELECT `TABLE_NAME` FROM information_schema.TABLES '
						.'WHERE `TABLE_SCHEMA` LIKE :database '
						.'AND `TABLE_TYPE` LIKE "BASE TABLE" '
						.'AND `TABLE_NAME` = :tablename'
						.')'
					)
					->setParameter('database', $this->sDatabase);
				break;

			case self::DRV_PGSQL:
				$oQuery
					->select(
						'EXISTS ('
						. 'SELECT table_name FROM information_schema.tables '
						. 'WHERE table_schema LIKE :database '
						. 'AND table_type LIKE "BASE TABLE" '
						. 'AND table_name` = :tablename'
						. ')'
					)
					->setParameter('database', $this->sDatabase);
				break;

			case self::DRV_SQLTE:
				$oQuery->select('EXISTS ('
					.'SELECT name FROM sqlite_master '
					.'WHERE type = "table" AND name = :tablename'
					.')'
				);
				break;
		}
		
		$oQuery
			->setParameter('tablename', $sTableName, PDO::PARAM_STR)
			->run();
		
		return ($oQuery->fetchColumn() > 0);
	}

	/**
	 * Get the value of AUTO_INCREMENT from given table.
	 *
	 * @param string $sTable Table name.
	 * @return integer|false
	 */
	public function getAutoIncrement($sTable): int|false
	{
		$oQuery = $this->query();

		switch ($this->sDriver)
		{
			case self::DRV_MYSQL:
				$oQuery
					->raw('SHOW TABLE STATUS LIKE ' . $oQuery->esc($sTable))
					->run(PDO::FETCH_ASSOC);

				$aTableStatus = $oQuery->fetch();

				return ($aTableStatus && isset($aTableStatus['Auto_increment'])
					? $aTableStatus['Auto_increment']
					: false
				);

			case self::DRV_PGSQL:
				// TODO : getAutoIncrement for PostgreSQL
				break;

			case self::DRV_SQLTE:
				$oQuery
					->select('seq')->from('sqlite_sequence')->where('name = :name')
					->setParameter('name', $sTable)
					->run();

				return $oQuery->fetchColumn();
		}

		return false;
	}

	/**
	 * Checks rows existence in a table.
	 *
	 * @param string $sTableName Table name to check.
	 * @param string $sFieldName Field name to check.
	 * @param mixed $sValue Value to search.
	 * @return boolean|null	`null` in case of error.
	 */
	public function exists(string $sTableName, string $sFieldName, mixed $mValue): bool|null
	{
		if (empty($sTableName) || empty($sFieldName))
			return null;

		$oQuery = $this->query();
		$sFieldName = $oQuery->esc($sFieldName);

		try
		{
			$oQuery
				->select('COUNT('. $sFieldName .')')->from($sTableName)
				->where($sFieldName .' = :value')
				->setParameter('value', $mValue)
				->run();

			return ($oQuery->fetchColumn() > 0);
		}
		catch (\Exception $e) { return null; }
	}

	/**
	 * Get columns from a table.
	 * 
	 * @param string $sTable Table name.
	 * @param string $sColName Filter on a specific column.
	 * @return array
	 */
	public function getColumns(string $sTable, string $sColName = ''): array
	{
		$oQuery = $this->query();

		$aColumns = [];

		switch ($this->sDriver)
		{
			case self::DRV_PGSQL:
				$sWhereCol = ($sColName ? ' WHERE Field = :colname' : '');
				$oQuery
					->raw('SHOW COLUMNS FROM ' . $oQuery->esc($sTable) . $sWhereCol)
					->setParameter('colname', $sColName)
					->run();

				while ($oCol = $oQuery->fetch())
					$aColumns[$oCol->Field] = [
						'type'		=> $oCol->Type,
						'not_null'	=> (bool) ($oCol->Null == 'NO'),
						'default'	=> $oCol->Default,
						'primary'	=> (stripos($oCol->Key, 'PRI') !== false)
					];
				break;

			case self::DRV_PGSQL:
				throw new Exception('Not implemented for PostgreSQL driver.');
				break;

			case self::DRV_SQLTE:
				$oQuery->raw('PRAGMA table_info('. $oQuery->esc($sTable) .')')->run();

				while ($oCol = $oQuery->fetch())
				{
					if ($sColName && $sColName <> $oCol->name)
						continue;

					$aColumns[$oCol->name] = [
						'type'		=> $oCol->type,
						'not_null'	=> (bool) $oCol->notnull,
						'default'	=> $oCol->dflt_value,
						'primary'	=> (bool) $oCol->pk
					];
				}
				break;
		}

		return ($sColName && isset($aColumns[$sColName]) ? $aColumns[$sColName] : $aColumns);
	}

	/**
	 * Create an empty row for the given table.
	 *
	 * @param string $sFromTable Table pour laquelle construire un enregistrement vierge.
	 * @return \stdClass|false Object which fields are columns name.
	 */
	public function createEmptyRow(string $sFromTable): \stdClass|false
	{
		$aCols = $this->getColumns($sFromTable);

		if (!count($aCols))
			return false;

		$oRow = new \stdClass();

		foreach ($aCols as $sFieldName => $aInfos)
			$oRow->$sFieldName = $aInfos['default'];

		return $oRow;
	}

	/**
	 * Get the possible values of an enumeration (type 'ENUM').
	 *
	 * @param string $sTableName Table name.
	 * @param string $sColName Column name
	 * @return array|false
	 */
	public function getEnumValues(string $sTableName, string $sColName): array|false
	{
		$aCol = $this->getColumns($sTableName, $sColName);

		if (!count($aCol))
			return false;

		$aMatches = array();
		preg_match('`^enum\(\'(.*)\'\)$`i', $aCol['type'], $aMatches);

		if (count($aMatches) < 2)
			return false;

		return explode('\',\'', $aMatches[1]);
	}

	/**
	 * Bind query parameters to their values and types.
	 *
	 * @param PDOStatement $oStatement Prepared query.
	 * @param array $aParams Array of parameters and values.
	 * @param array $aTypes Array of the same parameters and types.
	 */
	private function bindValues(PDOStatement &$oStatement, array $aParams, array $aTypes)
	{
		foreach ($aParams as $mKey => $mValue)
			$oStatement->bindValue(
				$mKey,
				$mValue,
				(isset($aTypes[$mKey]) ? $aTypes[$mKey] : PDO::PARAM_STR)
			);
	}

	/**
	 * Build the connection string.
	 * 
	 * @return string
	 */
	private function buildConnectionString()
	{
		$args = array();

		switch ($this->sDriver)
		{
			case self::DRV_MYSQL:
			case self::DRV_PGSQL:
				empty($this->sHost)		or $args[] = 'host='. $this->sHost;
				empty($this->iPort)		or $args[] = 'port='. $this->iPort;
				empty($this->sDatabase)	or $args[] = 'dbname='. $this->sDatabase;
				break;

			case self::DRV_SQLTE:
				empty($this->sDatabase)	or $args[] = $this->sDatabase;
				break;
		}
		
		return implode(';', $args);
	}

	/**
	 * Build the array of options to apply to the connection.
	 * 
	 * @param array $aOptions Optional additional options.
	 * @return array
	 */
	private function buildConnexionOptions(array $aOptions = array()): array
	{
		$aOpt = array(PDO::ATTR_CASE => PDO::CASE_NATURAL);

		// Apply a default timeout except for SQLite
		if ($this->sDriver != self::DRV_SQLTE && $this->iTimeout > 0)
			$aOpt[PDO::ATTR_TIMEOUT] = $this->iTimeout;

		if (sizeof($aOptions) > 0)
			$aOpt = array_merge($aOpt, $aOptions);
		
		return $aOpt;
	}

	/**
	 * Configure the default behavior of the connection.
	 */
	private function setDefaultAttributes()
	{	
		$this->setAttribute(PDO::ATTR_CASE,					PDO::CASE_NATURAL);
		$this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,	PDO::FETCH_OBJ);
		$this->setAttribute(PDO::ATTR_ERRMODE,				PDO::ERRMODE_EXCEPTION);
		$this->setAttribute(PDO::ATTR_ORACLE_NULLS,			PDO::NULL_NATURAL);
	}

	/**
	 * Start the timer.
	 */
	private function startTimer()
	{
		$this->iTimerStart = microtime(true);
	}

	/**
	 * Stop the timer and return the elapsed time.
	 *
	 * @return float Elapsed time in milliseconds.
	 */
	private function stopTimer(): float
	{
		return microtime(true) - $this->iTimerStart;
	}

	/**
	 * Trigger operations before executing a query.
	 */
	private function triggerBeforeExecute()
	{
		if ($this->bSaveQueries)
			$this->startTimer();

		Hooks::do('wlib.db.execute.before', ['timer_start' => $this->iTimerStart]);
	}

	/**
	 * Trigger operations after executing a query.
	 *
	 * @param string $sSQL SQL code of the executed query.
	 * @param array $aParams Query parameters.
	 */
	private function triggerAfterExecute(string $sSQL, array $aParams = [])
	{
		$iRunningTime = $this->stopTimer();

		if ($this->bSaveQueries)
			$this->aQueries[] = [$sSQL, $aParams, $iRunningTime];

		Hooks::do('wlib.db.execute.after', [
			'sql' => $sSQL,
			'bindings' => $aParams,
			'timer_start'	=> $this->iTimerStart,
			'running_time' => $iRunningTime
		]);
	}
}