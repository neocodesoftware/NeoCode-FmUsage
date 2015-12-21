<?php
/**
 * Created by PhpStorm.
 * User: admin
 * Date: 12/7/2015
 * Time: 5:39 PM
 */

//require __DIR__.'/vendor/autoload.php';
include_once('class.Log.php');		// Log class
include_once('class.CheckStart.php');
include_once('class.Db.php');

														// Global vars
define('FM_CONN_FREE','free');
define('FM_CONN_PAID','paid');
$INIFILE = 'config.ini';
$LOGFILE = 'fmusage.log';

$CONFIG = parse_ini_file($INIFILE, true);
$LOG = new LOG($CONFIG['VAR_DIR'].$LOGFILE);
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