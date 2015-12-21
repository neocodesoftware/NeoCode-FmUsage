<?php

// parselog.php - parse FileMaker access log file
// 	Access log codes: http://help.filemaker.com/app/answers/detail/a_id/7275/~/filemaker-server-event-log-messages
//
// Examples:
//94 Client "%1" opening database "%2" as "%3".
//2015-03-31 07:04:12.581 -0700	Information	94	NICOLE	Client "Yurii [192.168.1.10]" opening database "MyDatabase" as "MyUserName".
//
//98 Client "%1" closing database "%2" as "%3".
//2015-03-31 07:17:02.176 -0700	Information	98	NICOLE	Client "Yurii (MyComputer) [192.168.1.10]" closing database "MyDatabase" as "MyUserName".
//
//22 Client "%1" closing a connection.
//2015-03-31 07:20:25.208 -0700	Information	22	NICOLE	Client "Yurii (MyComputer) [192.168.1.10]" closing a connection.
//
//638	Client "%1" opening a connection from "%2" using "%3".
//2015-03-31 07:21:05.770 -0700	Information	638	NICOLE	Client "Yurii" opening a connection from "MyComputer (192.168.1.10)" using "Pro 13.0v4 [fmapp]".
//
//
// Vers. 1.0 , YP 12/08/2015
//
// Parse FileMaker access logs.
//
// Copyright © 2015 Neo Code Software Ltd
// Artisanal FileMaker & PHP hosting and development since 2002
// 1-888-748-0668 http://store.neocodesoftware.com
//
// History:
// ALPHA, YP 12/08/2015 - Alpha release
//

include_once('config.php');			// configuration and common stuff
									// Global vars
$FM_EXTENSIONS = array('fmp12');
$FM_COLLECT_CODES = array (94,98,22,638);		// FM log codes we want to collect. THe complete list is here http://help.filemaker.com/app/answers/detail/a_id/7275/~/filemaker-server-event-log-messages

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

									// Read date/time of the last record in log
$lastDateTime = getDateFromLog(readLastLine($CONFIG['FM_ACCESS_LOG']));
									// Read date/time of the first record in log
$firstDateTime = getDateFromLog(readFirstLine($CONFIG['FM_ACCESS_LOG']));

$lastRecDateTime = '';				// Last record in DB
$sth = $DB->dbh->prepare("SELECT LogDate, LogTime FROM FmAccessLog ORDER BY LogDate DESC, LogTime DESC LIMIT 1");
$sth->execute();
if ($sth->errorInfo()[1]) {
  printLogAndDie("DB error: ".$sth->errorInfo()[2]);
}
if ($lastRecord = $sth->fetch(PDO::FETCH_ASSOC)) {
  $lastRecDateTime = $lastRecord['LogDate'].' '.$lastRecord['LogTime'];
}

$log2Parse = array($CONFIG['FM_ACCESS_LOG']);// List of log files to parse
if ($lastRecDateTime < $firstDateTime &&	// If last record in DB is less that first record in file - Log was rotated
  array_key_exists('FM_ACCESS_LOG_ROTATE',$CONFIG) &&
  $CONFIG['FM_ACCESS_LOG_ROTATE'])			// Additionally parse previous log
{
  $LOG->message("Additionally parse previous log: ".$CONFIG['FM_ACCESS_LOG_ROTATE']);
  array_unshift($log2Parse,$CONFIG['FM_ACCESS_LOG_ROTATE']);
}

foreach ($log2Parse as $logFile) {
  if ($handle = fopen($logFile, "r")) {
	while (($line = fgets($handle)) !== false) {
	  $rdate = $rtime = $rcode = $rserver = $rclient = $rip = $rdb = $rlogin = $rfmapp = '';
	  $line_parts = explode("\t", $line);
	  $rcode = $line_parts[2];
	  $rserver = $line_parts[3];
	  if (!in_array($rcode,$FM_COLLECT_CODES)) {			// We do not need this record
		continue;
	  }
	  if (preg_match('/^([\d-]+) ([\d:]+)\.\d+ ([=\d-]+ )?/', $line_parts[0], $matches)) {
		$rdate = $matches[1];
		$rtime = $matches[2];
	  } else {
		$LOG->message("Can't parse date/time: $line");
		continue;                        // Invalid date/time format
	  }

	  if ($lastDateTime && $lastDateTime <= "$rdate $rtime" ||        // Don't save very last records - we'll start with this records next time and this way we avoid duplicate records.
		  $lastRecDateTime && $lastRecDateTime >= "$rdate $rtime")	// We already have this date in DB
	  {
		continue;									// go to next line(record)
	  }

	  if ($rcode == '94' || $rcode == '98' || $rcode == '22') {
		if (preg_match('/^Client "([^"]+)" (closing a connection|[^"]+ "([^"]+)" as "([^"]+)")/', $line_parts[4], $matches)) {
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
	  else {
        continue;							// We don't need this record
	  }
											// Save record in DB

	  $sth = $DB->dbh->prepare("INSERT INTO FmAccessLog (LogDate,LogTime,LogCode,ServerName,FmClient,FmClientIP,DbName,FmLoginName,FmApp,OwnerName) VALUES (?,?,?,?,?,?,?,?,?,?)");
	  $sth->execute(array($rdate, $rtime, $rcode, $rserver, $rclient, $rip, $rdb, $rlogin, $rfmapp,array_key_exists($rdb,$databases) ? $databases[$rdb] : ''));
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