<?php

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

use DateTime;
use LogicException;
use \PDO, \PDOStatement;
use UnexpectedValueException;
use wlib\Tools\Tree;

/**
 * Manipulate and run a SQL query.
 *
 * @author Cédric Ducarre
 * @since 02/12/2010
 * @version 15/04/2023
 * @package wlib
 */
class Query
{
	/**
	 * Identifies SELECT queries.
	 */
	const TYPE_SELECT = 0;

	/**
	 * Identifies INSERT queries.
	 */
	const TYPE_INSERT = 1;

	/**
	 * Identifies UPDATE queries.
	 */
	const TYPE_UPDATE = 2;

	/**
	 * Identifies DELETE queries.
	 */
	const TYPE_DELETE = 3;

	/**
	 * Identifies TRUNCATE queries.
	 */
	const TYPE_TRUNCATE = 4;

	/**
	 * Identifies REPLACE queries.
	 */
	const TYPE_REPLACE = 5;

	/**
	 * Identifies raw queries.
	 */
	const TYPE_RAW = 6;

	/**
	 * Identifies queries ready for execution.
	 */
	const STATE_READY = 10;

	/**
	 * Identifies queries not ready.
	 */
	const STATE_NOTREADY = 11;

	/**
	 * Error when calling methods which need query to be executed before.
	 */
	const ERR_STATEMENT_NOT_FOUND = 'Statement not found. Run query first.';

	/**
	 * Database connection.
	 * @var Db
	 */
	private $oDb = null;

	/**
	 * Query type.
	 * @var integer
	 */
	private $iType = null;

	/**
	 * Query state.
	 * @var integer
	 */
	private $iState = self::STATE_NOTREADY;

	/**
	 * SQL query clause tree.
	 * @var Tree
	 */
	private $oSQL = null;

	/**
	 * SQL query string.
	 * @var string
	 */
	private $sSQL = '';

	/**
	 * Query parameters.
	 * @var array
	 */
	private $aParameters = array();

	/**
	 * Types of query parameters.
	 * @var array
	 */
	private $aParametersTypes = array();

	/**
	 * Prepared query.
	 * @var PDOStatement
	 */
	private $oStatement = null;

	/**
	 * Create a query.
	 *
	 * @param Db $oDb Database connection.
	 */
	public function __construct(Db $oDb)
	{
		$this->oDb	= $oDb;
		$this->oSQL	= new Tree();
	}

	/**
	 * Convert query to string.
	 *
	 * @return string
	 */
	public function __toString()
	{
		return $this->getSQL();
	}

	/**
	 * Checks validity of a table or column name.
	 * 
	 * @param string $sStrucName Table or column name.
	 * @return string Validated name.
	 * @throws UnexpectedValueException if name is not compliant.
	 */
	public function check(string $sStructName)
	{
		if (!is_string($sStructName) || !preg_match('`^[a-zA-Z0-9_]+$`', $sStructName))
			throw new UnexpectedValueException(
				"Invalid table or column name \"{$sStructName}\"."
				.' [a-zA-Z0-9_] characters only.'
			);

		return $sStructName;
	}

	/**
	 * Escape table or column name.
	 * 
	 * @param string $sStructName Table or column name.
	 * @param boolean $bEnclose Enclose the name with the database driver delimiter character.
	 * @return string
	 */
	public function esc(string $sStructName, bool $bEnclose = true): string
	{
		$this->check($sStructName);

		if ($bEnclose)
		{
			$sEnclosure = '';
			switch ($this->oDb->getDriver())
			{
				case Db::DRV_MYSQL:
				case Db::DRV_PGSQL: $sEnclosure = '`'; break;
				case Db::DRV_SQLTE: $sEnclosure = '"'; break;
			}
			$sStructName = $sEnclosure . $sStructName . $sEnclosure;
		}

		return $sStructName;
	}

