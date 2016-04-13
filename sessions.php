<?php

// sessions.php - Save FM sessions based on log saved in DB
//
//
// Vers. 1.0 , YP 03/22/2016
//
//
// Copyright © 2015 Neo Code Software Ltd
// Artisanal FileMaker & PHP hosting and development since 2002
// 1-888-748-0668 http://store.neocodesoftware.com
//
// History:
// ALPHA, YP 12/08/2015 - Alpha release
// 1.0, YP 03/22/2016 - Added schedule tasks events processing
//

include_once('config.php');			// configuration and common stuff
									// Global vars
define('FM_DEFAULT_SESSION', 10*60);// Default FM session time. We use it if we can't find close DB(or connection) record
define('FM_MAX_DURATION_SESSION', 12*60*60);// Max FM session time. We close session with default time if we can't find close DB (session) record and session time is more that FM_MAX_DURATION_SESSION
define('FM_RELOGIN_TIMEOUT',1);		// If 2 actions from the same client are xx sec close to each other we save them in one session (like relogin as ... action)

define('FM_TYPE_OTHER','Other');
define('FM_TYPE_REGEXP','/^([a-zA-Z_ ]+) /');
define('FM_CONN_PAID_REGEXP','/(^Go )|(^Go_iPad)|(\[fmwebdirect\]S)/');
									// Start here
$LOG->message("session started");
$ckStart = new CheckStart($CONFIG['VAR_DIR'].'sessions.lock');
if(!$ckStart->canStart()) {			// Check if script already running. Doesn't allow customer to send multiple restart requests
  printLogAndDie("Script is already running.");
}

CleanUpDB();						// Cleanup DB

