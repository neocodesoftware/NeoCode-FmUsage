<?php
//
// class.Log.php - log class
//
// Vers. 1.0, YP 04/12/2016
//
//
// History:
// ALPHA, YP 05/12/2015 - initial release
// 1.0 , YP 04/12/2016 - add support for NULL and CONSOLE log files
//

class Log {
 
  function __construct($logFile) {
    $this->logFile = $logFile;
  }
  
  public function message ($str) {
    if ($this->logFile == 'NULL') {
    }
    else if ($this->logFile == 'CONSOLE') {
      print $str."\n";
    }
    else {
	  file_put_contents($this->logFile,date('Y-m-d H:i:s').
		  (array_key_exists('REMOTE_ADDR',$_SERVER) ? ' ['.$_SERVER['REMOTE_ADDR'].']' : '').
		  ' '.$_SERVER['SCRIPT_NAME'].' ('.getmypid().'): '.$str."\r\n",FILE_APPEND | LOCK_EX);
	}
  }                                     // -- message --
}
?>