	/**
	 * Execute the query.
	 *
	 * @param integer $iFetchMode One of the PDO::FETCH_* constants (for SELECT only).
	 * @return integer|self|false
	 */
	public function run(int $iFetchMode = PDO::FETCH_OBJ): int|self|false
	{
		if (!is_null($this->oStatement))
			$this->oStatement->closeCursor();

		array_walk($this->aParameters, [$this, 'parseValue']);

		$this->oStatement = null;
		$this->oStatement = $this->oDb->execute(
			$this->getSQL(), $this->aParameters, $this->aParametersTypes
		);

		switch ($this->iType)
		{
			case self::TYPE_INSERT:
			case self::TYPE_REPLACE:
				return $this->oDb->getLastInsertId();

			case self::TYPE_SELECT:
			case self::TYPE_RAW:
				if (is_array($this->oStatement))
				{
					foreach ($this->oStatement as &$oStmt)
						$oStmt->setFetchMode($iFetchMode);
				}
				else $this->oStatement->setFetchMode($iFetchMode);

				return $this;
			
			case self::TYPE_DELETE:
			case self::TYPE_UPDATE:
			case self::TYPE_TRUNCATE:
				if (is_array($this->oStatement))
					return array_sum($this->oStatement);

				return $this->oStatement->rowCount();
		}

		return false;
	}

	/**
	 * Get affected rows of the last query execution.
	 *
	 * @return integer Rows count.
	 * @throws LogicException if query not executed.
	 */
	public function getAffectedRows(): int
	{
		if ($this->oStatement === null)
			throw new LogicException(self::ERR_STATEMENT_NOT_FOUND);
		
		return $this->oStatement->rowCount();
	}

	/**
	 * Get last insert id value.
	 *
	 * @param string $sSequenceName Sequence name, SQLite only.
	 * @return int
	 */
	public function getLastInsertId(string $sSequenceName = ''): int
	{
		return $this->oDb->getLastInsertId($sSequenceName);
	}

	/**
	 * Fetch next row.
	 *
	 * @return mixed|false Row with the type requested at runtime (PDO::FETCH_*). `false` when end reached.
	 * @throws LogicException if query not executed.
	 */
	public function fetch(): mixed
	{
		if ($this->oStatement === null)
			throw new LogicException(self::ERR_STATEMENT_NOT_FOUND);

		return $this->oStatement->fetch();
	}

	/**
	 * Fetch remaining rows. 
	 *
	 * @return array|false Array of rows or `false`.
	 * @throws LogicException if query not executed.
	 */
	public function fetchAll(): array|false
	{
		if ($this->oStatement === null)
			throw new LogicException(self::ERR_STATEMENT_NOT_FOUND);

		return $this->oStatement->fetchAll();
	}

	/**
	 * Fetch a column in next row.
	 * 
	 * @param integer $index Column index, from 0.
	 * @return mixed|false
	 * @throws LogicException if query not executed.
	 */
	public function fetchColumn(int $index = 0): mixed
	{
		if ($this->oStatement === null)
			throw new LogicException(self::ERR_STATEMENT_NOT_FOUND);

		return $this->oStatement->fetchColumn($index);
	}

	/**
	 * Define the query SELECT clause.
	 *
	 * Usages :
	 * 
	 * ```
	 * select('expression1, expression2, expression3')
	 * select(['expression1', 'expression2', 'expression3'])
	 * select('expression1', 'expression2', 'expression3')
	 * ```
	 *
	 * @param string|array $mSelect Clause contents.
	 * @return self
	 */
	public function select(string|array $mSelect): self
	{
		$this->iType	= self::TYPE_SELECT;
		$this->iState	= self::STATE_NOTREADY;

		$this->oSQL->select(is_array($mSelect) ? $mSelect : func_get_args());

		return $this;
	}

	/**
	 * Define the query FROM clause.
	 *
	 * Usages :
	 * 
	 * ```
	 * from('table_name', 'alias_name')
	 * from($oQuery, 'alias_name')
	 * ```
	 *
	 * @param string|Query $mFrom Table à utiliser ou sous-requête.
	 * @param string $sAlias Optional alias.
	 * @return self
	 */
	public function from(string|Query $mFrom, $sAlias = ''): self
	{
		$this->iState = self::STATE_NOTREADY;

		$sAliasPattern = '`([a-zA-Z0-9_]+)\s+as\s+([a-zA-Z0-9_]+)`Ui';
		if (is_string($mFrom) && preg_match($sAliasPattern, trim($mFrom), $matches))
		{
			$mFrom = $matches[1];
			$sAlias = $matches[2];
		}

		$this->oSQL->from(['from' => $mFrom, 'alias' => $sAlias]);

		return $this;
	}

