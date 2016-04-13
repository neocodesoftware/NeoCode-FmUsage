<?php
//
// class.GoogleAPI.php - google api wrapper class
//
// Vers. ALPHA, YP 03/22/2016
//
//
// History:
// ALPHA, YP 03/22/2016 - Initial release.
//

require 'google-api-php-client/src/Google/autoload.php';


class GoogleAPI {


  function __construct($cfg) {
	$this->CFG = $cfg;
	$client = $this->getGoogleApiClient();						// Get the API client
	$this->Service = new Google_Service_Calendar($client);	// Construct the service object.
  }

  /**
   * Returns an authorized API client.
   * @return Google_Client the authorized client object
   */
  private function getGoogleApiClient() {
	$client = new Google_Client();
	$client->setApplicationName($this->CFG['GOOGLE_API_APPLICATION_NAME']);
	$client->setScopes(Google_Service_Calendar::CALENDAR);
	$client->setAuthConfigFile($this->CFG['GOOGLE_API_CLIENT_SECRET_PATH']);
	$client->setAccessType('offline');

	// Load previously authorized credentials from a file.
	$credentialsPath = $this->CFG['GOOGLE_API_CREDENTIALS_PATH'];
	if (file_exists($credentialsPath)) {
	  $accessToken = file_get_contents($credentialsPath);
	} else {
	  // Request authorization from the user.
	  $authUrl = $client->createAuthUrl();
	  printf("Open the following link in your browser:\n%s\n", $authUrl);
	  print 'Enter verification code: ';
	  $authCode = trim(fgets(STDIN));

	  // Exchange authorization code for an access token.
	  $accessToken = $client->authenticate($authCode);

	  // Store the credentials to disk.
	  if(!file_exists(dirname($credentialsPath))) {
		mkdir(dirname($credentialsPath), 0700, true);
	  }
	  file_put_contents($credentialsPath, $accessToken);
	  printf("Credentials saved to %s\n", $credentialsPath);
	}
	$client->setAccessToken($accessToken);

	// Refresh the token if it's expired.
	if ($client->isAccessTokenExpired()) {
	  $client->refreshToken($client->getRefreshToken());
	  file_put_contents($credentialsPath, $client->getAccessToken());
	}
	return $client;
  }

  //
  // add_event - create event in google calendar
  // Call:		$id = add_event($start,$end, $msg)
  // Call:		$id = add_event($set)
  // Where:		$id - event Id if it was created successfully
  //			$set - - array of settings
  //			$set[start],$set[end] - start and end time of the event in the format YYYY-MM-DD HH:MM:SS
  //		 	$set[msg] - event subject
  //			$set[descr] - event description
  //
  public function add_event($set) {
	$event = new Google_Service_Calendar_Event(array(
	  'summary' => $set['msg'],
	  'description' => array_key_exists('descr',$set) ? $set['descr'] : '',
	  'start' => array(
		'dateTime' => date('Y-m-d\TH:i:s'.$this->CFG['GOOGLE_API_CALENDAR_TIMEZONE'], strtotime($set['start']))
	  ),
	  'end' => array(
		'dateTime' => date('Y-m-d\TH:i:s'.$this->CFG['GOOGLE_API_CALENDAR_TIMEZONE'], strtotime($set['end']))
	  ),
	 'colorId' => array_key_exists('colorId',$set) ? $set['colorId'] : 0,
	));

	$result = $this->Service->events->insert($this->CFG['GOOGLE_API_CALENDAR_ID'], $event);

	return $result->id;
  }

}