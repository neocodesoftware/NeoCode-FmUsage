<?php

// report_sched.php - Calculate and send FM usage statistic for scheduled tasks
//				All scheduled tasks are saved in google calendar
//
// Vers. DD-1.0 , YP 03/18/2016
//
// Run:	php report_sched.php
//

//
// Copyright © 2016 Neo Code Software Ltd
// Artisanal FileMaker & PHP hosting and development since 2002
// 1-888-748-0668 http://store.neocodesoftware.com
//
// History:
// 1.0, YP 03/18/2016 - Initial release
//

include_once('config.php');			// configuration and common stuff
include_once('class.GoogleApi.php'); // Google API class
									// Global vars
$options = array();
									// Local functions
// markRecProcessed - mark  record as processed
// Call:	markRecProcessed($sessionId,$id);
// Where:	$sessionId - session Id
//
function markRecProcessed($sessionId) {
  global $DB;
  $sth1 = $DB->dbh->prepare("UPDATE FmClientSession SET Processed=? WHERE  SessionId=?");
  $sth1->execute(array(1,$sessionId));
  if ($sth1->errorInfo()[1]) {
	printLogAndDie("DB error: ".$sth1->errorInfo()[2]);
  }
  return '';
}									// -- markRecProcessed --
									// Start here
$LOG->message("report_sched started");
$ckStart = new CheckStart($CONFIG['VAR_DIR'].'report_sched.lock');
if(!$ckStart->canStart()) {			// Check if script already running. Doesn't allow customer to send multiple restart requests
  printLogAndDie("Script is already running.");
}

$googleAPI = new GoogleAPI($CONFIG);	// GoogleAPI object

										// Load stats for script not included in $CONFIG['FM_SCHED_SCRIPTS_2_SUM']
$qval = array(0,FM_SCHED_TYPE);
$stmt = "SELECT SessionId, DATE_FORMAT(StartDate,'%Y-%m-%d %H:%i:%S') as stDate,DATE_FORMAT(EndDate,'%Y-%m-%d %H:%i:%S') as enDate, ".
        "TIMESTAMPDIFF(SECOND,StartDate,EndDate) as ExecTime, FmClient as SchedName ".
        "FROM FmClientSession ".
        "WHERE  Processed=? and SessionType=? ";
if (array_key_exists('FM_SCHED_SCRIPTS_2_SUM',$CONFIG) && count($CONFIG['FM_SCHED_SCRIPTS_2_SUM'])) {
  $stmt .= " AND FmClient NOT IN (?".str_repeat(",?",count($CONFIG['FM_SCHED_SCRIPTS_2_SUM'])-1).") ";
  $qval = array_merge($qval,$CONFIG['FM_SCHED_SCRIPTS_2_SUM']);
}
$stmt .= "ORDER BY SessionId";

$sth = $DB->dbh->prepare($stmt);
$sth->execute($qval);
if ($sth->errorInfo()[1]) {
  printLogAndDie("DB error: ".$sth->errorInfo()[2]);
}
while ($rec = $sth->fetch(PDO::FETCH_ASSOC)) {
  $res = $googleAPI->add_event(array('start' => $rec['stDate'],
									 'end' => $rec['enDate'],
									 'msg' => $rec['SchedName'].". Exec time: ".formatSec($rec["ExecTime"])));
  if ($res) {
	$LOG->message("Event for session ". $rec['SessionId'] ." created. EventId: ".$res);
	markRecProcessed($rec['SessionId']);	// Mark session as processed
  }
  else {
	printLogAndDie("Error creating event for session ". $rec['SessionId']);
  }
}

if (array_key_exists('FM_SCHED_SCRIPTS_2_SUM',$CONFIG) && count($CONFIG['FM_SCHED_SCRIPTS_2_SUM'])) {  // Load stat for scripts in $CONFIG['FM_SCHED_SCRIPTS_2_SUM']
  $curStartDate = $curEndDate = $curDescr = '';
  $curExecTime = $curExecCount = 0;
  $qval = array_merge(array(0,FM_SCHED_TYPE),$CONFIG['FM_SCHED_SCRIPTS_2_SUM']);
  $stmt = "SELECT FmClient as SchedName, SUM(TIMESTAMPDIFF(SECOND,StartDate,EndDate)) as ExecTime, COUNT(SessionId) as ExecCount, ".
	      "CONCAT(DATE_FORMAT(StartDate,'%Y-%m-%d %H:'), DATE_FORMAT(StartDate,'%i') - DATE_FORMAT(StartDate,'%i') MOD 30,':00') as stDate, ".
	      "CONCAT(DATE_FORMAT(date_add(StartDate, INTERVAL 30 minute),'%Y-%m-%d %H:'), DATE_FORMAT(date_add(StartDate, INTERVAL 30 minute),'%i') - DATE_FORMAT(date_add(StartDate, INTERVAL 30 minute),'%i') MOD 30,':00') as enDate ".
          "FROM FmClientSession ".
          "WHERE Processed=? AND SessionType=? AND FmClient IN (?".str_repeat(",?",count($CONFIG['FM_SCHED_SCRIPTS_2_SUM'])-1).") ".
          "GROUP BY FmClient, stDate order by stDate";
  $sth = $DB->dbh->prepare($stmt);
  $sth->execute($qval);
  if ($sth->errorInfo()[1]) {
    printLogAndDie("DB error: ".$sth->errorInfo()[2]);
  }
  while ($rec = $sth->fetch(PDO::FETCH_ASSOC)) {
    if ($curStartDate && $curStartDate != $rec['stDate']) {			// New date - save collected data in calendar
	  $res = $googleAPI->add_event(array('start' => $curStartDate,
		'end' => $curEndDate,
		'msg' => count($CONFIG['FM_SCHED_SCRIPTS_2_SUM'])." everyminute scripts executed $curExecCount times. Time: ".formatSec($curExecTime),
		'descr' => $curDescr,
		'colorId' => $CONFIG['FM_SCHED_SCRIPTS_2_SUM_COLOR']));
      if ($res) {
	    $LOG->message("Event for everyminute scripts $curStartDate - $curEndDate created. EventId: ".$res);
      }
      else {
	    printLogAndDie("Error creating event for session ". $rec['SessionId']);
      }
	  $curExecTime = $curExecCount = 0;
	  $curDescr = '';
	}
	$curStartDate = $rec['stDate'];
	$curEndDate = $rec['enDate'];
	$curExecTime += $rec["ExecTime"];
	$curExecCount += $rec["ExecCount"];
	$curDescr .= $curDescr ? "\n" : '';
	$curDescr .= $rec['SchedName'].". Execution summary time: ".$rec["ExecTime"]." sec, script executed ".$rec["ExecCount"]." times";
  }
													// Mark records as process.
  													// Races are possible here. Live with them for now
  $sth1 = $DB->dbh->prepare("UPDATE FmClientSession SET Processed=1 WHERE Processed=? AND SessionType=? AND FmClient IN (?".str_repeat(",?",count($CONFIG['FM_SCHED_SCRIPTS_2_SUM'])-1).") ");
  $sth1->execute($qval);
  if ($sth1->errorInfo()[1]) {
	printLogAndDie("DB error: ".$sth1->errorInfo()[2]);
  }
}

$LOG->message("report_sched finished");
exit;

?>