	/**
	 * Add an INNER JOIN clause.
	 *
	 * Usages :
	 * 
	 * ```
	 * innerJoin('table_name', 'conditions')
	 * innerJoin('table_name AS t', 'conditions')
	 * ```
	 *
	 * @param string $sJoinTable Table to join.
	 * @param string $sJoinConditions Join conditions.
	 * @return self
	 */
	public function innerJoin(string $sJoinTable, string $sJoinConditions): self
	{
		return $this->join('INNER', $sJoinTable, $sJoinConditions);
	}

	/**
	 * Add a LEFT JOIN clause.
	 *
	 * Usages :
	 * 
	 * ```
	 * leftJoin('table_name', 'conditions')
	 * leftJoin('table_name AS t', 'conditions')
	 * ```
	 *
	 * @param string $sJoinTable Table to join.
	 * @param string $sJoinConditions Join conditions.
	 * @return self
	 */
	public function leftJoin(string $sJoinTable, string $sJoinConditions): self
	{
		return $this->join('LEFT', $sJoinTable, $sJoinConditions);
	}

	/**
	 * Add a RIGHT JOIN clause.
	 *
	 * Usages :
	 * 
	 * ```
	 * leftJoin('table_name', 'conditions')
	 * leftJoin('table_name AS t', 'conditions')
	 * ```
	 *
	 * @param string $sJoinTable Table to join.
	 * @param string $sJoinConditions Join conditions.
	 * @return self
	 */
	public function rightJoin(string $sJoinTable, string $sJoinConditions): self
	{
		return $this->join('RIGHT', $sJoinTable, $sJoinConditions);
	}

	/**
	 * Add several joints.
	 * 
	 * @param array $aJoints Array of joints.
	 * @return self
	 */
	public function joins(array $aJoints): self
	{
		if (count($aJoints))
		{
			$this->iState = self::STATE_NOTREADY;
			$this->oSQL->joins(array_merge($this->oSQL->joins, $aJoints));
		}
		
		return $this;
	}

	/**
	 * Define the WHERE clause.
	 *
	 * Usage : `where('condition1 AND condition2 OR condition3 ...')`
	 *
	 * @param string $sWhere Conditions.
	 * @return self
	 */
	public function where(string $sWhere): self
	{
		$this->iState = self::STATE_NOTREADY;

		$this->oSQL->where($sWhere);

		return $this;
	}

	/**
	 * Define the GROUP BY clause.
	 *
	 * Usages :
	 * 
	 * ```
	 * groupBy('expression1, expression2')
	 * groupBy(['expression1', 'expression2'])
	 * groupBy('expression1', 'expression2')
	 * ```
	 *
	 * @param string $mGroupBy Clause contents.
	 * @return self
	 */
	public function groupBy(string|array $mGroupBy): self
	{
		$this->iState = self::STATE_NOTREADY;

		$mGroupBy = (is_array($mGroupBy) ? $mGroupBy : func_get_args());
		$mGroupBy = implode(', ', $mGroupBy);

		$this->oSQL->groupBy($mGroupBy);

		return $this;
	}

	/**
	 * Define the ORDER BY clause.
	 *
	 * Usages :
	 * 
	 * ```
	 * orderBy('expression1, expression2 DESC, expression3 ASC')
	 * orderBy(['expression1', 'expression2 DESC', 'expression3 ASC'])
	 * orderBy('expression1', 'expression2 DESC', 'expression3 ASC')
	 *
	 * @param string $mOrderBy Clause contents.
	 * @return self
	 */
	public function orderBy(string|array $mOrderBy): self
	{
		$this->iState = self::STATE_NOTREADY;

		$mOrderBy = (is_array($mOrderBy) ? $mOrderBy : func_get_args());
		$mOrderBy = implode(', ', $mOrderBy);

		$this->oSQL->orderBy($mOrderBy);

		return $this;
	}

