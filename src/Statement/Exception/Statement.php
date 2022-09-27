<?php
namespace WiseDb\Statement\Exception;

use WiseDb\DBException;

class Statement extends DBException
{
    /**
     * @var DBException
     */
    protected $_chainedException = null;

    /**
     * @param null $message
     * @param null $code
     * @param DBException|null $chainedException
     */
    public function __construct($message = null, $code = null, DBException $chainedException=null)
    {
        $this->message = $message;
        $this->code = $code;
        $this->_chainedException = $chainedException;
        parent::__construct($message, $this);
    }

    /**
     * Check if this general exception has a specific database driver specific exception nested inside.
     *
     * @return bool
     */
    public function hasChainedException():bool
    {
        return ($this->_chainedException!==null);
    }

    /**
     * @return DBException|null
     */
    public function getChainedException():DBException
    {
        return $this->_chainedException;
    }
}