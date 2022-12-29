<?php
namespace ZendDb\Adapter\Exception;

use ZendDb\DBException;

class Adapter extends DBException
{

    protected $_chainedException = null;

    public function __construct($message = null, Adapter $e = null)
    {
        if ($e) {
            $this->_chainedException = $e;
            $this->code = $e->getCode();
        }
        parent::__construct($message);
    }

    public function hasChainedException()
    {
        return ($this->_chainedException !== null);
    }

    public function getChainedException()
    {
        return $this->_chainedException;
    }
}