	/**
	 * Define the HAVING clause.
	 *
	 * Usage : `having('condition1 AND condition2 OR condition3 ...')`
	 *
	 * @param string $sHaving Clause contents.
	 * @return self
	 */
	public function having(string $sHaving): self
	{
		$this->iState = self::STATE_NOTREADY;

		$this->oSQL->having($sHaving);

		return $this;
	}

	/**
	 * Define the LIMIT clause.
	 *
	 * @param integer|string $mRowCount Number of rows to return or clause contents as string.
	 * @param integer $iOffset Offset to apply.
	 * @return self
	 */
	public function limit(int|string $mRowCount, int $iOffset = 0): self
	{
		$this->iState = self::STATE_NOTREADY;

		$this->oSQL->limit(
			$iOffset > 0
				? (int) $mRowCount .' OFFSET '. $iOffset
				: $mRowCount
		);

		return $this;
	}

	/**
	 * Define the query UPDATE clause.
	 *
	 * @param string $sTableName Table name.
	 * @return self
	 */
	public function update(string $sTableName): self
	{
		$this->iType	= self::TYPE_UPDATE;
		$this->iState	= self::STATE_NOTREADY;

		$this->oSQL->update($sTableName);

		return $this;
	}

	/**
	 * Define the query INSERT clause.
	 *
	 * @param string $sTableName Table name.
	 * @return self
	 */
	public function insert(string $sTableName): self
	{
		$this->iType	= self::TYPE_INSERT;
		$this->iState	= self::STATE_NOTREADY;

		$this->oSQL->insert($sTableName);

		return $this;
	}

	/**
	 * Define a column value for an INSERT or UPDATE query.
	 *
	 * @param string $sColumn Column name.
	 * @param mixed $mValue Value to insert/update.
	 * @param integer $iType Column type among PDO::PARAM_* constants.
	 * @return self
	 */
	public function set(string $sColumn, mixed $mValue, int $iType = PDO::PARAM_STR): self
	{
		$this->iState = self::STATE_NOTREADY;

		$aValues = $this->oSQL->values;

		if (!is_null($mValue)
			&& (
				(is_string($mValue) && strpos($mValue, ':') !== 0 && $mValue != '?')
				|| !is_string($mValue)
			)
		) {
			$this->setParameter(':'.$sColumn, $mValue, $iType);
			$mValue = ':'.$sColumn;
		}

		$aValues[$sColumn] = $mValue;

		$this->oSQL->values($aValues);

		return $this;
	}

	/**
	 * Define a set of values for an INSERT or UPDATE query.
	 * 
	 * Gives each array passed to the `set()` method. The values must therefore
	 * correspond to its prototype.
	 * 
	 * @see self::set()
	 * @param array $aValues Array of values.
	 * @return self
	 */
	public function values(array|string ...$aValues): self
	{
		foreach ($aValues as $aValue)
		{
			if (is_string($aValue))
				$this->set($aValue, null);
			else
				$this->set(...$aValue);
		}

		return $this;
	}

	/**
	 * Define the query DELETE clause.
	 *
	 * @param string $sTableName Table name.
	 * @return self
	 */
	public function delete(string $sTableName): self
	{
		$this->iType	= self::TYPE_DELETE;
		$this->iState	= self::STATE_NOTREADY;

		$this->oSQL->delete($sTableName);

		return $this;
	}

	/**
	 * Define the query TRUNCATE clause.
	 * 
	 * @param string $sTableName Table name.
	 * @return self
	 */
	public function truncate(string $sTableName): self
	{
		$this->iType	= self::TYPE_TRUNCATE;
		$this->iState	= self::STATE_NOTREADY;

		$this->oSQL->truncate($sTableName);

		return $this;
	}

	/**
	 * Define a raw SQL query.
	 * 
	 * @param string $sSQL Query SQL code.
	 * @return self
	 */
	public function raw(string $sSQL): self
	{
		$this->iType	= self::TYPE_RAW;
		$this->iState	= self::STATE_NOTREADY;

		$this->oSQL->raw($sSQL);

		return $this;
	}

