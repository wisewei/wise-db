<?php
namespace ZendDb\Statement;

use ZendDb\ZendDb;
use ZendDb\Statement;
use ZendDb\Statement\Exception\Mysqli as MysqliStatementException;
use ZendDb\Statement\Exception\Statement as StatementException;

/**
 * Extends for Mysqli
 *
 * @category   Zend
 * @package    ZendDb
 * @subpackage Statement
 * @copyright  Copyright (c) 2005-2009 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
class Mysqli extends Statement
{

    /**
     * Column names.
     *
     * @var array
     */
    protected $_keys;

    /**
     * Fetched result values.
     *
     * @var array
     */
    protected $_values;

    /**
     * @var array
     */
    protected $_meta = null;

    /**
     * @param  string $sql
     * @return void
     * @throws MysqliStatementException
     */
    public function _prepare($sql)
    {
        $mysqli = $this->_adapter->getConnection();

        $this->_stmt = $mysqli->prepare($sql);

        if ($this->_stmt === false || $mysqli->errno) {
            throw new MysqliStatementException("Mysqli prepare error: " . $mysqli->error, $mysqli->errno);
        }
    }

    /**
     * Binds a parameter to the specified variable name.
     *
     * @param mixed $parameter Name the parameter, either integer or string.
     * @param mixed $variable  Reference to PHP variable containing the value.
     * @param mixed $type      OPTIONAL Datatype of SQL parameter.
     * @param mixed $length    OPTIONAL Length of SQL parameter.
     * @param mixed $options   OPTIONAL Other options.
     * @return bool
     */
    protected function _bindParam($parameter, &$variable, $type = null, $length = null, $options = null): bool
    {
        return true;
    }

    /**
     * Closes the cursor and the statement.
     *
     * @return bool
     */
    public function close(): bool
    {
        if ($this->_stmt) {
            $r = $this->_stmt->close();
            $this->_stmt = null;
            return $r;
        }
        return false;
    }

    /**
     * Closes the cursor, allowing the statement to be executed again.
     *
     * @return bool
     */
    public function closeCursor(): bool
    {
        if ($this->_stmt) {
            $mysqli = $this->_adapter->getConnection();
            while ($mysqli->more_results()) {
                $mysqli->next_result();
            }
            $this->_stmt->free_result();
            return $this->_stmt->reset();
        }
        return false;
    }

    /**
     * Returns the number of columns in the result set.
     * Returns null if the statement has no result set metadata.
     *
     * @return int The number of columns.
     */
    public function columnCount(): int
    {
        if (isset($this->_meta) && $this->_meta) {
            return $this->_meta->field_count;
        }
        return 0;
    }

    /**
     * Retrieves the error code, if any, associated with the last operation on
     * the statement handle.
     *
     * @return string error code.
     */
    public function errorCode(): string
    {
        if (! $this->_stmt) {
            return false;
        }
        return substr($this->_stmt->sqlstate, 0, 5);
    }

    /**
     * Retrieves an array of error information, if any, associated with the
     * last operation on the statement handle.
     *
     * @return array
     */
    public function errorInfo(): array
    {
        if (! $this->_stmt) {
            return [];
        }
        return array(
            substr($this->_stmt->sqlstate, 0, 5),
            $this->_stmt->errno,
            $this->_stmt->error
        );
    }

    /**
     * Executes a prepared statement.
     *
     * @param array|null $params OPTIONAL Values to bind to parameter placeholders.
     * @return bool
     * @throws MysqliStatementException
     * @throws StatementException
     */
    public function _execute(array $params = null): bool
    {
        if (! $this->_stmt) {
            return false;
        }

        // if no params were given as an argument to execute(),
        // then default to the _bindParam array
        if ($params === null) {
            $params = $this->_bindParam;
        }
        // send $params as input parameters to the statement
        if ($params) {
            array_unshift($params, str_repeat('s', count($params)));
            $stmtParams = array();
            foreach ($params as $k => &$value) {
                $stmtParams[$k] = &$value;
            }
            call_user_func_array(array(
                $this->_stmt,
                'bind_param'
            ), $stmtParams);
        }

        // execute the statement
        $retval = $this->_stmt->execute();
        if ($retval === false) {
            throw new MysqliStatementException("Mysqli statement execute error : " . $this->_stmt->error, $this->_stmt->errno);
        }

        // retain metadata
        if ($this->_meta === null) {
            $this->_meta = $this->_stmt->result_metadata();
            if ($this->_stmt->errno) {
                throw new MysqliStatementException("Mysqli statement metadata error: " . $this->_stmt->error, $this->_stmt->errno);
            }
        }

        // statements that have no result set do not return metadata
        if ($this->_meta !== false) {

            // get the column names that will result
            $this->_keys = array();
            foreach ($this->_meta->fetch_fields() as $col) {
                $this->_keys[] = $this->_adapter->foldCase($col->name);
            }

            // set up a binding space for result variables
            $this->_values = array_fill(0, count($this->_keys), null);

            // set up references to the result binding space.
            // just passing $this->_values in the call_user_func_array()
            // below won't work, you need references.
            $refs = array();
            foreach ($this->_values as $i => &$f) {
                $refs[$i] = &$f;
            }

            $this->_stmt->store_result();
            // bind to the result variables
            call_user_func_array(array(
                $this->_stmt,
                'bind_result'
            ), $this->_values);
        }
        return $retval;
    }

    /**
     * Fetches a row from the result set.
     *
     * @param int $style  OPTIONAL Fetch mode for this fetch operation.
     * @param int $cursor OPTIONAL Absolute, relative, or other.
     * @param int $offset OPTIONAL Number for absolute or relative cursors.
     * @return mixed Array, object, or scalar depending on fetch mode.
     * @throws MysqliStatementException
     */
    public function fetch(int $style = null, int $cursor = null, int $offset = null): array
    {
        if (! $this->_stmt) {
            return [];
        }
        // fetch the next result
        $retval = $this->_stmt->fetch();
        switch ($retval) {
            case null: // end of data
            case false: // error occurred
                $this->_stmt->reset();
                return [];
            default:
            // fallthrough
        }

        // make sure we have a fetch mode
        if ($style === null) {
            $style = $this->_fetchMode;
        }

        // dereference the result values, otherwise things like fetchAll()
        // return the same values for every entry (because of the reference).
        $values = array();
        foreach ($this->_values as $val) {
            $values[] = $val;
        }

        switch ($style) {
            case ZendDb::FETCH_NUM:
                $row = $values;
                break;
            case ZendDb::FETCH_ASSOC:
                $row = array_combine($this->_keys, $values);
                break;
            case ZendDb::FETCH_BOTH:
                $assoc = array_combine($this->_keys, $values);
                $row = array_merge($values, $assoc);
                break;
            case ZendDb::FETCH_OBJ:
                $row = (object) array_combine($this->_keys, $values);
                break;
            case ZendDb::FETCH_BOUND:
                $assoc = array_combine($this->_keys, $values);
                $row = array_merge($values, $assoc);
                return $this->_fetchBound($row);
            default:
                throw new MysqliStatementException("Invalid fetch mode '$style' specified");
        }
        return $row;
    }

    /**
     * Retrieves the next rowset (result set) for a SQL statement that has
     * multiple result sets.  An example is a stored procedure that returns
     * the results of multiple queries.
     *
     * @return bool
     * @throws MysqliStatementException
     */
    public function nextRowset(): bool
    {
        throw new MysqliStatementException(__FUNCTION__ . '() is not implemented');
    }

    /**
     * Returns the number of rows affected by the execution of the
     * last INSERT, DELETE, or UPDATE statement executed by this
     * statement object.
     *
     * @return int|bool     The number of rows affected.
     */
    public function rowCount(): int
    {
        if (! $this->_adapter) {
            return 0;
        }
        $mysqli = $this->_adapter->getConnection();
        return $mysqli->affected_rows;
    }
}