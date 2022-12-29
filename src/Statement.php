<?php
namespace ZendDb;

use ZendDb\Adapter\AbstractAdapter;
use ZendDb\Adapter\Exception\Adapter as AdapterException;
use ZendDb\Statement\StatementInterface;
use ZendDb\Profiler\Query as ProfilerQuery;
use ZendDb\Statement\Exception\Statement as StatementException;

/**
 * Abstract class to emulate a PDOStatement for native database adapters.
 *
 * @category   Zend
 * @package    ZendDb
 * @subpackage Statement
 * @copyright  Copyright (c) 2005-2009 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
abstract class Statement implements StatementInterface
{

    /**
     * @var resource The driver level statement object/resource
     */
    protected $_stmt = null;

    /**
     * @var AbstractAdapter
     */
    protected $_adapter = null;

    /**
     * The current fetch mode.
     *
     * @var integer
     */
    protected $_fetchMode = ZendDb::FETCH_ASSOC;

    /**
     * Attributes.
     *
     * @var array
     */
    protected $_attribute = array();

    /**
     * Column result bindings.
     *
     * @var array
     */
    protected $_bindColumn = array();

    /**
     * Query parameter bindings; covers bindParam() and bindValue().
     *
     * @var array
     */
    protected $_bindParam = array();

    /**
     * SQL string split into an array at placeholders.
     *
     * @var array
     */
    protected $_sqlSplit = array();

    /**
     * Parameter placeholders in the SQL string by position in the split array.
     *
     * @var array
     */
    protected $_sqlParam = array();

    /**
     * @var ProfilerQuery
     */
    protected $_queryId = null;

    /**
     * Constructor for a statement.
     *
     * @param AbstractAdapter $adapter
     * @param mixed $sql Either a string or Zend_Db_Select.
     * @throws StatementException
     */
    public function __construct(AbstractAdapter $adapter, $sql)
    {
        $this->_adapter = $adapter;
        $this->_parseParameters($sql);
        $this->_prepare($sql);

        $this->_queryId = $this->_adapter->getProfiler()->queryStart($sql);
    }

    /**
     * Internal method called by abstract statment constructor to setup
     * the driver level statement
     */
    protected function _prepare($sql)
    {
    }

    /**
     * @param string $sql
     * @throws StatementException
     */
    protected function _parseParameters(string $sql)
    {
        $sql = $this->_stripQuoted($sql);

        // split into text and params
        $this->_sqlSplit = preg_split('/(\?|\:[a-zA-Z0-9_]+)/', $sql, - 1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        // map params
        $this->_sqlParam = array();
        foreach ($this->_sqlSplit as $val) {
            if ($val == '?') {
                if ($this->_adapter->supportsParameters('positional') === false) {
                    throw new StatementException("Invalid bind-variable position '$val'");
                }
            } else if ($val[0] == ':') {
                if ($this->_adapter->supportsParameters('named') === false) {
                    /**
                     * @see StatementException
                     */
                    throw new StatementException("Invalid bind-variable name '$val'");
                }
            }
            $this->_sqlParam[] = $val;
        }

        // set up for binding
        $this->_bindParam = array();
    }

    /**
     * Remove parts of a SQL string that contain quoted strings
     * of values or identifiers.
     *
     * @param string $sql
     * @return string
     */
    protected function _stripQuoted(string $sql): string
    {
        // get the character for delimited id quotes,
        // this is usually " but in MySQL is `
        $d = $this->_adapter->quoteIdentifier('a');
        $d = $d[0];

        // get the value used as an escaped delimited id quote,
        // e.g. \" or "" or \`
        $de = $this->_adapter->quoteIdentifier($d);
        $de = substr($de, 1, 2);
        $de = str_replace('\\', '\\\\', $de);

        // get the character for value quoting
        // this should be '
        $q = $this->_adapter->quote('a');
        $q = $q[0];

        // get the value used as an escaped quote,
        // e.g. \' or ''
        $qe = $this->_adapter->quote($q);
        $qe = substr($qe, 1, 2);
        $qe = str_replace('\\', '\\\\', $qe);

        // get a version of the SQL statement with all quoted
        // values and delimited identifiers stripped out
        // remove "foo\"bar"
        $sql = preg_replace("/$q($qe|\\\\{2}|[^$q])*$q/", '', $sql);
        // remove 'foo\'bar'
        if (! empty($q)) {
            $sql = preg_replace("/$q($qe|[^$q])*$q/", '', $sql);
        }

        return $sql;
    }

    /**
     * Bind a column of the statement result set to a PHP variable.
     *
     * @param string $column Name the column in the result set, either by
     *                       position or by name.
     * @param mixed  $param  Reference to the PHP variable containing the value.
     * @param mixed  $type   OPTIONAL
     * @return bool
     */
    public function bindColumn(string $column, &$param, $type = null): bool
    {
        $this->_bindColumn[$column] = & $param;
        return true;
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
     * @throws StatementException
     * @throws AdapterException
     */
    public function bindParam($parameter, &$variable, $type = null, $length = null, $options = null): bool
    {
        if (! is_int($parameter) && ! is_string($parameter)) {
            /**
             * @see StatementException
             */
            throw new StatementException('Invalid bind-variable position');
        }

        $position = null;
        if (($intval = (int) $parameter) > 0 && $this->_adapter->supportsParameters('positional')) {
            if ($intval >= 1 || $intval <= count($this->_sqlParam)) {
                $position = $intval;
            }
        } else if ($this->_adapter->supportsParameters('named')) {
            if ($parameter[0] != ':') {
                $parameter = ':' . $parameter;
            }
            if (in_array($parameter, $this->_sqlParam) !== false) {
                $position = $parameter;
            }
        }

        if ($position === null) {
            /**
             * @see StatementException
             */
            throw new StatementException("Invalid bind-variable position '$parameter'");
        }

        // Finally we are assured that $position is valid
        $this->_bindParam[$position] = & $variable;
        return $this->_bindParam($position, $variable, $type, $length, $options);
    }

    /**
     * Binds a value to a parameter.
     *
     * @param mixed $parameter Name the parameter, either integer or string.
     * @param mixed $value     Scalar value to bind to the parameter.
     * @param mixed $type      OPTIONAL Datatype of the parameter.
     * @return bool
     * @throws StatementException
     * @throws AdapterException
     */
    public function bindValue($parameter, $value, $type = null): bool
    {
        return $this->bindParam($parameter, $value, $type);
    }

    /**
     * Executes a prepared statement.
     *
     * @param array|null $params OPTIONAL Values to bind to parameter placeholders.
     * @return bool
     * @throws StatementException
     * @throws Profiler\ProfilerException
     */
    public function execute(array $params = null): bool
    {
        /*
         * Simple case - no query profiler to manage.
         */
        if ($this->_queryId === null) {
            return $this->_execute($params);
        }

        /*
         * Do the same thing, but with query profiler
         * management before and after the execute.
         */
        $prof = $this->_adapter->getProfiler();
        $qp = $prof->getQueryProfile($this->_queryId);
        if ($qp->hasEnded()) {
            $this->_queryId = $prof->queryClone($qp);
            $qp = $prof->getQueryProfile($this->_queryId);
        }
        if ($params !== null) {
            $qp->bindParams($params);
        } else {
            $qp->bindParams($this->_bindParam);
        }
        $qp->start();
        $retval = $this->_execute($params);
        $prof->queryEnd($this->_queryId);
        return $retval;
    }

    /**
     * Returns an array containing all of the result set rows.
     *
     * @param int|null $style OPTIONAL Fetch mode.
     * @param int|null $col OPTIONAL Column number, if fetch mode is by column.
     * @return array Collection of rows, each in a format by the fetch mode.
     * @throws StatementException
     */
    public function fetchAll(int $style = null, int $col = null): array
    {
        $data = array();
        if ($style === ZendDb::FETCH_COLUMN && $col === null) {
            $col = 0;
        }
        if ($col === null) {
            while (true) {
                $row = $this->fetch($style);
                if($row){
                    $data[] = $row;
                }else{
                    break;
                }
            }
        } else {
            while (false !== ($val = $this->fetchColumn($col))) {
                $data[] = $val;
            }
        }
        return $data;
    }

    /**
     * Returns a single column from the next row of a result set.
     *
     * @param int $col OPTIONAL Position of the column to fetch.
     * @return string|bool One value from the next row of result set, or false.
     * @throws StatementException
     */
    public function fetchColumn(int $col = 0): string{
        $row = $this->fetch(ZendDb::FETCH_NUM);
        if (! is_array($row)) {
            return '';
        }
        return $row[$col] ?? '';
    }

    /**
     * Fetches the next row and returns it as an object.
     *
     * @param string $class OPTIONAL Name of the class to create.
     * @param array $config OPTIONAL Constructor arguments for the class.
     * @return mixed One object instance of the specified class, or false.
     * @throws StatementException
     */
    public function fetchObject(string $class = 'stdClass', array $config = array())
    {
        $obj = new $class($config);
        $row = $this->fetch(ZendDb::FETCH_ASSOC);
        if (! is_array($row)) {
            return false;
        }
        foreach ($row as $key => $val) {
            $obj->$key = $val;
        }
        return $obj;
    }

    /**
     * Retrieve a statement attribute.
     *
     * @param string $key Attribute name.
     * @return mixed      Attribute value.
     */
    public function getAttribute(string $key)
    {
        if (array_key_exists($key, $this->_attribute)) {
            return $this->_attribute[$key];
        }
        return '';
    }

    /**
     * Set a statement attribute.
     *
     * @param string $key Attribute name.
     * @param mixed  $val Attribute value.
     * @return bool
     */
    public function setAttribute(string $key, $val): bool
    {
        $this->_attribute[$key] = $val;
        return true;
    }

    /**
     * Set the default fetch mode for this statement.
     *
     * @param int   $mode The fetch mode.
     * @throws StatementException
     */
    public function setFetchMode(int $mode): bool
    {
        switch ($mode) {
            case ZendDb::FETCH_NUM:
            case ZendDb::FETCH_ASSOC:
            case ZendDb::FETCH_BOTH:
            case ZendDb::FETCH_OBJ:
                $this->_fetchMode = $mode;
                break;
            case ZendDb::FETCH_BOUND:
            default:
                $this->closeCursor();
                /**
                 * @see StatementException
                 */
                throw new StatementException('invalid fetch mode');
        }
        return true;
    }

    /**
     * Helper function to map retrieved row
     * to bound column variables
     *
     * @param array $row
     * @return bool True
     */
    public function _fetchBound(array $row): bool
    {
        foreach ($row as $key => $value) {
            // bindColumn() takes 1-based integer positions
            // but fetch() returns 0-based integer indexes
            if (is_int($key)) {
                $key ++;
            }
            // set results only to variables that were bound previously
            if (isset($this->_bindColumn[$key])) {
                $this->_bindColumn[$key] = $value;
            }
        }
        return true;
    }

    /**
     * Gets the AbstractAdapter for this
     * particular Statement object.
     *
     * @return AbstractAdapter
     */
    public function getAdapter(): AbstractAdapter
    {
        return $this->_adapter;
    }

    /**
     * Gets the resource or object setup by the
     * _parse
     * @return resource
     */
    public function getDriverStatement()
    {
        return $this->_stmt;
    }
}