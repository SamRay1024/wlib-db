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

use stdClass;
use UnexpectedValueException;
use wlib\Tools\Hooks;

/**
 * Base class for easier management of table records.
 *
 * ## Introduction
 *
 * `Table` must be extended and define at least the two following constants :
 *
 * - TABLE_NAME
 * - COL_ID_NAME
 *
 * ## Extended classes
 *
 * You are strongly advised to implement to essentials methods :
 *
 * - 'filterFields()',
 * - 'isDeletable()'.
 *
 * ### `filterFields()`
 *
 * Its role is to control the fields received before any insertion/update in the
 * database. This method will therefore contain all the business rules governing
 * the table linked to the class.
 *
 * ### `isDeletable()`
 *
 * Intended for use within graphical interfaces, this method contains the
 * business rule which controls whether a record can be deleted.
 *
 * ## Manage records methods
 *
 * Use following methods to manage your records :
 *
 * - `create()`,
 * - `add()`,
 * - `update()`,
 * - `delete()`,
 * - `restore()`.
 *
 * Define the dates constants name to activate auto filling of created, updated
 * and deleted date/time fields.
 *
 * ## Read records methods
 *
 * - `findAssoc()`,
 * - `findAssocs()`,
 * - `findRow()`,
 * - `findRows()`,
 * - `findVal()`.
 *
 * See each method description to learn more.
 *
 * @author Cedric Ducarre
 * @since 15/04/2023
 */
abstract class Table
{
	/**
	 * Table name in database.
	 *
	 * Constant MUST be overwritten in extended classes.
	 */
	const TABLE_NAME = '';

	/**
	 * Primary key name.
	 *
	 * Constant MUST be overwritten in extended classes.
	 */
	const COL_ID_NAME = '';

	/**
	 * Created date/time column name.
	 *
	 * Define this constant to get auto filling of the column value.
	 */
	const COL_CREATED_AT_NAME = '';

	/**
	 * Updated date/time column name.
	 *
	 * Define this constant to get auto filling of the column value.
	 */
	const COL_UPDATED_AT_NAME = '';

	/**
	 * Deleted date/time column name.
	 *
	 * Define this constant to activate soft delete.
	 */
	const COL_DELETED_AT_NAME = '';

	/**
	 * Date format for use of NOW() in SQLite queries.
	 * 
	 * NOW() is not supported by SQLite, so the classe replaces it with date value.
	 */
	const SQLITE_DATE_FORMAT = 'Y-m-d H:i:s';

	/**
	 * Database connection.
	 * @var Db
	 */
	protected $oDb = null;

	/**
	 * Array of fields.
	 * @var array
	 */
	private $aFields = [];

	/**
	 * Array of filters models usable with `filter_*()` functions.
	 *
	 * You can add your filters thanks to two hooks available in `getFilter()`.
	 *
	 * @link http://php.net/manual/fr/book.filter.php
	 * @see self::getFilter() to get a filter.
	 * @var array
	 */
	private static $aFiltersTemplates = [

		'sanitize_string_no_quotes' => [
			'filter'	=> FILTER_SANITIZE_FULL_SPECIAL_CHARS,
			'flags'		=> FILTER_FLAG_NO_ENCODE_QUOTES 
		],
		'validate_string_alnum' => [
			'filter'	=> FILTER_VALIDATE_REGEXP,
			'options'	=> ['regexp' => '`^([[:alnum:]])|`i']
		],
		'validate_bool_nof' => [
			'filter'	=> FILTER_VALIDATE_BOOLEAN,
			'flags'		=> FILTER_NULL_ON_FAILURE
		],
		'validate_enum' => [
			'filter'	=> FILTER_VALIDATE_REGEXP,
			'options'	=> ['regexp' => '`^(%s)$`']
		],
		'validate_date' => [
			'filter'	=> FILTER_VALIDATE_REGEXP,
			'options'	=> ['regexp' => '`^([0-9]{4,4}-[0-9]{2,2}-[0-9]{2,2}|NOW\(\)|)$`i']
		],
		'validate_time' => [
			'filter'	=> FILTER_VALIDATE_REGEXP,
			'options'	=> ['regexp' => '`^([0-9]{2,2}:[0-9]{2,2}:[0-9]{2,2}|NOW\(\)|)$`i']
		],
		'validate_date_time' => [
			'filter'	=> FILTER_VALIDATE_REGEXP,
			'options'	=> [
				'regexp' => '`^([0-9]{4,4}-[0-9]{2,2}-[0-9]{2,2} [0-9]{2,2}:[0-9]{2,2}:[0-9]{2,2}|NOW\(\)|)$`i'
			]
		],
	];

