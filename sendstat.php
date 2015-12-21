<?php

// sendstat.php - Calculate and send FM usage statistic
//				Report is calculated for previous month. If we start it at any dat in March, we'll send report for February
// Run:	sendstat --month=X
// Where X -  month in the past we want to get stat for
//  For example: 1 - one month ago, 2 - two monthes ago...
// If we start script 12/20/2015 with --month=2 it will select data for the October, 2015
//
//
//
//
// Vers. 1.0 , YP 12/08/2015
//
//
// Copyright © 2015 Neo Code Software Ltd
// Artisanal FileMaker & PHP hosting and development since 2002
// 1-888-748-0668 http://store.neocodesoftware.com
//
// History:
// ALPHA, YP 12/08/2015 - Alpha release
//

include_once('config.php');			// configuration and common stuff
require 'phpmailer/PHPMailerAutoload.php';
									// Global vars
$options = array();
$reportHeader = "<TABLE BORDER='1'><TR style='font-weight:bold; text-align:center'><TD>Client</TD><TD>Used Minutes</TD><TD>Billable Minutes</TD></TR>";
$reportTail = "</TABLE>";

									// Start here
$LOG->message("sendstat started");
$ckStart = new CheckStart($CONFIG['VAR_DIR'].'sendstat.lock');
if(!$ckStart->canStart()) {			// Check if script already running. Doesn't allow customer to send multiple restart requests
  printLogAndDie("Script is already running.");
}

foreach($argv as $v) {				// Read input works in php < 5.3
  if(false !== strpos($v, '=')) {
	$parts = explode('=', $v);
	if (strpos($parts[0],'--')===0) {
	  $options[substr($parts[0],2)] = $parts[1];
	}
  }
}
if (!array_key_exists('month',$options) || !preg_match('/^\d+$/',$options['month'])) {
  printLogAndDie("invalid parameters");
}

$result = '';						// result data to send
$monthName = '';
$serverName = '';
									// Select and calculate sum here
$stmt = "SELECT OwnerName,ServerName,DATE_FORMAT(StartDate,'%M %Y') as MonthName, ROUND(SUM(TIMESTAMPDIFF(SECOND, StartDate, EndDate))/60) as UsedMinutes ".
        "FROM FmClientSession ".
        "WHERE MONTH(StartDate) = MONTH(CURDATE()-INTERVAL ? MONTH) and YEAR(StartDate) = YEAR(CURDATE()-INTERVAL ? MONTH) ".
        "AND ConnectionType=? ".
        "GROUP BY OwnerName ORDER BY OwnerName";
$sth = $DB->dbh->prepare($stmt);
$sth->execute(array($options['month'],$options['month'],FM_CONN_PAID));
if ($sth->errorInfo()[1]) {
  printLogAndDie("DB error: ".$sth->errorInfo()[2]);
}
while ($rec = $sth->fetch(PDO::FETCH_ASSOC)) {
  $monthName = $rec['MonthName'];					// All the records have the same month name
  $serverName = $rec['ServerName'];					// All the records have the same server name
  $billedMin = $rec['UsedMinutes'] - $CONFIG['FM_FREE_USAGE_TIME']*60;
  $result .= "<TR>".
  			 "<TD>".$rec['OwnerName']."</TD>".
  			 "<TD style='text-align:right'>".$rec['UsedMinutes']."</TD>".
  			 "<TD style='text-align:right'>".($billedMin < 0 ? 0 : $billedMin)."</TD>".
  			 "</TR>";

}

									// Send stat
$mail = new PHPMailer;
//$mail->SMTPDebug = 3;                         // Enable verbose debug output

$mail->isSMTP();                                // Set mailer to use SMTP
$mail->Host = $CONFIG['MAIL_HOST'];  			// Specify main and backup SMTP servers
$mail->SMTPAuth = $CONFIG['MAIL_SMTP_AUTH'];    // Enable SMTP authentication
$mail->Username = $CONFIG['MAIL_USER'];         // SMTP username
$mail->Password = $CONFIG['MAIL_PASS'];         // SMTP password
$mail->SMTPSecure = $CONFIG['MAIL_SMTP_SECURE'];// Enable TLS encryption, `ssl` also accepted
$mail->Port = $CONFIG['MAIL_PORT'];              // TCP port to connect to

$mail->setFrom($CONFIG['MAIL_SEND_FROM'], $CONFIG['MAIL_SEND_FROM_NAME']);
foreach ($CONFIG['MAIL_SEND_TO'] as $addr) {
  $mail->addAddress($addr);
}
$mail->isHTML(true);                                  // Set email format to HTML

$mail->Subject = "$serverName Usage report for $monthName";
$mail->Body    = "<b>$serverName Usage report for $monthName</b><BR><BR>".
				 "This is an automatically generated message. Do not reply.<BR><BR>".
  				 ($result ? $reportHeader.$result.$reportTail : "No data");

if(!$mail->send()) {
  $LOG->message('Message could not be sent. Mailer Error: ' . $mail->ErrorInfo);
} else {
  $LOG->message('Message has been sent');
}

$LOG->message("sendstat finished");
exit;

?>