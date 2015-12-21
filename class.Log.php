<?php
//
// class.Log.php - log class
//
// Vers. ALPHA, YP 05/12/2015
//
//
// History:
// ALPHA, YP 05/12/2015
//

class Log {
 
  function __construct($logFile) {
    $this->logFile = $logFile;
  }
  
  public function message ($str) {
    file_put_contents($this->logFile,date('Y-m-d H:i:s').' ['.(array_key_exists('REMOTE_ADDR',$_SERVER) ? $_SERVER['REMOTE_ADDR'] : '').'] ('.getmypid().'): '.$str."\r\n",FILE_APPEND | LOCK_EX);
  }                                     // -- message --
}
?>