	/**
	 * Constructor.
	 *
	 * @param Db $oDb Database connection.
	 */
	public function __construct(Db $oDb)
	{
		$this->oDb = $oDb;
	}

	/**
	 * Checks constants definitions in extended class.
	 * 
	 * @throws UnexpectedValueException in the event of default.
	 */
	private function checkConstants()
	{
		if (!defined('static::TABLE_NAME') || trim(static::TABLE_NAME) == '')
			throw new UnexpectedValueException(
				'Undefined TABLE_NAME constant in '.static::class.' class.'
			);

		if (!defined('static::COL_ID_NAME') || trim(static::COL_ID_NAME) == '')
			throw new UnexpectedValueException(
				'Undefined COL_ID_NAME constant in '. static::class .' class.'
			);
	}

	/**
	 * Filter and control fields before add/update.
	 *
	 * This method must be overwritten et must contain constraints and filters 
	 * to apply to submitted values.
	 *
	 * ## Dataf filtering
	 * 
	 * To filter data (from `$aFields`), you can use PHP filters functions
	 * (`filter_*`).
	 *
	 * You can get some predefined filters from `getFilters()`.
	 *
	 * ## Raise exceptions
	 *
	 * In case of constraint violation, vous must raise exceptions
	 * (`UnexpectedValueException`, ...).
	 *
	 * ## Return
	 * 
	 * You must return an associative array of fields corresponding to the
	 * columns of the current table.
	 *
	 * Any other field unknown in the table would raise an error.
	 *
	 * @see self::getFilters() to know about supported natives filters.
	 * @param array $aFields Array of entered fields.
	 * @param integer $id Updated record ID or 0 to add.
	 * @return array Array of filtered fields ready to be inserted or updated.
	 */
	protected function filterFields(array $aFields, int $id = 0): array
	{
		return $aFields;
	}

	/**
	 * Prepare fields for the SQL query.
	 *
	 * The method handle automatically creation and update dates.
	 *
	 * @param array $aFields Table fields.
	 * @param integer $id Updated record ID or 0 to add.
	 * @return array Array of prepared fields.
	 */
	private function prepareFields(array $aFields, int $id = 0): array
	{
		if (
			$id == 0 && static::COL_CREATED_AT_NAME != ''
			&& !array_key_exists(static::COL_CREATED_AT_NAME, $aFields)
		)
		{
			$aFields[static::COL_CREATED_AT_NAME] = 'NOW()';
		}

		if (
			static::COL_UPDATED_AT_NAME != ''
			&& !array_key_exists(static::COL_UPDATED_AT_NAME, $aFields)
		)
		{
			$aFields[static::COL_UPDATED_AT_NAME] = 'NOW()';
		}

		$sNow = null;

		foreach ($aFields as $sColName => &$mValue)
		{
			// Null fields are ignored (to put a field at null, you have to use 'NULL' string)
			if (is_null($mValue) || is_array($mValue))
			{
				unset($aFields[$sColName]);
				continue;
			}
			
			if ($mValue === 'NOW()' && $this->oDb->getDriver() === 'sqlite')
			{
				if (is_null($sNow))
					$sNow = (new \DateTime())->format(static::SQLITE_DATE_FORMAT);

				$mValue = $sNow;
			}
		}

		return $aFields;
	}

