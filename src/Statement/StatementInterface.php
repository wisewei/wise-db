<?php
namespace ZendDb\Statement;

use ZendDb\Statement\Exception\Statement as StatementException;

interface StatementInterface
{

    /**
     * Bind a column of the statement result set to a PHP variable.
     *
     * @param string $column Name the column in the result set, either by
     *                       position or by name.
     * @param mixed  $param  Reference to the PHP variable containing the value.
     * @param mixed  $type   OPTIONAL
     * @return bool
     * @throws StatementException
     */
    public function bindColumn(string $column, &$param, $type = null): bool;

    /**
     * Binds a parameter to the specified variable name.
     *
     * @param mixed $parameter Name the parameter, either integer or string.
     * @param mixed $variable  Reference to PHP variable containing the value.
     * @param mixed $type      OPTIONAL Datatype of SQL parameter.
     * @param mixed $length    OPTIONAL Length of SQL parameter.
     * @param mixed $options   OPTIONAL Other options.
     * @return bool
     * @throws StatementException
     */
    public function bindParam($parameter, &$variable, $type = null, $length = null, $options = null): bool;

    /**
     * Binds a value to a parameter.
     *
     * @param mixed $parameter Name the parameter, either integer or string.
     * @param mixed $value     Scalar value to bind to the parameter.
     * @param mixed $type      OPTIONAL Datatype of the parameter.
     * @return bool
     * @throws StatementException
     */
    public function bindValue($parameter, $value, $type = null): bool;

    /**
     * Closes the cursor, allowing the statement to be executed again.
     *
     * @return bool
     * @throws StatementException
     */
    public function closeCursor(): bool;

    /**
     * Returns the number of columns in the result set.
     * Returns null if the statement has no result set metadata.
     *
     * @return int The number of columns.
     * @throws StatementException
     */
    public function columnCount(): int;

    /**
     * Retrieves the error code, if any, associated with the last operation on
     * the statement handle.
     *
     * @return string error code.
     * @throws StatementException
     */
    public function errorCode(): string;

    /**
     * Retrieves an array of error information, if any, associated with the
     * last operation on the statement handle.
     *
     * @return array
     * @throws StatementException
     */
    public function errorInfo(): array;

    /**
     * Executes a prepared statement.
     *
     * @param array $params OPTIONAL Values to bind to parameter placeholders.
     * @return bool
     * @throws StatementException
     */
    public function execute(array $params = array()): bool;

    /**
     * Fetches a row from the result set.
     *
     * @param int|null $style OPTIONAL Fetch mode for this fetch operation.
     * @param int|null $cursor OPTIONAL Absolute, relative, or other.
     * @param int|null $offset OPTIONAL Number for absolute or relative cursors.
     * @return array Array, object, or scalar depending on fetch mode.
     * @throws StatementException
     */
    public function fetch(int $style = null, int $cursor = null, int $offset = null): array;

    /**
     * Returns an array containing all of the result set rows.
     *
     * @param int|null $style OPTIONAL Fetch mode.
     * @param int|null $col OPTIONAL Column number, if fetch mode is by column.
     * @return array Collection of rows, each in a format by the fetch mode.
     * @throws StatementException
     */
    public function fetchAll(int $style = null, int $col = null): array;

    /**
     * Returns a single column from the next row of a result set.
     *
     * @param int $col OPTIONAL Position of the column to fetch.
     * @return string
     * @throws StatementException
     */
    public function fetchColumn(int $col = 0): string;

    /**
     * Fetches the next row and returns it as an object.
     *
     * @param string $class  OPTIONAL Name of the class to create.
     * @param array  $config OPTIONAL Constructor arguments for the class.
     * @return mixed One object instance of the specified class.
     */
    public function fetchObject(string $class = 'stdClass', array $config = array());

    /**
     * Retrieve a statement attribute.
     *
     * @param string $key Attribute name.
     * @return mixed      Attribute value.
     */
    public function getAttribute(string $key);

    /**
     * Retrieves the next rowset (result set) for a SQL statement that has
     * multiple result sets.  An example is a stored procedure that returns
     * the results of multiple queries.
     *
     * @return bool
     */
    public function nextRowset(): bool;

    /**
     * Returns the number of rows affected by the execution of the
     * last INSERT, DELETE, or UPDATE statement executed by this
     * statement object.
     *
     * @return int     The number of rows affected.
     */
    public function rowCount(): int;

    /**
     * Set a statement attribute.
     *
     * @param string $key Attribute name.
     * @param mixed  $val Attribute value.
     * @return bool
     */
    public function setAttribute(string $key, $val): bool;

    /**
     * Set the default fetch mode for this statement.
     *
     * @param int   $mode The fetch mode.
     * @return bool
     * @throws StatementException
     */
    public function setFetchMode(int $mode): bool;
}