$user_session = array();
$script_session = array();
$opened_connections = array();
$session_to_close = array();
$last_used_app = array();
$lastProcessedDate = '';
$sth = $DB->dbh->prepare("SELECT * FROM FmAccessLog WHERE SessionId=? ORDER BY LogDate, LogTime, LogSec, Id LIMIT 100000");	# Limit request to prevent Allowed memory size ... exhausted error
$sth->execute(array(0));
if ($sth->errorInfo()[1]) {
  printLogAndDie("DB error: ".$sth->errorInfo()[2]);
}
while ($rec = $sth->fetch(PDO::FETCH_ASSOC)) {
  $lastProcessedDate = $rec['LogDate'].' '.$rec['LogTime'];				// Store date/time of last processed record for future use

//if (!(($rec['Id'] >= 479770 && $rec['Id'] <= 479773) || ($rec['Id'] >= 480177 and $rec['Id'] <= 480178))) {
//  if ($rec['FmClient'] != "Name" || $rec['LogDate'] != '2015-12-04') {
//	continue;
//  }
//  print "Rec: ".$rec['Id']."\n";

  $clientKey = strtolower($rec['FmClient'].' '.$rec['FmClientIP']);
  $fmClientKey = strtolower($rec['FmClient'].' '.$rec['FmClientIP'].'_'.$rec['FmLoginName']);

														// Check if this client has sessions started long time ago
														// Close found sessions
  if (array_key_exists($clientKey,$user_session)) {
    for ($i = count($user_session[$clientKey])-1; $i>=0; $i--) { // Search starting with last opened session
	  if (strtotime($rec['LogDate'].' '.$rec['LogTime']) - strtotime($user_session[$clientKey][$i]['LastDate']) > FM_MAX_DURATION_SESSION) {
	    createSession($user_session[$clientKey][$i]);
	    if (array_key_exists($clientKey,$session_to_close)) { // Remove this session from $session_to_close if exists
	      unset($session_to_close[$clientKey]);
	    }
	    array_splice($user_session[$clientKey],$i,1);			// Remove this session from the list
	  }
	}
  }

  if ($rec['LogCode'] == '638') {			// Opening a connection. Save record data and TimeSec.
	$opened_connections[$clientKey] =  array_merge($rec,array("TimeSec" => strtotime($rec['LogDate'].' '.$rec['LogTime'])));
	if (!array_key_exists($clientKey,$user_session)) {
	  $user_session[$clientKey] = array();	// Prepare array to store session information
	}
	$last_used_app[$clientKey] = $rec['FmApp'];	// Last used App for this client
	if (array_key_exists($clientKey,$session_to_close)) {	//  We have not closed session from this client, we need to close it
	  $ses2Close = $session_to_close[$clientKey];
	  createSession($user_session[$clientKey][$ses2Close]);
	  array_splice($user_session[$clientKey],$ses2Close,1);			// Remove element from array
	  unset($session_to_close[$clientKey]);
	}
  }

  else if ($rec['LogCode'] == '94') {			// Open DB code
	$createNew = 0;
	if (array_key_exists($clientKey,$opened_connections)) {	// We have opened connection - create new session
	  $createNew = 1;
	}
	else if (array_key_exists($clientKey,$user_session) && count($user_session[$clientKey])) { // Find opened session(s) for this client. Add DB opening in exist session
      $foundIndex = -1;
	  for ($i = count($user_session[$clientKey])-1; $i>=0; $i--) { // Search starting from last opened session
		if (strcasecmp($user_session[$clientKey][$i]['FmClientKey'],$fmClientKey)==0) {	  // Found session with the same $fmClientKey
		  $foundIndex = $i;
		  break;
		}														// Check if have session with any activity less than  FM_RELOGIN_TIMEOUT sec ago
		else if (strtotime($rec['LogDate'].' '.$rec['LogTime']) - strtotime($user_session[$clientKey][$i]['LastDate']) < FM_RELOGIN_TIMEOUT) {
		  $foundIndex = $i;
		  break;
		}
	  }
	  if ($foundIndex == -1) {									// Session still not found - Save data in last opened session
		$foundIndex = count($user_session[$clientKey]) - 1;
	  }
      $closePreviousSession = 0;								// If we need to create new session
	  if (array_key_exists($rec['DbName'],$user_session[$clientKey][$foundIndex]['OpenDBs'])) {	// This DB is already opened. New opening means we missed close DB in logs
		unset($user_session[$clientKey][$foundIndex]['OpenDBs'][$rec['DbName']]);
		if (!count($user_session[$clientKey][$foundIndex]['OpenDBs'])){ // No more opened DBs - Save session
		  $createNew = 1;									// Session closed - we need new session for current record
		  $closePreviousSession = 1;
    	}
	  }
	  														// Check if previous action was long time ago - this means we missed close DB in logs and we need to close it
      else if (strtotime($rec['LogDate'].' '.$rec['LogTime']) - strtotime($user_session[$clientKey][$foundIndex]['LastDate']) > FM_MAX_DURATION_SESSION) {
		$createNew = 1;
		$closePreviousSession = 1;
	  }

	  if ($closePreviousSession) {										// Close current session and create new one
        createSession($user_session[$clientKey][$foundIndex]);
        array_splice($user_session[$clientKey],$foundIndex,1);			// Remove element from array
      }
      if (!$createNew) {									// Add this record in session
        $user_session[$clientKey][$foundIndex]['OpenDBs'][$rec['DbName']] = $rec['FmLoginName'];
        $user_session[$clientKey][$foundIndex]['Ids'][] = $rec['Id'];
        $user_session[$clientKey][$foundIndex]['LastDate'] = $rec['LogDate'].' '.$rec['LogTime'];	// Date/time of teh last action in this session
		if (array_key_exists($clientKey,$session_to_close) &&	// If this session was in the list of sessions to close
		    $foundIndex ==  $session_to_close[$clientKey])
		{    													// we have new DB open - remove this session from the list of sessions for closing
		  unset($session_to_close[$clientKey]);
		}
	  }
	}
	else {													// No sessions for this client - create new
	  $createNew = 1;
	}
	if ($createNew) {
	  if (!array_key_exists($clientKey,$user_session)) {
		$user_session[$clientKey] = array();	// Prepare array to store session information
	  }
	  $user_session[$clientKey][] = array(
		'SessionType' => FM_ACCESS_TYPE,
	    'FmClientKey' => $fmClientKey,
	    'FmApp' => array_key_exists($clientKey,$opened_connections) ? $opened_connections[$clientKey]['FmApp'] : array_key_exists($clientKey,$last_used_app) ? $last_used_app[$clientKey] : '',
	    'OpenDBs' => array($rec['DbName'] => $rec['FmLoginName']),
	    'Rec' => $rec,
	    'Ids' => array_key_exists($clientKey,$opened_connections) ? array($rec['Id'],$opened_connections[$clientKey]['Id']) : array($rec['Id']),
	    'StartDate' => $rec['LogDate'].' '.$rec['LogTime'], // Start session date - date/time of first found record
	    'LastDate' => $rec['LogDate'].' '.$rec['LogTime']	// Date/time of the last action in this session
	  );
      if (array_key_exists($clientKey,$opened_connections)) { // Remove record from $opened_connections
	    unset($opened_connections[$clientKey]);
	  }
	}

  }

  else if ($rec['LogCode'] == '98') {						// Close DB code
	if (array_key_exists($clientKey,$user_session)) {
	  $found = 0;											// Try to find this DB opened
      for ($i = count($user_session[$clientKey])-1; $i>=0; $i--) { // Search starting with last opened session
	    foreach ($user_session[$clientKey][$i]['OpenDBs'] as $dbName => $dbLogin) {
          if (strcasecmp($dbName,$rec['DbName'])==0 && strcasecmp($dbLogin,$rec['FmLoginName'])==0) { // Find this DB opened by this user in list of opened DBs (case-insensitive comparison)
		    unset($user_session[$clientKey][$i]['OpenDBs'][$dbName]);	// Remove DB from lis tof opened DBS in this session
			$user_session[$clientKey][$i]['Ids'][] = $rec['Id'];		// Ad record in list of records in this session
			$user_session[$clientKey][$i]['LastDate'] = $rec['LogDate'].' '.$rec['LogTime'];	// Date/time of the last action in this session
			if (!count($user_session[$clientKey][$i]['OpenDBs'])){ 	// No more opened DBs - Save session
			  $session_to_close[$clientKey] = $i;					// Memorize session we want to close
			}
			$found = 1;
			break;
		  }
        }
        if ($found) {
          break;
        }
	  }
	  if (!$found) {
		markLogRecord(-1,$rec['Id']);						// Don't know what to do with this record, just mark is as processed
	  }
	}
	else {
      $LOG->message("No session for this close record. Id: ".$rec['Id']);
	  markLogRecord(-1,$rec['Id']);							// No session for this close record. Mark is as processed
	}
  }

  else if ($rec['LogCode'] == '22' || $rec['LogCode'] == '30') {			// Close connection code
	$ses2Close = -1;
	if (array_key_exists($clientKey,$session_to_close)) {	//  We have prepared session to close
	  $ses2Close = $session_to_close[$clientKey];
	}
	else if (array_key_exists($clientKey,$user_session) && count($user_session[$clientKey])){	// Close the recent opened connection
	  $ses2Close = count($user_session[$clientKey]) - 1;
	}
	else {
      //$LOG->message("No session found to close. Record Id: ".$rec['Id']);
	  markLogRecord(-1,$rec['Id']);				// No sessions found for this client. Mark record as processed
	}
	if ($ses2Close != -1) {						// We found session to close - close it
	  $user_session[$clientKey][$ses2Close]['Ids'][] = $rec['Id'];
	  $user_session[$clientKey][$ses2Close]['EndDate']= $rec['LogDate'].' '.$rec['LogTime'];
	  createSession($user_session[$clientKey][$ses2Close]);
	  array_splice($user_session[$clientKey],$ses2Close,1);
	  if (array_key_exists($clientKey,$session_to_close)) {	//  Remove from the list of sessions to close
		unset($session_to_close[$clientKey]);
	  }
	}
  }

  else if ($rec['LogCode'] == '689') {			// Schedule "%1" has started FileMaker script
    if (array_key_exists($clientKey,$script_session)) {	// New schedule started. Didn't find "complete" records for previous session
      if (array_key_exists('LastDate',$script_session[$clientKey])) {
	    $script_session[$clientKey]['EndDate'] = $script_session[$clientKey]['LastDate'];
	  }
	  createSession($script_session[$clientKey]);
	  unset($script_session[$clientKey]);
    }
	$script_session[$clientKey]= array(
	  'SessionType' => FM_SCHED_TYPE,
	  'FmClientKey' => $fmClientKey,
	  'FmApp' => '',
	  'Rec' => $rec,
	  'Ids' => array($rec['Id']),
	  'StartDate' => $rec['LogDate'].' '.$rec['LogTime'], // Start session date - date/time of first found record
	  'LastDate' => $rec['LogDate'].' '.$rec['LogTime']	// Date/time of the last action in this session
	);
  }
  else if ($rec['LogCode'] == '645') {			// Schedule "Send milestone notifications" scripting error
    if (array_key_exists($clientKey,$script_session)) { // Find opened session(s) for this client. Add record in exist session
	  $script_session[$clientKey]['Ids'][] = $rec['Id'];
	  $script_session[$clientKey]['LastDate'] = $rec['LogDate'].' '.$rec['LogTime'];	// Date/time of the last action in this session
	}
	else {
	  markLogRecord(-1,$rec['Id']);
	}
  }
  else if ($rec['LogCode'] == '150' || $rec['LogCode'] == '644' || $rec['LogCode'] == '152') {			// Schedule "%1" completed/aborted ...
	if (array_key_exists($clientKey,$script_session)) { // Find opened session(s) for this client. Add record in exist session
	  $script_session[$clientKey]['Ids'][] = $rec['Id'];
	  $script_session[$clientKey]['EndDate']= $rec['LogDate'].' '.$rec['LogTime'];
	  createSession($script_session[$clientKey]);
	  unset($script_session[$clientKey]);
	}
    else {
	  markLogRecord(-1,$rec['Id']);
    }
  }

  else {									// Don't know how to use this code
	$LOG->message("Invalid code in record ".$rec['Id']);
	markLogRecord(-1,$rec['Id']);
  }
}
											// Process all not closed session
