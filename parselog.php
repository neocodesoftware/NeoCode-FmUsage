<?php

// parselog.php - parse FileMaker access log file
//
// Vers. 1.0 , YP 03/22/2016
//
// Parse FileMaker access logs.
//
// Copyright © 2015 Neo Code Software Ltd
// Artisanal FileMaker & PHP hosting and development since 2002
// 1-888-748-0668 http://store.neocodesoftware.com
//
// History:
// ALPHA, YP 08/12/2015 - Alpha release
// 1.0, YP 03/22/2016 - Added Events log parsing
//

include_once('config.php');			// configuration and common stuff
									// Global vars
$FM_EXTENSIONS = array('fmp12');
$FM_COLLECT_CODES = array (94,98,22,638,689,150,644,645,30,152);		// FM log codes we want to collect.
// The complete list is here http://help.filemaker.com/app/answers/detail/a_id/7275/~/filemaker-server-event-log-messages
// 22 	Informational 	Client "%1" closing a connection.	- ACCESS
// 30 	Warning 	Client "%1" no longer responding; connection closed. (%2) - ACCESS TODO %1 - client+IP, %2 - error code
// 94   Informational 	Client "%1" opening database "%2" as "%3". - ACCESS
// 98 	Informational 	Client "%1" closing database "%2" as "%3". - ACCESS
// 638 	Informational 	Client "%1" opening a connection from "%2" using "%3". - ACCESS
//
// 689 	Informational 	Schedule "%1" has started FileMaker script "%2". - SCRIPT %1 - schedule name, %2 - script name
// 150 	Informational 	Schedule "%1" completed. - SCRIPT %1 - schedule name,
// 152 	Informational 	Schedule "%1" aborted; aborted by user.
// 644 	Informational 	Schedule "%1" completed; last scripting error (%2).
// 645 	Informational 	Schedule "%1" scripting error (%2) at "%3".	- SCRIPT %1 - schedule name, %2 - error code, %3 - "DBName: error description"
									// Start here
$LOG->message("parselog started");
$ckStart = new CheckStart($CONFIG['VAR_DIR'].'parselog.lock');
if(!$ckStart->canStart()) {			// Check if script already running. Doesn't allow customer to send multiple restart requests
  printLogAndDie("Script is already running.");
}

$databases = array();				// Find all databases for all clients
foreach (scandir($CONFIG['FM_DB_DIR']) as $client) {
  if (!in_array($client,array(".","..")) && is_dir($CONFIG['FM_DB_DIR'].'/'.$client)) {
	$ar = collectFmDbs($CONFIG['FM_DB_DIR'].'/'.$client);
	foreach ($ar as $db){
	  $databases[$db] = $client;
	}
  }
}
//print_r($databases);


$lastRecDateTime = '';				// Last record in DB
$sth = $DB->dbh->prepare("SELECT LogDate, LogTime FROM FmAccessLog ORDER BY LogDate DESC, LogTime DESC LIMIT 1");
$sth->execute();
if ($sth->errorInfo()[1]) {
  printLogAndDie("DB error: ".$sth->errorInfo()[2]);
}
if ($lastRecord = $sth->fetch(PDO::FETCH_ASSOC)) {
  $lastRecDateTime = $lastRecord['LogDate'].' '.$lastRecord['LogTime'];
}
								// Prepare list of log files to parse
$log2Parse = array();			// List of log files to parse
$lastDateTime = '';				// Last record in log file (we'll select older record among several log files)
foreach (array('FM_ACCESS_LOG','FM_EVENT_LOG') as $log) {
  $myLastDateTime = getDateFromLog(readLastLine($CONFIG[$log]));	// Read date/time of the last record in log
  if (!$lastDateTime || $lastDateTime > $myLastDateTime) {
	$lastDateTime = $myLastDateTime;	// Select older record among several log files
  }
  $firstDateTime = getDateFromLog(readFirstLine($CONFIG[$log]));	// Read date/time of the first record in log

  if ($lastRecDateTime < $firstDateTime &&							// If last record in DB is less that first record in file - Log was rotated
	array_key_exists($log.'_ROTATE',$CONFIG) &&
	$CONFIG[$log.'_ROTATE'])								// Additionally parse previous log
  {
	$LOG->message("Additionally parse previous log: ".$CONFIG[$log.'_ROTATE']);
	$log2Parse[] = $CONFIG[$log.'_ROTATE'];
  }
  $log2Parse[] = $CONFIG[$log];
}

								// Parse log files
