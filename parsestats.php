<?php

// parsestats.php - parse FileMaker Stats log file
//
// Vers. 1.0 , YP 03/21/2016
//
//Log Example:
//Timestamp	Network KB/sec In	Network KB/sec Out	Disk KB/sec Read	Disk KB/sec Written	Cache Hit %	Cache Unsaved %	Pro Clients	Open Databases	ODBC/JDBC Clients	WebDirect Clients	Custom Web Clients	Remote Calls/sec	Remote Calls In Progress	Elapsed Time/call	Wait Time/call	I/O Time/call	Go Clients
//2016-03-17 07:58:47.096 -0700	0	0	0	0	100	0	12	57	0	0	0	0	0	0	0	0	0
//
// Run:	parsestats.php
//
// Parse start logs and ave max number of connected users in google calendar for every 30 min

//
// Copyright © 2016 Neo Code Software Ltd
// Artisanal FileMaker & PHP hosting and development since 2002
// 1-888-748-0668 http://store.neocodesoftware.com
//
// History:
// 1.0, YP 03/21/2016 - Initial release

//

include_once('config.php');			// configuration and common stuff
include_once('class.GoogleApi.php'); // Google API class

									// Global vars
$PERIOD_MIN = 30;
									// Start here
$LOG->message("parsestats started");
$ckStart = new CheckStart($CONFIG['VAR_DIR'].'parsestats.lock');
if(!$ckStart->canStart()) {			// Check if script already running. Doesn't allow customer to send multiple restart requests
  printLogAndDie("Script is already running.");
}

$googleAPI = new GoogleAPI($CONFIG);	// GoogleAPI object

$startDate = $endDate = '';				// Start and end date for report
$sth = $DB->dbh->prepare("SELECT EndDate FROM FmClientStats ORDER BY EndDate DESC LIMIT 1");
$sth->execute();
if ($sth->errorInfo()[1]) {
  printLogAndDie("DB error: ".$sth->errorInfo()[2]);
}
if ($lastRecord = $sth->fetch(PDO::FETCH_ASSOC)) {
  $startDate = $lastRecord['EndDate'];	// Start date for new report
  $endDate = date('Y-m-d H:i:s',  strtotime($startDate.' + '.$PERIOD_MIN.' minute'));
}

									// Calculate end date for report - round down to closest 30 min. Don't check logs after this date
$endDateTimeStamp = mktime(date("H"), date("i") > $PERIOD_MIN ? $PERIOD_MIN : 0 , 0, date("m")  , date("d"), date("Y"));
$reportEndDate = date('Y-m-d H:i:s',$endDateTimeStamp);

$max_clients_connected = 0;
$line_num = 0;
if ($handle = fopen($CONFIG['FM_STATS_LOG'], "r")) {
  while (($line = fgets($handle)) !== false) {
	$line_num++;
    if ($line_num == 1) {
      continue;						// skip first line
    }
	$line_parts = explode("\t", $line);
	if (preg_match('/^([\d-]+) ([\d:]+)\.(\d+) ([=\d-]+ )?/', $line_parts[0], $matches)) {
	  $rdate = $matches[1];
	  $rtime = $matches[2];
	  $rsec = $matches[3];
	} else {
	  $LOG->message("Can't parse date/time: $line");
	  continue;                        // Invalid date/time format
	}

    if (!$startDate) {					// First run
	  $st = date_parse("$rdate $rtime");
	  $startDate = $st['year'].'-'.$st['month'].'-'.$st['day'].' '.$st['hour'].':'.($st['minute'] > $PERIOD_MIN ? '30:00' : '00:00');
	  $endDate = date('Y-m-d H:i:s',  strtotime($startDate.' + '.$PERIOD_MIN.' minute'));
    }

    if ($endDate <= "$rdate $rtime" ) {	// New date - save data and collect new max value
	  if ($max_clients_connected) {		// Save max value in DB and calendar
	    $sth = $DB->dbh->prepare("INSERT INTO FmClientStats SET StartDate=?, EndDate=?, MaxClients=?");
	    $sth->execute(array($startDate,$endDate,$max_clients_connected));
	    if ($sth->errorInfo()[1]) {
		  printLogAndDie("DB error: ".$sth->errorInfo()[2]);
	    }
		$res = $googleAPI->add_event(array('start' => $startDate,
										   'end' => $endDate,
										   'msg' => "Clients: $max_clients_connected",
		  								   'colorId' => $CONFIG['FM_SUM_CLIENTS_COLOR']));

	    $LOG->message("Max number of clients $startDate - $endDate is $max_clients_connected. Saved in event Id $res");
	  }
	  $max_clients_connected = 0;
	  $startDate = $endDate;
	  $endDate = date('Y-m-d H:i:s',  strtotime($startDate.' + '.$PERIOD_MIN.' minute'));
    }

	if ($reportEndDate < "$rdate $rtime") {	// Exit here, do not process further records
	  break;
	}

	if ($startDate > "$rdate $rtime") {     // Check date/time of the record
	  continue;								// we already have these values in DB - go to next line(record)
	}

	$clients_connected = $line_parts[7] + $line_parts[9] + $line_parts[10] + $line_parts[11] + $line_parts[17]; // Pro Clients(7) + ODBC/JDBC Clients(9) + WebDirect Clients(10) + Custom Web Clients(11) + Go Clients(17)
	if ($clients_connected > $max_clients_connected) {
	  $max_clients_connected =  $clients_connected;
	}
  }
  fclose($handle);

} else {
  $LOG->message("Unable to open file " . $logFile);
}

$LOG->message("parsestats finished");
exit;


?>