	/**
	 * Create an empty record.
	 *
	 * Usable to init creation forms on database default values.
	 *
	 * @return array Array of default values set in database.
	 */
	public function create(): array
	{
		return (array) $this->oDb->createEmptyRow(static::TABLE_NAME);
	}

	/**
	 * Add a record in current table.
	 *
	 * @see self::filterFields() for implementation of business rules.
	 * @param array $aFields Record fields array.
	 * @return integer|false Record ID or `false`.
	 */
	public function add(array $aFields): int|false
	{
		$this->checkConstants(__FUNCTION__);

		Hooks::do('db.'. static::TABLE_NAME .'.add.before', ['aFields' => &$aFields]);

		$this->aFields = $this->filterFields($aFields, 0);

		$aRemainingFields = array_diff_key($aFields, $this->aFields);

		Hooks::do('db.'. static::TABLE_NAME .'.filter.after', [
			'id' => 0,
			'aFields' => &$this->aFields,
			'aRemainingFields' => &$aRemainingFields,
		]);

		$aReadyFields = $this->prepareFields($this->aFields);

		$insert = $this->oDb->query()->insert(static::TABLE_NAME);

		foreach ($aReadyFields as $sColName => $mValue)
		{
			$insert->set($sColName, $mValue);
		}

		$mAdded = $insert->run();

		if ($mAdded)
		{
			Hooks::do('db.'. static::TABLE_NAME .'.add.after', [
				'id'				=> $mAdded,
				'sCallingMethod'	=> __FUNCTION__,
				'aFields'			=> &$this->aFields,
				'aRemainingFields'	=> &$aRemainingFields
			]);
		}

		return $mAdded;
	}

	/**
	 * Update a record in current table.
	 *
	 * @see self::filterFields() for implementation of business rules.
	 * @param integer $id Updated record ID or 0 to add.
	 * @param array $aFields Table fields.
	 * @return integer|false Record ID or `false`.
	 */
	public function update(int $id, array $aFields): int|false
	{
		$this->checkConstants(__FUNCTION__);

		Hooks::do('db.'. static::TABLE_NAME .'.update.before', [
			'id'		=> &$id,
			'aFields'	=> &$aFields
		]);

		$this->aFields		= $this->filterFields($aFields, (int) $id);

		$aRemainingFields	= array_diff_assoc($aFields, $this->aFields);

		Hooks::do('db.'. static::TABLE_NAME .'.filter.after', [
			'id'				=> &$id,
			'aFields'			=> &$this->aFields,
			'aRemainingFields'	=> &$aRemainingFields,
		]);

		$aReadyFields		= $this->prepareFields($this->aFields, $id);
		
		$oQuery = $this->oDb->query()
			->update(static::TABLE_NAME)
			->where(static::COL_ID_NAME .' = :id')
			->setParameter('id', $id, \PDO::PARAM_INT)
		;

		foreach ($aReadyFields as $sColName => $mValue)
		{
			$oQuery->set($sColName, $mValue);
		}

		$mUpdated = $oQuery->run();

		if ($mUpdated)
		{
			Hooks::do('db.'. static::TABLE_NAME .'.update.after', [
				'id'				=> &$id,
				'sCallingMethod'	=> __FUNCTION__,
				'aFields'			=> &$this->aFields,
				'aRemainingFields'	=> &$aRemainingFields,
				'mUpdated'			=> &$mUpdated,
			]);
		}

		return ($mUpdated !== false ? $id : false);
	}

	/**
	 * Save a record.
	 *
	 * `save()` redirect automatically on `add()` or `update()`.
	 *
	 * @param array $aFields Fields to save.
	 * @param integer $id Record ID for an update, 0 for an insert.
	 * @return integer|false Record ID added or lines updated count or `false` in case of error.
	 */
	public function save(array $aFields, int $id = 0): int|false
	{
		return ((int)$id == 0
			? $this->add($aFields)
			: $this->update($id, $aFields)
		);
	}

