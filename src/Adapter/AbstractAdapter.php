<?php

namespace WiseDb\Adapter;

use WiseDb\DBException;
use WiseDb\WiseDb;
use WiseDb\Profiler;
use WiseDb\Statement;
use WiseDb\Statement\StatementInterface;
use WiseDb\Adapter\Exception\Adapter as AdapterException;

use WiseDb\Statement\Exception\Statement as StatementException;

/**
 * Class for connecting to SQL databases and performing common operations.
 *
 * @category   Zend
 * @package    Zend_Db
 * @subpackage Adapter
 * @copyright  Copyright (c) 2005-2009 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
abstract class AbstractAdapter
{

    /**
     * User-provided configuration
     *
     * @var array
     */
    protected $_config = array();

    /**
     * Fetch mode
     *
     * @var int
     */
    protected $_fetchMode = WiseDb::FETCH_ASSOC;

    /**
     * Query profiler object, of type Zend_Db_Profiler
     * or a subclass of that.
     *
     * @var Profiler
     */
    protected $_profiler;

    /**
     * Database connection
     *
     * @var resource|null
     */
    protected $_connection = null;

    /**
     * Specifies the case of column names retrieved in queries
     * Options
     * WiseDb::CASE_NATURAL (default)
     * WiseDb::CASE_LOWER
     * WiseDb::CASE_UPPER
     *
     * @var integer
     */
    protected $_caseFolding = WiseDb::CASE_NATURAL;

    /**
     * Specifies whether the adapter automatically quotes identifiers.
     * If true, most SQL generated by Zend_Db classes applies
     * identifier quoting automatically.
     * If false, developer must quote identifiers themselves
     * by calling quoteIdentifier().
     *
     * @var bool
     */
    protected $_autoQuoteIdentifiers = true;

    /**
     * Keys are UPPERCASE SQL datatypes or the constants
     * WiseDb::INT_TYPE, Zend_Db::BIGINT_TYPE, or Zend_Db::FLOAT_TYPE.
     *
     * Values are:
     * 0 = 32-bit integer
     * 1 = 64-bit integer
     * 2 = float or decimal
     *
     * @var array Associative array of datatypes to values 0, 1, or 2.
     */
    protected $_numericDataTypes = array(
        WiseDb::INT_TYPE    => WiseDb::INT_TYPE,
        WiseDb::BIGINT_TYPE => WiseDb::BIGINT_TYPE,
        WiseDb::FLOAT_TYPE  => WiseDb::FLOAT_TYPE
    );

    /** Weither or not that object can get serialized
     *
     * @var bool
     */
    protected $_allowSerialization = true;

    /**
     * Weither or not the database should be reconnected
     * to that adapter when waking up
     *
     * @var bool
     */
    protected $_autoReconnectOnUnserialize = false;

    /**
     * Constructor.
     *
     * $config is an array of key/value pairs or an instance of Zend_Config
     * containing configuration options.  These options are common to most adapters:
     *
     * dbname         => (string) The name of the database to user
     * username       => (string) Connect to the database as this username.
     * password       => (string) Password associated with the username.
     * host           => (string) What host to connect to, defaults to localhost
     *
     * Some options are used on a case-by-case basis by adapters:
     *
     * port           => (string) The port of the database
     * persistent     => (boolean) Whether to use a persistent connection or not, defaults to false
     * protocol       => (string) The network protocol, defaults to TCPIP
     * caseFolding    => (int) style of case-alteration used for identifiers
     *
     * @param  array $config An array or instance of Zend_Config having configuration data
     * @throws AdapterException
     */
    public function __construct(array $config)
    {

        $this->_checkRequiredOptions($config);

        $options = array(
            WiseDb::CASE_FOLDING           => $this->_caseFolding,
            WiseDb::AUTO_QUOTE_IDENTIFIERS => $this->_autoQuoteIdentifiers
        );
        $driverOptions = array();

        /*
         * normalize the config and merge it with the defaults
         */
        if (array_key_exists('options', $config)) {
            // can't use array_merge() because keys might be integers
            foreach ((array) $config['options'] as $key => $value) {
                $options[$key] = $value;
            }
        }
        if (array_key_exists('driver_options', $config)) {
            if (!empty($config['driver_options'])) {
                // can't use array_merge() because keys might be integers
                foreach ((array) $config['driver_options'] as $key => $value) {
                    $driverOptions[$key] = $value;
                }
            }
        }

        if (!isset($config['charset'])) {
            $config['charset'] = null;
        }

        if (!isset($config['persistent'])) {
            $config['persistent'] = false;
        }

        $this->_config = array_merge($this->_config, $config);
        $this->_config['options'] = $options;
        $this->_config['driver_options'] = $driverOptions;


        // obtain the case setting, if there is one
        if (array_key_exists(WiseDb::CASE_FOLDING, $options)) {
            $case = (int) $options[WiseDb::CASE_FOLDING];
            switch ($case) {
                case WiseDb::CASE_LOWER:
                case WiseDb::CASE_UPPER:
                case WiseDb::CASE_NATURAL:
                    $this->_caseFolding = $case;
                    break;
                default:
                    /**
                     * @see AdapterException
                     */
                    throw new AdapterException('Case must be one of the following constants: '
                        . 'WiseDb::CASE_NATURAL, WiseDb::CASE_LOWER, WiseDb::CASE_UPPER');
            }
        }

        // obtain quoting property if there is one
        if (array_key_exists(WiseDb::AUTO_QUOTE_IDENTIFIERS, $options)) {
            $this->_autoQuoteIdentifiers = (bool) $options[WiseDb::AUTO_QUOTE_IDENTIFIERS];
        }

        // obtain allow serialization property if there is one
        if (array_key_exists(WiseDb::ALLOW_SERIALIZATION, $options)) {
            $this->_allowSerialization = (bool) $options[WiseDb::ALLOW_SERIALIZATION];
        }

        // obtain auto reconnect on unserialize property if there is one
        if (array_key_exists(WiseDb::AUTO_RECONNECT_ON_UNSERIALIZE, $options)) {
            $this->_autoReconnectOnUnserialize = (bool) $options[WiseDb::AUTO_RECONNECT_ON_UNSERIALIZE];
        }

        // create a profiler object
    }

    /**
     * Check for config options that are mandatory.
     * Throw exceptions if any are missing.
     *
     * @param array $config
     * @throws AdapterException
     */
    protected function _checkRequiredOptions(array $config)
    {
        // we need at least a dbname
        if (! array_key_exists('dbname', $config)) {
            /** @see AdapterException */
            throw new AdapterException("Configuration array must have a key for 'dbname' that names the database instance");
        }

        if (! array_key_exists('password', $config)) {
            /**
             * @see AdapterException
             */
            throw new AdapterException("Configuration array must have a key for 'password' for login credentials");
        }

        if (! array_key_exists('username', $config)) {
            /**
             * @see AdapterException
             */
            throw new AdapterException("Configuration array must have a key for 'username' for login credentials");
        }
    }

    /**
     * Returns the underlying database connection object or resource.
     * If not presently connected, this initiates the connection.
     *
     * @return object|resource|null
     */
    public function getConnection()
    {
        $this->_connect();
        return $this->_connection;
    }

    /**
     * Set the adapter's profiler object.
     *
     * The argument may be a boolean, an associative array, an instance of
     * Zend_Db_Profiler, or an instance of Zend_Config.
     *
     * A boolean argument sets the profiler to enabled if true, or disabled if
     * false.  The profiler class is the adapter's default profiler class,
     * Zend_Db_Profiler.
     *
     * An instance of Zend_Db_Profiler sets the adapter's instance to that
     * object.  The profiler is enabled and disabled separately.
     *
     * An associative array argument may contain any of the keys 'enabled',
     * 'class', and 'instance'. The 'enabled' and 'instance' keys correspond to the
     * boolean and object types documented above. The 'class' key is used to name a
     * class to use for a custom profiler. The class must be Zend_Db_Profiler or a
     * subclass. The class is instantiated with no constructor arguments. The 'class'
     * option is ignored when the 'instance' option is supplied.
     *
     * An object of type Zend_Config may contain the properties 'enabled', 'class', and
     * 'instance', just as if an associative array had been passed instead.
     *
     * @param  Profiler|array|boolean $profiler
     * @return AbstractAdapter Provides a fluent interface
     * @throws DBException if the object instance or class specified
     *         is not Zend_Db_Profiler or an extension of that class.
     */
    public function setProfiler($profiler)
    {
        $enabled          = null;
        $profilerInstance = null;

        if ($profilerIsObject = is_object($profiler)) {
            if ($profiler instanceof Profiler) {
                $profilerInstance = $profiler;
            } else {
                /**
                 * @see DBException
                 */
                throw new DBException('Profiler argument must be an instance of either Zend_Db_Profiler'
                    . ' or Zend_Config when provided as an object');
            }
        }

        if (is_array($profiler)) {
            if (isset($profiler['enabled'])) {
                $enabled = (bool) $profiler['enabled'];
            }
            if (isset($profiler['instance'])) {
                $profilerInstance = $profiler['instance'];
            }
        } else if (!$profilerIsObject) {
            $enabled = (bool) $profiler;
        }

        if ($profilerInstance === null) {
            $profilerInstance = new Profiler();
        }

        if (!$profilerInstance instanceof Profiler) {
            /** @see DBException */
            throw new DBException('Class ' . get_class($profilerInstance) . ' does not extend '
                . 'Zend_Db_Profiler');
        }

        if (null !== $enabled) {
            $profilerInstance->setEnabled($enabled);
        }

        $this->_profiler = $profilerInstance;

        return $this;
    }


    /**
     * Returns the profiler for this adapter.
     *
     * @return Profiler
     */
    public function getProfiler():Profiler
    {
        return $this->_profiler;
    }

    /**
     * Prepares and executes an SQL statement with bound data.
     *
     * @param mixed $sql The SQL statement with placeholders.
     *                      May be a string or Zend_Db_Select.
     * @param mixed $bind An array of data to bind to the placeholders.
     * @return StatementInterface
     * @throws StatementException
     */
    public function query($sql, $bind = array())
    {
        // connect to the database if needed
        $this->_connect();

        // make sure $bind to an array;
        // don't use (array) typecasting because
        // because $bind may be a Zend_Db_Expr object
        if (!is_array($bind)) {
            $bind = array($bind);
        }

        // prepare and execute the statement with profiling
        $stmt = $this->prepare($sql);
        $stmt->execute($bind);

        // return the results embedded in the prepared statement object
        $stmt->setFetchMode($this->_fetchMode);
        return $stmt;
    }

    /**
     * Leave autocommit mode and begin a transaction.
     *
     * @return AbstractAdapter
     * @throws Profiler\ProfilerException
     */
    public function beginTransaction():AbstractAdapter
    {
        $this->_connect();
        $q = $this->_profiler->queryStart('begin', Profiler::TRANSACTION);
        $this->_beginTransaction();
        $this->_profiler->queryEnd($q);
        return $this;
    }

    /**
     * Commit a transaction and return to autocommit mode.
     *
     * @return AbstractAdapter
     * @throws Profiler\ProfilerException
     */
    public function commit():AbstractAdapter
    {
        $this->_connect();
        $q = $this->_profiler->queryStart('commit', Profiler::TRANSACTION);
        $this->_commit();
        $this->_profiler->queryEnd($q);
        return $this;
    }

    /**
     * Roll back a transaction and return to autocommit mode.
     *
     * @return AbstractAdapter
     * @throws Profiler\ProfilerException
     */
    public function rollBack():AbstractAdapter
    {
        $this->_connect();
        $q = $this->_profiler->queryStart('rollback', Profiler::TRANSACTION);
        $this->_rollBack();
        $this->_profiler->queryEnd($q);
        return $this;
    }

    /**
     * Get the fetch mode.
     *
     * @return int
     */
    public function getFetchMode():int
    {
        return $this->_fetchMode;
    }

    /**
     * Fetches all SQL result rows as a sequential array.
     * Uses the current fetchMode for the adapter.
     *
     * @param string $sql An SQL SELECT statement.
     * @param mixed $bind Data to bind into SELECT placeholders.
     * @param mixed $fetchMode Override current fetch mode.
     * @return array
     * @throws StatementException
     */
    public function fetchAll(string $sql, $bind = array(), $fetchMode = null):array
    {
        if ($fetchMode === null) {
            $fetchMode = $this->_fetchMode;
        }
        $stmt = $this->query($sql, $bind);
        return $stmt->fetchAll($fetchMode);
    }

    /**
     * Fetches the first row of the SQL result.
     * Uses the current fetchMode for the adapter.
     *
     * @param string $sql An SQL SELECT statement.
     * @param mixed $bind Data to bind into SELECT placeholders.
     * @param mixed $fetchMode Override current fetch mode.
     * @return array
     * @throws StatementException
     */
    public function fetchRow(string $sql, $bind = array(), $fetchMode = null):array
    {
        if ($fetchMode === null) {
            $fetchMode = $this->_fetchMode;
        }
        $stmt = $this->query($sql, $bind);
        return $stmt->fetch($fetchMode);
    }

    /**
     * Fetches all SQL result rows as an associative array.
     *
     * The first column is the key, the entire row array is the
     * value.  You should construct the query to be sure that
     * the first column contains unique values, or else
     * rows with duplicate values in the first column will
     * overwrite previous data.
     *
     * @param string $sql An SQL SELECT statement.
     * @param array $bind Data to bind into SELECT placeholders.
     * @return array
     * @throws StatementException
     */
    public function fetchAssoc(string $sql, array $bind = []):array
    {
        $stmt = $this->query($sql, $bind);
        $data = array();
        while ($row = $stmt->fetch(WiseDb::FETCH_ASSOC)) {
            $tmp = array_values(array_slice($row, 0, 1));
            $data[$tmp[0]] = $row;
        }
        return $data;
    }

    /**
     * Fetches the first column of all SQL result rows as an array.
     *
     * The first column in each row is used as the array key.
     *
     * @param string $sql An SQL SELECT statement.
     * @param mixed $bind Data to bind into SELECT placeholders.
     * @return array
     * @throws StatementException
     */
    public function fetchCol(string $sql, $bind = array()):array
    {
        $stmt = $this->query($sql, $bind);
        return $stmt->fetchAll(WiseDb::FETCH_COLUMN, 0);
    }

    /**
     * Fetches all SQL result rows as an array of key-value pairs.
     *
     * The first column is the key, the second column is the
     * value.
     *
     * @param string $sql An SQL SELECT statement.
     * @param mixed $bind Data to bind into SELECT placeholders.
     * @return array
     * @throws StatementException
     */
    public function fetchPairs(string $sql, $bind = array()):array
    {
        $stmt = $this->query($sql, $bind);
        $data = array();
        while ($row = $stmt->fetch(WiseDb::FETCH_NUM)) {
            $data[$row[0]] = $row[1];
        }
        return $data;
    }

    /**
     * Fetches the first column of the first row of the SQL result.
     *
     * @param string $sql An SQL SELECT statement.
     * @param mixed $bind Data to bind into SELECT placeholders.
     * @return string
     * @throws StatementException
     */
    public function fetchOne(string $sql, $bind = array()):string
    {
        $stmt = $this->query($sql, $bind);
        return $stmt->fetchColumn();
    }

    /**
     * Quote a raw string.
     *
     * @param string|int|float $value     Raw string
     * @return string           Quoted string
     */
    protected function _quote($value):string
    {
        if (is_int($value)) {
            return $value;
        } elseif (is_float($value)) {
            return sprintf('%F', $value);
        }
        return "'" . addcslashes($value, "\000\n\r\\'\"\032") . "'";
    }

    /**
     * Safely quotes a value for an SQL statement.
     *
     * If an array is passed as the value, the array values are quoted
     * and then returned as a comma-separated string.
     *
     * @param mixed $value The value to quote.
     * @param mixed $type  OPTIONAL the SQL datatype name, or constant, or null.
     * @return mixed An SQL-safe quoted value (or string of separated values).
     */
    public function quote($value, $type = null)
    {
        $this->_connect();

        if (is_array($value)) {
            foreach ($value as &$val) {
                $val = $this->quote($val, $type);
            }
            return implode(', ', $value);
        }

        if ($type !== null && array_key_exists($type = strtoupper($type), $this->_numericDataTypes)) {
            $quotedValue = '0';
            switch ($this->_numericDataTypes[$type]) {
                case WiseDb::INT_TYPE: // 32-bit integer
                    $quotedValue = (string) intval($value);
                    break;
                case WiseDb::BIGINT_TYPE: // 64-bit integer
                    // ANSI SQL-style hex literals (e.g. x'[\dA-F]+')
                    // are not supported here, because these are string
                    // literals, not numeric literals.
                    if (preg_match('/^(
                          [+-]?                  # optional sign
                          (?:
                            0[Xx][\da-fA-F]+     # ODBC-style hexadecimal
                            |\d+                 # decimal or octal, or MySQL ZEROFILL decimal
                            (?:[eE][+-]?\d+)?    # optional exponent on decimals or octals
                          )
                        )/x',
                        (string) $value, $matches)) {
                        $quotedValue = $matches[1];
                    }
                    break;
                case WiseDb::FLOAT_TYPE: // float or decimal
                    $quotedValue = sprintf('%F', $value);
            }
            return $quotedValue;
        }

        return $this->_quote($value);
    }

    /**
     * Quotes a value and places into a piece of text at a placeholder.
     *
     * The placeholder is a question-mark; all placeholders will be replaced
     * with the quoted value.   For example:
     *
     * <code>
     * $text = "WHERE date < ?";
     * $date = "2005-01-02";
     * $safe = $sql->quoteInto($text, $date);
     * // $safe = "WHERE date < '2005-01-02'"
     * </code>
     *
     * @param string  $text  The text with a placeholder.
     * @param mixed   $value The value to quote.
     * @param string|null  $type  OPTIONAL SQL datatype
     * @param int|null $count OPTIONAL count of placeholders to replace
     * @return string An SQL-safe quoted value placed into the original text.
     */
    public function quoteInto(string $text, $value, $type = null, $count = null):string
    {
        if ($count === null) {
            return str_replace('?', $this->quote($value, $type), $text);
        } else {
            while ($count > 0) {
                if (strpos($text, '?') != false) {
                    $text = substr_replace($text, $this->quote($value, $type), strpos($text, '?'), 1);
                }
                --$count;
            }
            return $text;
        }
    }

    /**
     * Quotes an identifier.
     *
     * Accepts a string representing a qualified indentifier. For Example:
     * <code>
     * $adapter->quoteIdentifier('myschema.mytable')
     * </code>
     * Returns: "myschema"."mytable"
     *
     * Or, an array of one or more identifiers that may form a qualified identifier:
     * <code>
     * $adapter->quoteIdentifier(array('myschema','my.table'))
     * </code>
     * Returns: "myschema"."my.table"
     *
     * The actual quote character surrounding the identifiers may vary depending on
     * the adapter.
     *
     * @param string|array $ident The identifier.
     * @param boolean $auto If true, heed the AUTO_QUOTE_IDENTIFIERS config option.
     * @return string The quoted identifier.
     */
    public function quoteIdentifier($ident, bool $auto=false):string
    {
        return $this->_quoteIdentifierAs($ident, null, $auto);
    }

    /**
     * Quote a column identifier and alias.
     *
     * @param string|array $ident The identifier or expression.
     * @param string $alias An alias for the column.
     * @param boolean $auto If true, heed the AUTO_QUOTE_IDENTIFIERS config option.
     * @return string The quoted identifier and alias.
     */
    public function quoteColumnAs($ident, string $alias, bool $auto=false):string
    {
        return $this->_quoteIdentifierAs($ident, $alias, $auto);
    }

    /**
     * Quote a table identifier and alias.
     *
     * @param string|array $ident The identifier or expression.
     * @param string|null $alias An alias for the table.
     * @param boolean $auto If true, heed the AUTO_QUOTE_IDENTIFIERS config option.
     * @return string The quoted identifier and alias.
     */
    public function quoteTableAs($ident, string $alias = null, bool $auto = false):string
    {
        return $this->_quoteIdentifierAs($ident, $alias, $auto);
    }

    /**
     * Quote an identifier and an optional alias.
     *
     * @param string|array $ident The identifier or expression.
     * @param string|null $alias An optional alias.
     * @param boolean $auto If true, heed the AUTO_QUOTE_IDENTIFIERS config option.
     * @param string $as The string to add between the identifier/expression and the alias.
     * @return string The quoted identifier and alias.
     */
    protected function _quoteIdentifierAs($ident, string $alias = null, bool $auto = false, string $as = ' AS '):string
    {
        if (is_string($ident)) {
            $ident = explode('.', $ident);
        }
        if (is_array($ident)) {
            $segments = array();
            foreach ($ident as $segment) {
                $segments[] = $this->_quoteIdentifier($segment, $auto);
            }
            if ($alias !== null && end($ident) == $alias) {
                $alias = null;
            }
            $quoted = implode('.', $segments);
        } else {
            $quoted = $this->_quoteIdentifier($ident, $auto);
        }
        if ($alias !== null) {
            $quoted .= $as . $this->_quoteIdentifier($alias, $auto);
        }
        return $quoted;
    }

    /**
     * Quote an identifier.
     *
     * @param  string $value The identifier or expression.
     * @param boolean $auto If true, heed the AUTO_QUOTE_IDENTIFIERS config option.
     * @return string        The quoted identifier and alias.
     */
    protected function _quoteIdentifier($value, $auto=false)
    {
        if ($auto === false || $this->_autoQuoteIdentifiers === true) {
            $q = $this->getQuoteIdentifierSymbol();
            return ($q . str_replace("$q", "$q$q", $value) . $q);
        }
        return $value;
    }

    /**
     * Returns the symbol the adapter uses for delimited identifiers.
     *
     * @return string
     */
    public function getQuoteIdentifierSymbol()
    {
        return '"';
    }

    /**
     * Return the most recent value from the specified sequence in the database.
     * This is supported only on RDBMS brands that support sequences
     * (e.g. Oracle, PostgreSQL, DB2).  Other RDBMS brands return null.
     *
     * @param string $sequenceName
     * @return string
     */
    public function lastSequenceId($sequenceName)
    {
        return null;
    }

    /**
     * Generate a new value from the specified sequence in the database, and return it.
     * This is supported only on RDBMS brands that support sequences
     * (e.g. Oracle, PostgreSQL, DB2).  Other RDBMS brands return null.
     *
     * @param string $sequenceName
     * @return string
     */
    public function nextSequenceId($sequenceName)
    {
        return null;
    }

    /**
     * Helper method to change the case of the strings used
     * when returning result sets in FETCH_ASSOC and FETCH_BOTH
     * modes.
     *
     * This is not intended to be used by application code,
     * but the method must be public so the Statement class
     * can invoke it.
     *
     * @param string $key
     * @return string
     */
    public function foldCase($key)
    {
        switch ($this->_caseFolding) {
            case WiseDb::CASE_LOWER:
                $value = strtolower((string) $key);
                break;
            case WiseDb::CASE_UPPER:
                $value = strtoupper((string) $key);
                break;
            case WiseDb::CASE_NATURAL:
            default:
                $value = (string) $key;
        }
        return $value;
    }

    /**
     * called when object is getting serialized
     * This disconnects the DB object that cant be serialized
     *
     * @throws AdapterException
     * @return array
     */
    public function __sleep()
    {
        if ($this->_allowSerialization == false) {
            /** @see AdapterException */
            throw new AdapterException(get_class($this) ." is not allowed to be serialized");
        }
        $this->_connection = false;
        return array_keys(array_diff_key(get_object_vars($this), array('_connection'=>false)));
    }

    /**
     * called when object is getting unserialized
     *
     * @return void
     */
    public function __wakeup()
    {
        if ($this->_autoReconnectOnUnserialize == true) {
            $this->getConnection();
        }
    }

    /**
     * Abstract Methods
     */

    /**
     * Returns a list of the tables in the database.
     *
     * @return array
     */
    abstract public function listTables();

    /**
     * Returns the column descriptions for a table.
     *
     * The return value is an associative array keyed by the column name,
     * as returned by the RDBMS.
     *
     * The value of each array element is an associative array
     * with the following keys:
     *
     * SCHEMA_NAME => string; name of database or schema
     * TABLE_NAME  => string;
     * COLUMN_NAME => string; column name
     * COLUMN_POSITION => number; ordinal position of column in table
     * DATA_TYPE   => string; SQL datatype name of column
     * DEFAULT     => string; default expression of column, null if none
     * NULLABLE    => boolean; true if column can have nulls
     * LENGTH      => number; length of CHAR/VARCHAR
     * SCALE       => number; scale of NUMERIC/DECIMAL
     * PRECISION   => number; precision of NUMERIC/DECIMAL
     * UNSIGNED    => boolean; unsigned property of an integer type
     * PRIMARY     => boolean; true if column is part of the primary key
     * PRIMARY_POSITION => integer; position of column in primary key
     *
     * @param string $tableName
     * @param string $schemaName OPTIONAL
     * @return array
     */
    abstract public function describeTable($tableName, $schemaName = null):array;

    /**
     * Creates a connection to the database.
     *
     * @return void
     */
    abstract protected function _connect();

    /**
     * Test if a connection is active
     *
     * @return boolean
     */
    abstract public function isConnected():bool;

    /**
     * Force the connection to close.
     *
     * @return void
     */
    abstract public function closeConnection();

    /**
     * Prepare a statement and return a PDOStatement-like object.
     *
     * @param string $sql SQL query
     * @return Statement
     */
    abstract public function prepare($sql);

    /**
     * Gets the last ID generated automatically by an IDENTITY/AUTOINCREMENT column.
     *
     * As a convention, on RDBMS brands that support sequences
     * (e.g. Oracle, PostgreSQL, DB2), this method forms the name of a sequence
     * from the arguments and returns the last id generated by that sequence.
     * On RDBMS brands that support IDENTITY/AUTOINCREMENT columns, this method
     * returns the last value generated for such a column, and the table name
     * argument is disregarded.
     *
     * @param string $tableName   OPTIONAL Name of table.
     * @param string $primaryKey  OPTIONAL Name of primary key column.
     * @return string
     */
    abstract public function lastInsertId($tableName = null, $primaryKey = null);

    /**
     * Begin a transaction.
     */
    abstract protected function _beginTransaction();

    /**
     * Commit a transaction.
     */
    abstract protected function _commit();

    /**
     * Roll-back a transaction.
     */
    abstract protected function _rollBack();

    /**
     * Set the fetch mode.
     *
     * @param integer $mode
     * @return void
     * @throws AdapterException
     */
    abstract public function setFetchMode($mode);

    /**
     * Adds an adapter-specific LIMIT clause to the SELECT statement.
     *
     * @param mixed $sql
     * @param integer $count
     * @param integer $offset
     * @return string
     */
    abstract public function limit($sql, $count, $offset = 0):string;

    /**
     * Check if the adapter supports real SQL parameters.
     *
     * @param string $type 'positional' or 'named'
     * @return bool
     */
    abstract public function supportsParameters(string $type):bool;

    /**
     * Retrieve server version in PHP style
     *
     * @return string
     */
    abstract public function getServerVersion():string;
}