foreach ($log2Parse as $logFile) {
  if ($handle = fopen($logFile, "r")) {
	while (($line = fgets($handle)) !== false) {
	  $rdate = $rtime = $rsec = $rcode = $rserver = $rclient = $rip = $rdb = $rlogin = $rfmapp = $rcomments = ''; // Values to store for each record
	  $line_parts = explode("\t", $line);
	  $rcode = $line_parts[2];
	  $rserver = $line_parts[3];
	  if (!in_array($rcode,$FM_COLLECT_CODES)) {			// We do not need this record
		continue;
	  }
	  if (preg_match('/^([\d-]+) ([\d:]+)\.(\d+) ([=\d-]+ )?/', $line_parts[0], $matches)) {
		$rdate = $matches[1];
		$rtime = $matches[2];
		$rsec = $matches[3];
	  } else {
		$LOG->message("Can't parse date/time: $line");
		continue;                        // Invalid date/time format
	  }

	  if ($lastDateTime && $lastDateTime <= "$rdate $rtime" ||      // Don't save very last records - we'll start with this records next time and this way we avoid duplicate records.
		  $lastRecDateTime && $lastRecDateTime >= "$rdate $rtime")	// We already have this date in DB
	  {
		continue;									// go to next line(record)
	  }

	  if ($rcode == '94' || $rcode == '98' || $rcode == '22' || $rcode == '30') {
		if (preg_match('/^Client "([^"]+)" (closing a connection|no longer responding.+|[^"]+ "([^"]+)" as "([^"]+)")/', $line_parts[4], $matches)) {
          $cl = $matches[1];
          if ($rcode == '94' || $rcode == '98') {
		    $rdb = $matches[3];
		    $rlogin = $matches[4];
		  }
		  if (preg_match('/^(.+) \[([\d., ]+)\]$/',$cl,$matches)) {	// IP found here
			$rclient =  $matches[1];
			$rip = $matches[2];
		  }
		  else {													// no ip here
			$rclient =  $cl;
			$rip = '';
		  }
		} else {
		  $LOG->message("Can't parse record with code $rcode: $line");
		  continue;                        // Invalid date/time format
		}
	  }

	  else if ($rcode == '638') {
		if (preg_match('/^Client "([^"]*)" opening a connection from "([^"]+)" using "([^"]+)"/', $line_parts[4], $matches)) {
		  $cl1 = $matches[1];
		  $cl2 = $matches[2];
		  $rfmapp = $matches[3];
		  if (preg_match('/^(.+) \(([\d., ]+)\)$/',$cl2,$matches)) {	// IP found here
			$rclient =  $cl1.' ('.$matches[1].')';
			$rip = $matches[2];
		  }
		  else {													// no ip here
			$rclient =  $cl1.' ('.$cl2.')';
			$rip = '';
		  }
		} else {
		  $LOG->message("Can't parse record with code $rcode: $line");
		  continue;                        // Invalid date/time format
		}
	  }

	  else if ($rcode == '689' || $rcode == '150' || $rcode == '645' || $rcode == '644' || $rcode == '152') {
		if (preg_match('/^Schedule "([^"]*)" ([^\r\n]+)/', $line_parts[4], $matches)) {
		  $rclient = $matches[1];	// Save schedule name as client name
		  $rcomments = $matches[2];					// scripting error will be saved in comments
		} else {
		  $LOG->message("Can't parse record with code $rcode: $line");
		  continue;                       			// Invalid format
		}
	  }

	  else {
        continue;							// We don't need this record
	  }
											// Save record in DB

	  $sth = $DB->dbh->prepare("INSERT INTO FmAccessLog (LogDate,LogTime,LogSec,LogCode,ServerName,FmClient,FmClientIP,DbName,FmLoginName,FmApp,OwnerName,Comments) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
	  $sth->execute(array($rdate, $rtime, $rsec, $rcode, $rserver, $rclient, $rip, $rdb, $rlogin, $rfmapp,array_key_exists($rdb,$databases) ? $databases[$rdb] : '',$rcomments));
	  if ($sth->errorInfo()[1]) {
  	    printLogAndDie("DB error: ".$sth->errorInfo()[2]);
      }
	}
	fclose($handle);
  } else {
	$LOG->message("Unable to open file " . $logFile);
  }
}

$LOG->message("parselog finished");
exit;

// readFirstLine - read last line in log file
// Call:		$line = readFirstLine($logFile)
// Where:		$line - first line in log file
// 				$logFile - path to log file
//
function readFirstLine ($file) {
  $line = '';
  if ($handle = fopen($file, 'r')) {				// Open file
  	$line = fgets($handle);
	$line = preg_replace('/^[^\d]+/','',$line);		// Remove invalid chars in the beginning of the string
	fclose($handle);
  }
  return $line;
}

// readLastLine - read last line in log file
// Call:		$line = readLastLine($logFile)
// Where:		$line - last line in log file
// 				$logFile - path to log file
//
function readLastLine ($file) {
  $LastLine = "";
  if ($handle = fopen($file, 'r')) {				// Open file
    fseek($handle, -1, SEEK_END);				// Jump to last character
    $pos = ftell($handle);					// Store pointer's position
    while((($C = fgetc($handle)) == "\n") && ($pos > 0)) { // Skip all new lines at the end of the file
	  fseek($handle, $pos--);
    }
										// Loop backword util "\n" is found.
    while((($C = fgetc($handle)) != "\n") && ($pos > 0)) {
	  $LastLine = $C.$LastLine;
	  fseek($handle, $pos--);
    }
    fclose($handle);
  }
  return $LastLine;
}

//  collectFmDbs - return list of all FM database files in directory, check all the directories downline
// Call: 	$list = collectFmDbs($folder);
// Where:	$list - list of FM DB files
//			$folder - DB folder
//
function collectFmDbs($dir) {
  global $FM_EXTENSIONS;
  $result = array();
  if (!file_exists($dir)) {			// Check if dir exists
	return $result;
  }

  foreach (scandir($dir) as $file) {
	if (!in_array($file,array(".",".."))) {
	  if (is_dir($dir.'/'.$file)) {
		$result = array_merge($result, collectFmDbs($dir.'/'.$file));
	  }
	  else {
		$path_parts = pathinfo($dir.'/'.$file);
		if (array_key_exists('extension',$path_parts) && in_array($path_parts['extension'],$FM_EXTENSIONS)) {
		  $result[] = $path_parts['filename'];
	    }
	  }
	}
  }
  return $result;
}

?>