	/**
	 * Delete a record.
	 *
	 * @see self::COL_DELETED_AT_NAME to enable soft delete.
	 * @param integer $id Record ID.
	 * @param boolean $bHard Set to `true` to force a real deletion.
	 * @return boolean
	 */
	public function delete(int $id, bool $bHard = false): bool
	{
		$this->checkConstants(__FUNCTION__);

		if (!$this->isDeletable($id))
			return false;

		Hooks::do('db.'. static::TABLE_NAME .'.delete.before', ['id' => &$id]);

		$oDelete = $this->oDb->query();

		if (static::COL_DELETED_AT_NAME != '' && !$bHard)
		{
			$oDelete
				->update(static::TABLE_NAME)
				->set(static::COL_DELETED_AT_NAME, 'NOW()')
			;
		}
		else
		{
			$oDelete->delete(static::TABLE_NAME);
		}

		$mDeleted = $oDelete
			->where(static::COL_ID_NAME .' = :id')
			->setParameter('id', $id, \PDO::PARAM_INT)
			->run();

		if ($mDeleted)
		{
			Hooks::do('db.'. static::TABLE_NAME .'.delete.after', [
				'id'		=> $id,
				'bHard'		=> !(static::COL_DELETED_AT_NAME != '' && !$bHard),
				'mDeleted'	=> &$mDeleted,
			]);
		}

		return ($mDeleted > 0);
	}

	/**
	 * Restore a record.
	 *
	 * @see self::COL_DELETED_AT_NAME to enable soft delete.
	 * @param integer $id Record ID.
	 * @return boolean
	 */
	public function restore(int $id): bool
	{
		$this->checkConstants(__FUNCTION__);

		if (static::COL_DELETED_AT_NAME == '' || !$this->isRestorable($id))
			return false;

		Hooks::do('db.'. static::TABLE_NAME .'.restore.before', ['id' => &$id]);

		$mRestored = $this->oDb->query()
			->update(static::TABLE_NAME)
			->set(static::COL_DELETED_AT_NAME, 'NULL')
			->where(static::COL_ID_NAME . ' = :id')
			->setParameter('id', $id, \PDO::PARAM_INT)
			->run()
		;

		if ($mRestored)
		{
			Hooks::do('db.'. static::TABLE_NAME .'.restore.after', [
				'id'		=> $id,
				'mRestored'	=> &$mRestored
			]);
		}

		return ($mRestored > 0);
	}

	/**
	 * Checks if a record is deletable.
	 *
	 * @param integer $id Record ID.
	 * @return boolean
	 */
	public function isDeletable(int $id): bool
	{
		return true;
	}
	
	/**
	 * Checks if a record is restorable.
	 *
	 * @param integer $id Record ID.
	 * @return boolean
	 */
	public function isRestorable(int $id): bool
	{
		return true;
	}

	/**
	 * Get the fields array.
	 *
	 * Use this method to retreive data after their passage through `filterFields()`.
	 * This is useful for resetting the values in a form that could not be saved
	 * due to of a constraint violation.
	 *
	 * @return array
	 */
	public function getFields(): array
	{
		return $this->aFields;
	}

	/**
	 * Get the value of AUTO_INCREMENT.
	 *
	 * @return integer|false
	 */
	public function getAutoIncrement(): int|false
	{
		return $this->oDb->getAutoIncrement(static::TABLE_NAME);
	}

	/**
	 * Select a column value from a record ID.
	 *
	 * @param string $sColName Column name to select.
	 * @param integer $id Record ID.
	 * @return string|false Field value.
	 */
	public function findVal(string $sColName, int $id): string|false
	{
		if ($sColName == '')
			return false;

		$oQuery = $this->oDb->query();

		return $oQuery
			->select($oQuery->esc($sColName))
			->from(static::TABLE_NAME)
			->where(static::COL_ID_NAME .' = :id')
			->setParameter('id', $id, \PDO::PARAM_INT)
			->run()
			->fetchColumn()
		;
	}

