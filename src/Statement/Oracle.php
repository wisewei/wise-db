<?php
namespace WiseDb\Statement;

use WiseDb\WiseDb;
use WiseDb\Statement;
use WiseDb\Statement\Exception\Statement as StatementException;
use WiseDb\Statement\Exception\Oracle as OracleStatementException;
use WiseDb\Adapter\Exception\Adapter as AdapterException;
use WiseDb\Adapter\Exception\Oracle as OracleAdapterException;

class Oracle extends Statement
{

    /**
     * Column names.
     */
    protected $_keys;

    /**
     * Fetched result values.
     */
    protected $_values;

    /**
     * Check if LOB field are returned as string
     * instead of OCI-Lob object
     *
     * @var boolean
     */
    protected $_lobAsString = false;

    /**
     * Activate/deactivate return of LOB as string
     *
     * @param string $lob_as_string
     * @return $this
     */
    public function setLobAsString(string $lob_as_string): Statement
    {
        $this->_lobAsString = (bool) $lob_as_string;
        return $this;
    }

    /**
     * Return whether or not LOB are returned as string
     *
     * @return bool
     */
    public function getLobAsString(): bool
    {
        return $this->_lobAsString;
    }

    /**
     * Prepares statement handle
     *
     * @param string $sql
     * @return void
     * @throws OracleStatementException
     */
    protected function _prepare($sql)
    {
        $connection = $this->_adapter->getConnection();
        $this->_stmt = oci_parse($connection, $sql);
        if (! $this->_stmt) {
            throw new OracleStatementException(oci_error($connection));
        }
    }

    /**
     * Binds a parameter to the specified variable name.
     *
     * @param mixed $parameter Name the parameter, either integer or string.
     * @param mixed $variable  Reference to PHP variable containing the value.
     * @param mixed $type      OPTIONAL Datatype of SQL parameter.
     * @param mixed $length    OPTIONAL Length of SQL parameter.
     * @return bool
     * @throws AdapterException
     */
    protected function _bindParam($parameter, &$variable, $type = null, $length = null): bool
    {
        // default value
        if ($type === NULL) {
            $type = SQLT_CHR;
        }

        // default value
        if ($length === NULL) {
            $length = - 1;
        }

        $retval = @oci_bind_by_name($this->_stmt, $parameter, $variable, $length, $type);
        if ($retval === false) {
            /**
             * @see OracleAdapterException
             */
            throw new OracleAdapterException(oci_error($this->_stmt));
        }

        return true;
    }

    /**
     * Closes the cursor, allowing the statement to be executed again.
     *
     * @return bool
     */
    public function closeCursor(): bool
    {
        if (! $this->_stmt) {
            return false;
        }

        oci_free_statement($this->_stmt);
        $this->_stmt = false;
        return true;
    }

    /**
     * Returns the number of columns in the result set.
     * Returns null if the statement has no result set metadata.
     *
     * @return int The number of columns.
     */
    public function columnCount(): int
    {
        if (! $this->_stmt) {
            return false;
        }

        return oci_num_fields($this->_stmt);
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
            return '';
        }

        $error = oci_error($this->_stmt);

        if (! $error) {
            return '';
        }

        return $error['code'];
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

        $error = oci_error($this->_stmt);
        if (! $error) {
            return [];
        }