	/**
	 * Define one parameter.
	 *
	 * @param string|integer $mKey Parameter name or index.
	 * @param mixed $mValue Parameter value.
	 * @param int|null $sType One of the PDO::PARAM_* constants.
	 * @return self
	 */
	public function setParameter(
		string|int $mKey, mixed $mValue, int|null $iType = PDO::PARAM_STR
	): self
	{
		$this->aParametersTypes[$mKey] = $iType;
		$this->aParameters[$mKey] = $mValue;

		return $this;
	}

	/**
	 * Define several parameters.
	 *
	 * @param array $aParameters Array of arrays which suits to `set()` prototype.
	 * @return self
	 */
	public function setParameters(array $aParameters): self
	{
		foreach ($aParameters as $aParameter)
			call_user_func_array([$this, 'setParameter'], $aParameter);

		return $this;
	}

	/**
	 * Make the SQL code string.
	 *
	 * *Note :* `array` return is only used for TRUNCATE query when working with
	 * SQLite since TRUNCATE doesn't exists on this engine.
	 * 
	 * @return string|array SQL string.
	 */
	public function getSQL(): string|array
	{
		// Requête non valide on arrête ici
		if ($this->iState == self::STATE_READY)
			return $this->sSQL;

		$sQuery = '';

		// Génération selon le type
		switch ($this->iType)
		{
			case self::TYPE_SELECT:
				$sQuery = $this->getSQLForSelect();
				break;
			case self::TYPE_INSERT:
				$sQuery = $this->getSQLForInsert();
				break;
			case self::TYPE_UPDATE:
				$sQuery = $this->getSQLForUpdate();
				break;
			case self::TYPE_DELETE:
				$sQuery = $this->getSQLForDelete();
				break;
			case self::TYPE_TRUNCATE:
				$sQuery = $this->getSQLForTruncate();
				break;
			case self::TYPE_RAW:
				$sQuery = $this->oSQL->raw;
				break;
		}

		$this->iState = self::STATE_READY;
		$this->sSQL = $sQuery;

		return $sQuery;
	}

	/**
	 * Display the results set.
	 * 
	 * Just for dedugging.
	 */
	public function display()
	{
		if ($this->iType != self::TYPE_SELECT)
		{
			echo '<p>Show only works with select statements.</p>';
			return;
		}

		if ($this->oStatement === null)
		{
			echo '<p>Nothing to show. Execute query first.<p>';
			return;
		}

		$bFirst = true;
		$sHeader = '';
		$sBody = '';

		while ($oRow = $this->oStatement->fetch())
		{
			if ($bFirst)
			{
				$aColumns = array_keys((array) $oRow);
				$sHeader = '<tr><th>'. implode('</th><th>', $aColumns) .'</th></tr>';
				$bFirst = false;
			}
			
			$sBody .= '<tr><td>'. implode('</td><td>', (array) $oRow) .'</td></tr>';;
		}

		echo '<table><thead>'.$sHeader.'</thead><tbody>'.$sBody.'</tbody></table>';
	}

	/**
	 * Add a join to the query.
	 *
	 * @param string $sJoinMode Join mode (inner, left, right, ...), without "JOIN" term.
	 * @param string $sJoinTable Join table.
	 * @param string $sJoinConditions Join conditions.
	 * @return self
	 */
	private function join(string $sJoinMode, string $sJoinTable, string $sJoinConditions): self
	{
		$this->iState = self::STATE_NOTREADY;

		if (!is_array($this->oSQL->joins))
			$this->oSQL->joins(array());

		$aJoins		= $this->oSQL->joins;
		$aJoins[]	= [$sJoinMode => $sJoinTable, 'ON' => $sJoinConditions];

		$this->oSQL->joins($aJoins);

		return $this;
	}