	/**
	 * Find an ID from a column.
	 * 
	 * Beware that If `$sColName` refers to a non unique column, more than one
	 * row can exists, and only one will be returned.
	 *
	 * @param string $sColumn Column name to select (prefer a unique column).
	 * @param integer $mValue Column value.
	 * @param integer $iType Column type among PDO::PARAM_* constants.
	 * @return integer ID value or 0 if not found.
	 */
	public function findId(string $sColumn, mixed $mValue, int $iType = \PDO::PARAM_STR): int
	{
		if ($sColumn == '')
			return false;

		$oQuery = $this->oDb->query();

		return (int) $oQuery
			->select(static::COL_ID_NAME)
			->from(static::TABLE_NAME)
			->where($oQuery->esc($sColumn) .' = :value')
			->setParameter('value', $mValue, $iType)
			->run()
			->fetchColumn();
	}

	/**
	 * Select a record from its ID as an object.
	 *
	 * @param string|array $mSelect SELECT clause array or string.
	 * @param integer $id Record ID.
	 * @return stdClass|false
	 */
	public function findRow(string|array $mSelect, int $id): stdClass|false
	{
		return $this->buildQueryRow($mSelect, $id)->run(\PDO::FETCH_OBJ)->fetch();
	}

	/**
	 * Select a record from its ID as an associative array.
	 *
	 * @param string|array $mSelect SELECT clause array or string.
	 * @param integer $id Record ID.
	 * @return array|false
	 */
	public function findAssoc(string|array $mSelect, int $id): array|false
	{
		return $this->buildQueryRow($mSelect, $id)->run(\PDO::FETCH_ASSOC)->fetch();
	}

	/**
	 * Select a set of records as an array of objects.
	 *
	 * @param string|array $mSelect SELECT clause array or string.
	 * @param string|array $mWhere WHERE clause array or string.
	 * @param string|array $mOrderBy ORDER BY clause array or string.
	 * @param string $sLimit LIMIT clause string.
	 * @param array $aOthers Array of other clauses ('joins', 'groupBy' and 'having').
	 * @return Query
	 */
	public function findRows(
		string|array $mSelect = '*',
		string|array $mWhere = '',
		string|array $mOrderBy = '',
		string $sLimit = '',
		array $aOthers = []
	): Query
	{
		return $this->buildQueryRows($mSelect, $mWhere, $mOrderBy, $sLimit, $aOthers)
			->run(\PDO::FETCH_OBJ);
	}

	/**
	 * Select a set of records as an array of associatives arrays.
	 *
	 * @param string|array $mSelect SELECT clause array or string.
	 * @param string|array $mWhere WHERE clause array or string.
	 * @param string|array $mOrderBy ORDER BY clause array or string.
	 * @param string $sLimit LIMIT clause string.
	 * @param array $aOthers Array of other clauses ('joins', 'groupBy' and 'having').
	 * @return Query
	 */
	public function findAssocs(
		string|array $mSelect = '*',
		string|array $mWhere = '',
		string|array $mOrderBy = '',
		string $sLimit = '',
		array $aOthers = []
	): Query
	{
		return $this->buildQueryRows($mSelect, $mWhere, $mOrderBy, $sLimit, $aOthers)
			->run(\PDO::FETCH_ASSOC);
	}

