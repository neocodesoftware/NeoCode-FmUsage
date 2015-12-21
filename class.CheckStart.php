<?php
//
// class.CheckStart.php - Check if scripr already running
//
// Vers. ALPHA, YP 05/12/2015
//
//
// History:
// ALPHA, YP 05/12/2015
//

class CheckStart {
  private $lockFilePnt;						// Lock file pointer
 
  function __construct($lockFile) {
    $this->lockFile = $lockFile;
    //$this->lockFilePnt = '';
  }
  
//
// canStart - lock file to prevent script from multirunning
// Call:	$res = canStart;
// Where:	$res = true if we locked filed and script could start working
//			$res = false if we can't start because locke file alreadu locked by another process
//
  public function canStart () {
    $this->lockFilePnt = fopen($this->lockFile, "w+");
    if(!flock($this->lockFilePnt, LOCK_EX | LOCK_NB)) {
      return false;
    }
    return true;
  }                                     // -- canStart --
}

?>