foreach (array_keys($user_session) as $clientKey) {
  for ($i=0;$i<count($user_session[$clientKey]);$i++) {// Save sessions if last action was long enough time ago
	if (strtotime($lastProcessedDate) - strtotime($user_session[$clientKey][$i]['LastDate']) > FM_MAX_DURATION_SESSION) {
      createSession($user_session[$clientKey][$i]);
	}
  }
}

$LOG->message("session finished");
exit;


// createSession - create client session record and mark log records
// Call:	$sessionId = createSession($rec);
// Where:	$rec - session data:
//			$rec['Ids'] - list of log records we need to assign to this session
//			$rec['Rec'] - session data
//			$sessionId - Id of just created session
//
function createSession($rec) {
  global $DB;
//print ("Create session");
//print_r($rec);
  if (!array_key_exists('EndDate',$rec)) {			// Close session without end date
	$rec['EndDate'] = setSessionEndDate ($rec);
  }
  $rec['SessionTime'] = strtotime($rec['EndDate']) - strtotime($rec['StartDate']);
  													// Create new session
  $sth = $DB->dbh->prepare("INSERT INTO FmClientSession (SessionType,StartDate,EndDate,SessionTime,ServerName,FmClient,FmClientIP,FmLoginName,FmApp,FmAppType,ConnectionType,OwnerName) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
  $sth->execute(array($rec['SessionType'],$rec['StartDate'],$rec['EndDate'],$rec['SessionTime'],$rec['Rec']['ServerName'],$rec['Rec']['FmClient'],$rec['Rec']['FmClientIP'],$rec['Rec']['FmLoginName'],$rec['FmApp'],getFmAppType($rec['FmApp']),getFmConnType($rec['FmApp']),$rec['Rec']['OwnerName']));
  if ($sth->errorInfo()[1]) {
	printLogAndDie("DB error: ".$sth->errorInfo()[2]);
  }
  $sessionId = $DB->dbh->lastInsertId();			// Id of created session
  foreach ($rec['Ids'] as $id) {					// Mark log records with this session Id
	markLogRecord($sessionId,$id);
  }
  return $sessionId;
}

// markLogRecord - mark log record with sessonId
// Call:	markLogRecord($sessionId,$id);
// Where:	$sessionId - session Id
//			$id - log record Id
//
function markLogRecord($sessionId,$id) {
  global $DB;
  $sth = $DB->dbh->prepare("UPDATE FmAccessLog SET SessionId=? WHERE Id=?");
  $sth->execute(array($sessionId,$id));
  if ($sth->errorInfo()[1]) {
	printLogAndDie("DB error: ".$sth->errorInfo()[2]);
  }
}


// setSessionEndDate - set session's end date if session is closed without disconnect code
// Call: 	$endDate = setSessionEndDate($sessRec);
// Where:	$endDate - end date for session
//			$sessRec - session details
//
function setSessionEndDate ($sessRec) {
  if (strtotime($sessRec['LastDate']) - strtotime($sessRec['StartDate']) < FM_DEFAULT_SESSION) {  // We don't have end session time and session time is too small
	return date('Y-m-d H:i:s',strtotime($sessRec['LastDate']) + FM_DEFAULT_SESSION);
  }
  else {
	return $sessRec['LastDate'];
  }
}

// getFmAppType - return FM application type for FM app record in logs
//  Call:	$type = getFmAppType($fmApp);
//
function getFmAppType($fmApp) {
  if (preg_match(FM_TYPE_REGEXP, $fmApp, $matches)) {
	return $matches[1];
  }
  else {
    return FM_TYPE_OTHER;
  }
}

// getFmConnType - return connection type 'free' or 'paid'
// Call:	$connType =  getFmConnType($fmApp);
function getFmConnType($fmApp) {
  if (preg_match(FM_CONN_PAID_REGEXP, $fmApp, $matches)) {
    return FM_CONN_PAID;
  }
  else {
    return FM_CONN_FREE;
  }
}

?>