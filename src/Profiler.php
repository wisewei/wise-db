<?php
namespace ZendDb;

use ZendDb\Profiler\Query as QueryProfiler;
use ZendDb\Profiler\ProfilerException;

/**
 * @category   Zend
 * @package    Zend_Db
 * @subpackage Profiler
 * @copyright  Copyright (c) 2005-2015 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
class Profiler
{

    /**
     * A connection operation or selecting a database.
     */
    const CONNECT = 1;

    /**
     * Any general database query that does not fit into the other constants.
     */
    const QUERY = 2;

    /**
     * Adding new data to the database, such as SQL's INSERT.
     */
    const INSERT = 4;

    /**
     * Updating existing information in the database, such as SQL's UPDATE.
     *
     */
    const UPDATE = 8;

    /**
     * An operation related to deleting data in the database,
     * such as SQL's DELETE.
     */
    const DELETE = 16;

    /**
     * Retrieving information from the database, such as SQL's SELECT.
     */
    const SELECT = 32;

    /**
     * Transactional operation, such as start transaction, commit, or rollback.
     */
    const TRANSACTION = 64;

    /**
     * Inform that a query is stored (in case of filtering)
     */
    const STORED = 'stored';

    /**
     * Inform that a query is ignored (in case of filtering)
     */
    const IGNORED = 'ignored';

    /**
     * Array of Zend_Db_Profiler_Query objects.
     *
     * @var array
     */
    protected $_queryProfiles = array();

    /**
     * Stores enabled state of the profiler.  If set to False, calls to
     * queryStart() will simply be ignored.
     *
     * @var boolean
     */
    protected $_enabled = false;

    /**
     * Stores the number of seconds to filter.  NULL if filtering by time is
     * disabled.  If an integer is stored here, profiles whose elapsed time
     * is less than this value in seconds will be unset from
     * the self::$_queryProfiles array.
     *
     * @var integer
     */
    protected $_filterElapsedSecs = null;

    /**
     * Logical OR of any of the filter constants.  NULL if filtering by query
     * type is disable.  If an integer is stored here, it is the logical OR of
     * any of the query type constants.  When the query ends, if it is not
     * one of the types specified, it will be unset from the
     * self::$_queryProfiles array.
     *
     * @var integer
     */
    protected $_filterTypes = null;

    /**
     * Class constructor.  The profiler is disabled by default unless it is
     * specifically enabled by passing in $enabled here or calling setEnabled().
     *
     * @param  bool $enabled
     */
    public function __construct(bool $enabled = false)
    {
        $this->setEnabled($enabled);
    }

    /**
     * Enable or disable the profiler.  If $enable is false, the profiler
     * is disabled and will not log any queries sent to it.
     *
     * @param  boolean $enable
     * @return $this Provides a fluent interface
     */
    public function setEnabled(bool $enable): Profiler
    {
        $this->_enabled = $enable;

        return $this;
    }

    /**
     * Get the current state of enable.  If True is returned,
     * the profiler is enabled.
     *
     * @return boolean
     */
    public function getEnabled(): bool
    {
        return $this->_enabled;
    }

    /**
     * Sets a minimum number of seconds for saving query profiles.  If this
     * is set, only those queries whose elapsed time is equal or greater than
     * $minimumSeconds will be saved.  To save all queries regardless of
     * elapsed time, set $minimumSeconds to null.
     *
     * @param  int|null $minimumSeconds OPTIONAL
     * @return $this Provides a fluent interface
     */
    public function setFilterElapsedSecs(int $minimumSeconds = null): Profiler
    {
        if (null === $minimumSeconds) {
            $this->_filterElapsedSecs = null;
        } else {
            $this->_filterElapsedSecs = $minimumSeconds;
        }

        return $this;
    }

    /**
     * Returns the minimum number of seconds for saving query profiles, or null if
     * query profiles are saved regardless of elapsed time.
     *
     * @return integer|null
     */
    public function getFilterElapsedSecs(): ?int
    {
        return $this->_filterElapsedSecs;
    }

    /**
     * Sets the types of query profiles to save.  Set $queryType to one of
     * the Zend_Db_Profiler::* constants to only save profiles for that type of
     * query.  To save more than one type, logical OR them together.  To
     * save all queries regardless of type, set $queryType to null.
     *
     * @param  integer|null $queryTypes OPTIONAL
     * @return $this Provides a fluent interface
     */
    public function setFilterQueryType(int $queryTypes = null): Profiler
    {
        $this->_filterTypes = $queryTypes;

        return $this;
    }

    /**
     * Returns the types of query profiles saved, or null if queries are saved regardless
     * of their types.
     *
     * @return integer|null
     * @see    Profiler::setFilterQueryType()
     */
    public function getFilterQueryType(): int
    {
        return $this->_filterTypes;
    }

    /**
     * Clears the history of any past query profiles.  This is relentless
     * and will even clear queries that were started and may not have
     * been marked as ended.
     *
     * @return $this Provides a fluent interface
     */
    public function clear(): Profiler
    {
        $this->_queryProfiles = array();

        return $this;
    }

    /**
     * Clone a profiler query
     *
     * @param  QueryProfiler $query
     * @return integer|null
     */
    public function queryClone(QueryProfiler $query)
    {
        $this->_queryProfiles[] = clone $query;

        end($this->_queryProfiles);

        return key($this->_queryProfiles);
    }

    /**
     * Starts a query.  Creates a new query profile object (Zend_Db_Profiler_Query)
     * and returns the "query profiler handle".  Run the query, then call
     * queryEnd() and pass it this handle to make the query as ended and
     * record the time.  If the profiler is not enabled, this takes no
     * action and immediately returns null.
     *
     * @param  string  $queryText   SQL statement
     * @param  integer|null $queryType   OPTIONAL Type of query, one of the Zend_Db_Profiler::* constants
     * @return integer|null
     */
    public function queryStart(string $queryText, $queryType = null)
    {
        if (! $this->_enabled) {
            return null;
        }

        // make sure we have a query type
        if (null === $queryType) {
            switch (strtolower(substr(ltrim($queryText), 0, 6))) {
                case 'insert':
                    $queryType = self::INSERT;
                    break;
                case 'update':
                    $queryType = self::UPDATE;
                    break;
                case 'delete':
                    $queryType = self::DELETE;
                    break;
                case 'select':
                    $queryType = self::SELECT;
                    break;
                default:
                    $queryType = self::QUERY;
                    break;
            }
        }

        /**
         * @see QueryProfiler
         */
        $this->_queryProfiles[] = new QueryProfiler($queryText, $queryType);

        end($this->_queryProfiles);

        return key($this->_queryProfiles);
    }

    /**
     * Ends a query. Pass it the handle that was returned by queryStart().
     * This will mark the query as ended and save the time.
     *
     * @param  integer $queryId
     * @return string   Inform that a query is stored or ignored.
     * @throws ProfilerException
     */
    public function queryEnd(int $queryId): string
    {
        // Don't do anything if the Zend_Db_Profiler is not enabled.
        if (! $this->_enabled) {
            return self::IGNORED;
        }

        // Check for a valid query handle.
        if (! isset($this->_queryProfiles[$queryId])) {
            throw new ProfilerException("Profiler has no query with handle '$queryId'.");
        }

        $qp = $this->_queryProfiles[$queryId];

        // Ensure that the query profile has not already ended
        if ($qp->hasEnded()) {
            /**
             * @see ProfilerException
             */
            throw new ProfilerException("Query with profiler handle '$queryId' has already ended.");
        }

        // End the query profile so that the elapsed time can be calculated.
        $qp->end();

        /**
         * If filtering by elapsed time is enabled, only keep the profile if
         * it ran for the minimum time.
         */
        if (null !== $this->_filterElapsedSecs && $qp->getElapsedSecs() < $this->_filterElapsedSecs) {
            unset($this->_queryProfiles[$queryId]);
            return self::IGNORED;
        }

        /**
         * If filtering by query type is enabled, only keep the query if
         * it was one of the allowed types.
         */
        if (null !== $this->_filterTypes && ! ($qp->getQueryType() & $this->_filterTypes)) {
            unset($this->_queryProfiles[$queryId]);
            return self::IGNORED;
        }

        return self::STORED;
    }

    /**
     * Get a profile for a query.  Pass it the same handle that was returned
     * by queryStart() and it will return a Zend_Db_Profiler_Query object.
     *
     * @param  integer $queryId
     * @throws ProfilerException
     * @return QueryProfiler
     */
    public function getQueryProfile($queryId)
    {
        if (! array_key_exists($queryId, $this->_queryProfiles)) {
            /**
             * @see ProfilerException
             */
            throw new ProfilerException("Query handle '$queryId' not found in profiler log.");
        }

        return $this->_queryProfiles[$queryId];
    }

    /**
     * Get an array of query profiles (Zend_Db_Profiler_Query objects).  If $queryType
     * is set to one of the Zend_Db_Profiler::* constants then only queries of that
     * type will be returned.  Normally, queries that have not yet ended will
     * not be returned unless $showUnfinished is set to True.  If no
     * queries were found, False is returned. The returned array is indexed by the query
     * profile handles.
     *
     * @param  integer $queryType
     * @param  boolean $showUnfinished
     * @return array|false
     */
    public function getQueryProfiles($queryType = null, $showUnfinished = false)
    {
        $queryProfiles = array();
        foreach ($this->_queryProfiles as $key => $qp) {
            if ($queryType === null) {
                $condition = true;
            } else {
                $condition = ($qp->getQueryType() & $queryType);
            }

            if (($qp->hasEnded() || $showUnfinished) && $condition) {
                $queryProfiles[$key] = $qp;
            }
        }

        if (empty($queryProfiles)) {
            $queryProfiles = false;
        }

        return $queryProfiles;
    }

    /**
     * Get the total elapsed time (in seconds) of all of the profiled queries.
     * Only queries that have ended will be counted.  If $queryType is set to
     * one or more of the Zend_Db_Profiler::* constants, the elapsed time will be calculated
     * only for queries of the given type(s).
     *
     * @param  integer $queryType OPTIONAL
     * @return float
     */
    public function getTotalElapsedSecs($queryType = null)
    {
        $elapsedSecs = 0;
        foreach ($this->_queryProfiles as $key => $qp) {
            if (null === $queryType) {
                $condition = true;
            } else {
                $condition = ($qp->getQueryType() & $queryType);
            }
            if (($qp->hasEnded()) && $condition) {
                $elapsedSecs += $qp->getElapsedSecs();
            }
        }
        return $elapsedSecs;
    }

    /**
     * Get the total number of queries that have been profiled.  Only queries that have ended will
     * be counted.  If $queryType is set to one of the Zend_Db_Profiler::* constants, only queries of
     * that type will be counted.
     *
     * @param  integer $queryType OPTIONAL
     * @return integer
     */
    public function getTotalNumQueries($queryType = null)
    {
        if (null === $queryType) {
            return count($this->_queryProfiles);
        }

        $numQueries = 0;
        foreach ($this->_queryProfiles as $qp) {
            if ($qp->hasEnded() && ($qp->getQueryType() & $queryType)) {
                $numQueries ++;
            }
        }

        return $numQueries;
    }

    /**
     * Get the Zend_Db_Profiler_Query object for the last query that was run, regardless if it has
     * ended or not.  If the query has not ended, its end time will be null.  If no queries have
     * been profiled, false is returned.
     *
     * @return QueryProfiler|false
     */
    public function getLastQueryProfile()
    {
        if (empty($this->_queryProfiles)) {
            return false;
        }

        end($this->_queryProfiles);

        return current($this->_queryProfiles);
    }
}