        if (isset($error['sqltext'])) {
            return array(
                $error['code'],
                $error['message'],
                $error['offset'],
                $error['sqltext']
            );
        } else {
            return array(
                $error['code'],
                $error['message']
            );
        }
    }

    /**
     * Executes a prepared statement.
     *
     * @param array|null $params OPTIONAL Values to bind to parameter placeholders.
     * @return bool
     * @throws StatementException
     */
    public function _execute(array $params = null): bool
    {
        $this->_adapter->getConnection();

        if (! $this->_stmt) {
            return false;
        }

        if ($params !== null) {
            if (! is_array($params)) {
                $params = array(
                    $params
                );
            }
            $error = false;
            foreach (array_keys($params) as $name) {
                if (! @oci_bind_by_name($this->_stmt, $name, $params[$name], - 1)) {
                    $error = true;
                    break;
                }
            }
            if ($error) {
                throw new OracleStatementException(oci_error($this->_stmt));
            }
        }

        $retval = @oci_execute($this->_stmt, $this->_adapter->_getExecuteMode());
        if ($retval === false) {
            throw new OracleStatementException(oci_error($this->_stmt));
        }

        $this->_keys = [];
        $field_num = oci_num_fields($this->_stmt);
        if ($field_num) {
            for ($i = 1; $i <= $field_num; $i ++) {
                $name = oci_field_name($this->_stmt, $i);
                $this->_keys[] = $name;
            }
        }

        $this->_values = [];
        if ($this->_keys) {
            $this->_values = array_fill(0, count($this->_keys), null);
        }

        return $retval;
    }

    /**
     * Fetches a row from the result set.
     *
     * @param int|null $style  OPTIONAL Fetch mode for this fetch operation.
     * @param int|null $cursor OPTIONAL Absolute, relative, or other.
     * @param int|null $offset OPTIONAL Number for absolute or relative cursors.
     * @return mixed Array, object, or scalar depending on fetch mode.
     * @throws StatementException|OracleAdapterException
     */
    public function fetch(int $style = null, int $cursor = null, int $offset = null): array
    {
        if (! $this->_stmt) {
            return [];
        }

        if ($style === null) {
            $style = $this->_fetchMode;
        }

        $lob_as_string = $this->getLobAsString() ? OCI_RETURN_LOBS : 0;

        switch ($style) {
            case WiseDb::FETCH_NUM:
                $row = oci_fetch_array($this->_stmt, OCI_NUM | OCI_RETURN_NULLS | $lob_as_string);
                break;
            case WiseDb::FETCH_ASSOC:
                $row = oci_fetch_array($this->_stmt, OCI_ASSOC | OCI_RETURN_NULLS | $lob_as_string);
                break;
            case WiseDb::FETCH_BOTH:
                $row = oci_fetch_array($this->_stmt, OCI_BOTH | OCI_RETURN_NULLS | $lob_as_string);
                break;
            case WiseDb::FETCH_OBJ:
                $row = oci_fetch_object($this->_stmt);
                break;
            case WiseDb::FETCH_BOUND:
                $row = oci_fetch_array($this->_stmt, OCI_BOTH | OCI_RETURN_NULLS | $lob_as_string);
                if ($row !== false) {
                    return $this->_fetchBound($row);
                }
                break;
            default:
                /**
                 * @see OracleStatementException
                 */
                throw new OracleStatementException(array(
                    'code' => 'HYC00',
                    'message' => "Invalid fetch mode '$style' specified"
                ));
        }

        if (! $row && $error = oci_error($this->_stmt)) {
            /**
             * @see OracleAdapterException
             */
            throw new OracleAdapterException($error);
        }

        if (is_array($row) && array_key_exists('zend_db_rownum', $row)) {
            unset($row['zend_db_rownum']);
        }

        return $row;
    }

    /**
     * Returns an array containing all of the result set rows.
     *
     * @param int|null $style OPTIONAL Fetch mode.
     * @param int|null $col   OPTIONAL Column number, if fetch mode is by column.
     * @return array|bool Collection of rows, each in a format by the fetch mode.
     * @throws StatementException
     * @throws AdapterException
     */
    public function fetchAll(int $style = null, $col = 0): array
    {
        if (! $this->_stmt) {
            return false;
        }

        // make sure we have a fetch mode
        if ($style === null) {
            $style = $this->_fetchMode;
        }

        $flags = OCI_FETCHSTATEMENT_BY_ROW;

        switch ($style) {
            case WiseDb::FETCH_BOTH:
                /**
                 * @see OracleAdapterException
                 */
                throw new OracleAdapterException(array(
                    'code' => 'HYC00',
                    'message' => "OCI8 driver does not support fetchAll(FETCH_BOTH), use fetch() in a loop instead"
                ));
            // notreached
            //$flags |= OCI_NUM;
            //$flags |= OCI_ASSOC;
            //break;
            case WiseDb::FETCH_NUM:
                $flags |= OCI_NUM;
                break;
            case WiseDb::FETCH_ASSOC:
                $flags |= OCI_ASSOC;
                break;
            case WiseDb::FETCH_OBJ:
                break;
            case WiseDb::FETCH_COLUMN:
                $flags = $flags & ~ OCI_FETCHSTATEMENT_BY_ROW;
                $flags |= OCI_FETCHSTATEMENT_BY_COLUMN;
                $flags |= OCI_NUM;
                break;
            default:
                throw new OracleAdapterException(array(
                    'code' => 'HYC00',
                    'message' => "Invalid fetch mode '$style' specified"
                ));
        }

        $result = Array();
        if ($flags != OCI_FETCHSTATEMENT_BY_ROW) { /* not Zend_Db::FETCH_OBJ */
            $rows = oci_fetch_all($this->_stmt, $result, 0, - 1, $flags);
            if (! $rows) {
                $error = oci_error($this->_stmt);
                if ($error) {
                    throw new OracleAdapterException($error);
                }
                return array();
            }
            if ($style == WiseDb::FETCH_COLUMN) {
                $result = $result[$col];
            }
            foreach ($result as &$row) {
                if (is_array($row) && array_key_exists('zend_db_rownum', $row)) {
                    unset($row['zend_db_rownum']);
                }
            }
        } else {
            while (($row = oci_fetch_object($this->_stmt)) !== false) {
                $result[] = $row;
            }
            $error = oci_error($this->_stmt);
            if ($error) {
                throw new OracleStatementException($error);
            }
        }
        return $result;
    }

    /**
     * Returns a single column from the next row of a result set.
     *
     * @param int $col OPTIONAL Position of the column to fetch.
     * @return string
     * @throws StatementException
     */
    public function fetchColumn(int $col = 0): string
    {
        if (! $this->_stmt) {
            return false;
        }

        if (! oci_fetch($this->_stmt)) {
            // if no error, there is simply no record
            if (! $error = oci_error($this->_stmt)) {
                return false;
            }
            /**
             * @see OracleStatementException
             */
            throw new OracleStatementException($error);
        }

        $data = oci_result($this->_stmt, $col + 1); //1-based
        if ($data === false) {
            /**
             * @see OracleStatementException
             */
            throw new OracleStatementException(oci_error($this->_stmt));
        }

        if ($this->getLobAsString()) {
            // instanceof doesn't allow '-', we must use a temporary string
            $type = 'OCI-Lob';
            if ($data instanceof $type) {
                $data = $data->read($data->size());
            }
        }

        return $data;
    }

    /**
     * Fetches the next row and returns it as an object.
     *
     * @param string $class  OPTIONAL Name of the class to create.
     * @param array  $config OPTIONAL Constructor arguments for the class.
     * @return false|object One object instance of the specified class.
     * @throws StatementException
     */
    public function fetchObject(string $class = 'stdClass', array $config = array())
    {
        if (! $this->_stmt) {
            return false;
        }

        $obj = oci_fetch_object($this->_stmt);
        $error = oci_error($this->_stmt);
        if ($error) {
            throw new OracleStatementException($error);
        }
        return $obj;
    }

    /**
     * Retrieves the next rowset (result set) for a SQL statement that has
     * multiple result sets.  An example is a stored procedure that returns
     * the results of multiple queries.
     *
     * @return bool
     * @throws StatementException
     */
    public function nextRowset(): bool
    {
        /**
         * @see OracleStatementException
         */
        throw new OracleStatementException(array(
            'code' => 'HYC00',
            'message' => 'Optional feature not implemented'
        ));
    }

    /**
     * Returns the number of rows affected by the execution of the
     * last INSERT, DELETE, or UPDATE statement executed by this
     * statement object.
     *
     * @return int     The number of rows affected.
     * @throws StatementException
     */
    public function rowCount(): int
    {
        if (! $this->_stmt) {
            return 0;
        }
        $num_rows = oci_num_rows($this->_stmt);
        if ($num_rows === false) {
            throw new OracleStatementException(oci_error($this->_stmt));
        }
        return $num_rows;
    }
}