	/**
	 * Make a SELECT SQL code string.
	 *
	 * @return string SQL code.
	 */
	private function getSQLForSelect(): string
	{
		$sQuery = 'SELECT '. implode(', ', $this->oSQL->select);

		if (!empty($this->oSQL->from))
		{
			$aFromClause = $this->oSQL->from;
	
			if ($aFromClause['from'] instanceof self)
				$sQuery .= sprintf(
					' FROM (%s) AS %s',
					$this->esc($aFromClause['from']),
					$this->esc($aFromClause['alias'], false)
				);
	
			else
			{
				$sQuery .= ' FROM '. $this->esc($aFromClause['from']);
	
				if ($aFromClause['alias'] != '')
					$sQuery .= ' AS '. $this->esc($aFromClause['alias']);
			}
		}

		if (!empty($this->oSQL->joins))
			foreach ($this->oSQL->joins as $aJointure)
				$sQuery .=
					' '. key($aJointure) .' JOIN '. current($aJointure)
					.' ON '. $aJointure['ON'];

		if (!empty($this->oSQL->where))
			$sQuery .= ' WHERE '. $this->oSQL->where;

		if (!empty($this->oSQL->groupBy))
			$sQuery .= ' GROUP BY '. $this->oSQL->groupBy;

		if (!empty($this->oSQL->having))
			$sQuery .= ' HAVING '. $this->oSQL->having;

		if (!empty($this->oSQL->orderBy))
			$sQuery .= ' ORDER BY '. $this->oSQL->orderBy;

		if (!empty($this->oSQL->limit))
			$sQuery .= ' LIMIT '. $this->oSQL->limit;

		return $sQuery;
	}

	/**
	 * Make an INSERT SQL code string.
	 *
	 * @return string SQL code.
	 */
	private function getSQLForInsert(): string
	{
		$aValues = $this->oSQL->values;

		foreach ($aValues as $sColName => &$mValue)
		{
			$aColumns[] = $this->esc($sColName);
			$this->parseValue($mValue);
		}

		return
			'INSERT INTO '. $this->esc($this->oSQL->insert)
			.'('. implode(', ', $aColumns) .')'
			.' VALUES ('. implode(', ', $aValues) .')';
	}

	/**
	 * Make an UPDATE SQL code string.
	 *
	 * @return string SQL code.
	 */
	private function getSQLForUpdate(): string
	{
		$aValues = [];

		foreach ($this->oSQL->values as $sColName => $mValue)
		{
			if (!is_null($mValue))
			{
				$this->parseValue($mValue);
				$aValues[] = $this->esc($sColName) .' = '. $mValue;
			}
			else $aValues[] = $sColName;
		}

		return
			'UPDATE '. $this->esc($this->oSQL->update) .' SET '
			.implode(', ', $aValues)
			.(!empty($this->oSQL->where) ? ' WHERE '. $this->oSQL->where : '')
		;
	}

	/**
	 * Make a DELETE SQL code string.
	 *
	 * @return string SQL code.
	 */
	private function getSQLForDelete(): string
	{
		return
			'DELETE FROM '. $this->esc($this->oSQL->delete)
			.(!empty($this->oSQL->where) ? ' WHERE '. $this->oSQL->where : '')
		;
	}

	/**
	 * Make a TRUNCATE SQL code string.
	 *
	 * @return string|array SQL code.
	 */
	private function getSQLForTruncate(): string|array
	{
		$sTableName = $this->oSQL->truncate;

		switch ($this->oDb->getDriver())
		{
			case Db::DRV_SQLTE:
				$LQuery = [
					'DELETE FROM '. $this->esc($sTableName),
					'DELETE FROM sqlite_sequence WHERE name = '
					. $this->oDb->quote($sTableName)
				];
				break;

			default: 
				$LQuery = 'TRUNCATE '. $this->esc($sTableName);
		}

		return $LQuery;
	}

	/**
	 * Parse values before INSERT/UPDATE.
	 * 
	 * Examples :
	 * 
	 * - Replace "NOW()" function that SQLite engine does not know.
	 * 
	 * @param mixed $mValue Current value reference.
	 */
	private function parseValue(&$mValue)
	{
		if (strtolower($mValue) == 'now()' && $this->oDb->getDriver() == Db::DRV_SQLTE)
		{
			$mValue = (new DateTime())->format('Y-m-d H:i:s');
		}
	}
}