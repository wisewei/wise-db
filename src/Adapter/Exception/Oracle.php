<?php
namespace WiseDb\Adapter\Exception;

use WiseDb\Adapter\Exception\Adapter as AdapterException;

/**
 * OracleException
 *
 * @category   Zend
 * @package    Zend_Db
 * @subpackage Adapter
 * @copyright  Copyright (c) 2005-2009 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
class Oracle extends AdapterException
{

    protected $message = 'Unknown exception';

    protected $code = 0;

    function __construct($error = null, $code = 0)
    {
        if (is_array($error)) {
            if (! isset($error['offset'])) {
                $this->message = $error['code'] . ' ' . $error['message'];
            } else {
                $this->message = $error['code'] . ' ' . $error['message'] . " " . substr($error['sqltext'], 0, $error['offset']) . "*" . substr($error['sqltext'], $error['offset']);
            }
            $this->code = $error['code'];
        } else if (is_string($error)) {
            $this->message = $error;
        }
        if (! $this->code && $code) {
            $this->code = $code;
        }
        parent::__construct($this->message, $this);
    }
}