	/**
	 * Checks if a record exists.
	 *
	 * ## From a column
	 *
	 * `SomeTable->exists('one_column', 'a value');`
	 *
	 * ## From several columns
	 *
	 * `SomeTable->exists(['one_column', 'other_column'], ['value for one', 'value for other']);`
	 *
	 * To check on more than one columne, `$mName` and `$mValue` must both be
	 * indexed arrays and count the same number of elements.
	 *
	 * @param string|array $mName Column name to checks.
	 * @param mixed|array $sValue Value to find.
	 * @param integer $iExceptId Record ID to exclude from search.
	 * @return boolean
	 * @throws UnexpectedValueException if `$mName` and `$sValue` types and counts doesn't match.
	 */
	public function exists(
		string|array $mName, mixed $mValue, int $iExceptId = 0
	): bool
	{
		if (
			(is_array($mName) && !is_array($mValue))
			|| (is_array($mValue) && !is_array($mName))
		)
			throw new UnexpectedValueException(
				'Parameters 1 & 2 must be arrays together.'
			);

		$aFields = [];
		$aWhere = [];

		if (is_array($mName))
		{
			if (count($mName) <> count($mValue))
				throw new UnexpectedValueException(
					'Parameters 1 & 2 must have the same number of elements.'
				);

			foreach ($mName as $i => $sName)
				$aFields[$sName] = $mValue[$i];
		}
		else $aFields = [$mName => $mValue];

		$oQuery = $this->oDb->query();

		foreach ($aFields as $sFieldName => $mFieldValue)
		{
			$aWhere[] = $oQuery->esc($sFieldName) .' = :'. $oQuery->esc($sFieldName, false);
			$oQuery->setParameter($sFieldName, $mFieldValue);
		}

		if ($iExceptId)
		{
			$aWhere[] = static::COL_ID_NAME .' <> :id';
			$oQuery->setParameter('id', $iExceptId, \PDO::PARAM_INT);
		}

		return (0 < (int) $oQuery
			->select('COUNT('. static::COL_ID_NAME .')')
			->from(static::TABLE_NAME)
			->where(implode(' AND ', $aWhere))
			->run()
			->fetchColumn()
		);
	}

	/**
	 * Count on table.
	 *
	 * @param string|array $mWhere WHERE clause string or array.
	 * @param string $sLimit LIMIT clause string.
	 * @param array $aOthers Array of other clauses ('joins', 'groupBy' and 'having').
	 * @return integer
	 */
	public function count(
		string|array $mWhere = '', string $sLimit = '', array $aOthers = []
	): int
	{
		return (int) $this
			->buildQueryRows(
				'COUNT('. static::COL_ID_NAME .')',
				$mWhere, '', $sLimit, $aOthers
			)
			->run()
			->fetchColumn()
		;
	}

	/**
	 * Get a table for select field options (or others fields).
	 *
	 * # Options
	 *
	 * Le tableau d'options supplémentaires supporte les entrées 'where', 'orderby', 'limit' et
	 * 'others'. Ces entrées correspondent aux paramètres de la méthode `Table::findRows()`.
	 *
	 * @see self::findRows() to learn more about 'others' entry from `$aOptions` parameter.
	 * @param string $sLabelColumn Column to select as the values.
	 * @param array $aOptions Options for the SELECT query (supported : 'where', 'orderby', 'limit' and 'others')
	 * @return array Array of ID => {$sLabelColumn} pairs.
	 */
	public function getSelectableArray(string $sLabelColumn, array $aOptions = array()): array
	{
		$aResult = [];
		$aOptions = array_merge(
			['where' => '', 'orderby' => '', 'limit' => '', 'others' => []],
			$aOptions
		);

		$oRows = $this->findRows(
			[static::COL_ID_NAME, $sLabelColumn],
			$aOptions['where'],
			(!is_null($aOptions['orderby']) ? $aOptions['orderby'] : $sLabelColumn),
			$aOptions['limit'],
			$aOptions['others']
		);

		while ($oRows && ($oRow = $oRows->fetch()))
			$aResult[$oRow->{static::COL_ID_NAME}] = $oRow->$sLabelColumn;

		return $aResult;
	}

