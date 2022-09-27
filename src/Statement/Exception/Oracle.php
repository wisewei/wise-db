<?php

namespace WiseDb\Statement\Exception;

use WiseDb\Statement\Exception\Statement as StatementException;

class Oracle extends StatementException
{
   protected $message = 'Unknown exception';
   protected $code = 0;

   function __construct($error = null, $code = 0)
   {
       if (is_array($error)) {
            if (!isset($error['offset'])) {
                $this->message = $error['code']." ".$error['message'];
            } else {
                $this->message = $error['code']." ".$error['message']." ";
                $this->message .= substr($error['sqltext'], 0, $error['offset']);
                $this->message .= "*";
                $this->message .= substr($error['sqltext'], $error['offset']);
            }
            $this->code = $error['code'];
       }
       if (!$this->code && $code) {
           $this->code = $code;
       }
       parent::__construct($this->getMessage(), $this);
   }
}