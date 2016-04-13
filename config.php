<?php
/**
 * Created by PhpStorm.
 * User: admin
 * Date: 03/21/2016
 * Time: 5:39 PM
 */

//require __DIR__.'/vendor/autoload.php';

include_once('class.Log.php');		// Log class
include_once('class.CheckStart.php');
include_once('class.Db.php');
													// Global vars
define('FM_CONN_FREE','free');
define('FM_CONN_PAID','paid');
define('FM_SCHED_PREFIX','_SCHED_');				// prefix for schedule records
define('FM_SCHED_TYPE','SCHEDULE');					// type for schedule records
define('FM_ACCESS_TYPE','ACCESS');				// type for user access records

$INIFILE = 'config.ini';
$LOGFILE = 'fmusage.log';

$CONFIG = parse_ini_file($INIFILE, true);
$LOG = new LOG($CONFIG['VAR_DIR'].$LOGFILE);		// Default log
$DB = new Db($CONFIG);

set_error_handler("error_handler", E_ALL); // Catch all error/notice messages

//
// error_handler - catch notice and warnings
//
function error_handler($errno, $errstr, $errfile, $errline) {
  global $LOG;
  if($errno == E_WARNING) {
	$LOG->message("Warning. File: $errfile, Line: $errline. $errstr");
//  	throw new Exception($errstr);
  } else if($errno == E_NOTICE) {
//      throw new Exception($errstr);
	$LOG->message("Notice. File: $errfile, Line: $errline. $errstr");
	exit;
  }
}								// -- error_handler --

//
// printLogAndDie - log and print  message and exit
// Call:	printLogAndDie($msg)
// Where:	$msg - message to write in log file
//
function printLogAndDie($str) {
  global $LOG;
  $LOG->message($str);
  exit;
}								// -- printLogAndDie --

// getDateFromLog - get date/time of the record in log file
// Call:	$dateTime = getDateFromLog($line)
// Where:	$dateTime - date/time of the last record in log file
//			$line - log line
//
function getDateFromLog ($line) {
  $line_parts = explode("\t", $line);
  if (preg_match('/^([\d-]+ [\d:]+)/', $line_parts[0], $matches)) {
	return $matches[1];
  }
  return '';							// Invalid date/time format
}

// formatSec - formatSec seconds to time format like HH:MM:SS
// Call:	$res = formatSec($sec);
// Where:	$res - result in format HH:MM:SS or MM:SS or SS depending on $sec value
//			$sec - seconds to convert
//
function formatSec ($sec) {
  $hours = floor($sec / 3600);
  $minutes = floor(($sec / 60) % 60);
  $seconds = $sec % 60;
  if ($hours) {
	return sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
  } else if ($minutes) {
	return sprintf("%02d:%02d",  $minutes, $seconds);
  }
  return $sec .' sec';
}										// -- formatSec --

// CleanUpDB - remove old records
// Call: 	CleanUpDB();
//
function CleanUpDB () {
  global $DB,$CONFIG;

  $sth = $DB->dbh->prepare("DELETE FROM FmAccessLog WHERE DATEDIFF(NOW(),LogDate) > ?");
  $sth->execute(array($CONFIG['DB_RECORDS_LIFETIME']));
  if ($sth->errorInfo()[1]) {
	printLogAndDie("DB error: ".$sth->errorInfo()[2]);
  }
  $sth = $DB->dbh->prepare("DELETE FROM FmClientSession WHERE DATEDIFF(NOW(),StartDate) > ?");
  $sth->execute(array($CONFIG['DB_RECORDS_LIFETIME']));
  if ($sth->errorInfo()[1]) {
	printLogAndDie("DB error: ".$sth->errorInfo()[2]);
  }
}										// -- CleanUpDB --