	/**
	 * Get a model filter for a `filter_*()` function.
	 *
	 * To learn more about the possible values of `$mVars`, refer to the method
	 * documentation associate to the model used (`makeFilter*()`).
	 *
	 * @param string $sTemplateName Filter model name.
	 * @param mixed $mVars Additional variables used depending on the requested filter.
	 * @return array|null
	 */
	public function getFilter(string $sTemplateName, mixed $mVars = null): array|null
	{
		Hooks::do('db.table.get.filters', [
			'aFiltersTemplates' => &self::$aFiltersTemplates
		]);

		if (!isset(self::$aFiltersTemplates[$sTemplateName]))
			return null;

		$aFilter = self::$aFiltersTemplates[$sTemplateName];

		switch ($sTemplateName)
		{
			case 'validate_enum':
				static::makeFilterValidateEnum($aFilter, $mVars);
				break;
			default:
				Hooks::do('db.table.make.filter', [
					'sTemplateName'	=> $sTemplateName,
					'aFilter'		=> &$aFilter,
					'mVars'			=> $mVars
				]);
		}

		return $aFilter;
	}

	/**
	 * Build the SELECT query of a record.
	 *
	 * @param string|array $mSelect SELECT clause contents.
	 * @param integer $id Record ID.
	 * @return Query
	 */
	private function buildQueryRow(string|array $mSelect, int $id): Query
	{
		if (is_array($mSelect))
			$mSelect = implode(', ', $mSelect);

		if ($mSelect == '')
			return false;

		return $this->oDb->query()
			->select($mSelect)
			->from(static::TABLE_NAME)
			->where(static::COL_ID_NAME .' = :id')
			->setParameter('id', $id, \PDO::PARAM_INT)
		;
	}

	/**
	 * Build the record search query.
	 *
	 * @param string|array $mSelect SELECT clause contents.
	 * @param string|array $mWhere WHERE clause contents.
	 * @param string|array $mOrderBy ORDER BY clause contents.
	 * @param string $sLimit LIMIT clause contents.
	 * @param array $aOthers Array of other clauses ('joins', 'groupBy' and 'having').
	 * @return Query
	 */
	private function buildQueryRows(
		string|array $mSelect = '*',
		string|array $mWhere = '',
		string|array $mOrderBy = '',
		string $sLimit = '',
		array $aOthers = []
	): Query
	{
		if (is_array($mSelect))
			$mSelect = implode(', ', $mSelect);

		if ($mSelect == '')
			return false;

		if (is_array($mWhere))
			$mWhere = implode(' AND ', $mWhere);

		if (is_array($mOrderBy))
			$mOrderBy = implode(', ', $mOrderBy);

		return $this->oDb->query()
			->select((string) $mSelect)
			->from(static::TABLE_NAME)
			->joins(arrayValue($aOthers, 'joins', []))
			->where((string) $mWhere)
			->groupBy(arrayValue($aOthers, 'groupBy', ''))
			->having(arrayValue($aOthers, 'having', ''))
			->orderBy((string) $mOrderBy)
			->limit((string) $sLimit)
		;
	}

	/**
	 * Make the enum validation filter.
	 *
	 * `$mValues` can received the following values :
	 *
	 * - `null` : '0|1', by default, standard enumeration,
	 * - `string` : 'a|b|c', custom enumeration,
	 * - `string` : ':column' to retreive the values defined in database (run a query),
	 * - `array` : array('a', 'b', 'c'), custom enumeration too but by array.
	 *
	 * @param array $aFilter Filter to modify.
	 * @param string|array|null $mValues Allowed enumeration values.
	 */
	private function makeFilterValidateEnum(array &$aFilter, string|array|null $mValues)
	{
		if (is_null($mValues))
			$mValues = '0|1';
		elseif (is_array($mValues))
			$mValues = implode('|', $mValues);
		else
		{
			$mValues = (string) $mValues;

			if ($mValues[0] == ':')
			{
				$sFieldName = substr($mValues, 1);
				$aEnumValues = $this->oDb->getEnumValues(static::TABLE_NAME, $sFieldName);

				if (!$aEnumValues)
					throw new UnexpectedValueException(
						'Filter "validate_enum" : unknown field "'. $sFieldName .'".'
					);

				$mValues = implode('|', $aEnumValues);
			}
			else $mValues = str_replace(',', '|', $mValues);
		}

		$aFilter['options']['regexp'] = sprintf($aFilter['options']['regexp'], $mValues);
	}
}