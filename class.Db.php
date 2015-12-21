<?php
/**
 * Created by Yurii.
 * Date: 12/7/2015
 * Time: 5:23 PM
 */


class Db {

  public $dbh = null;

  function __construct($cfg) {
	$this->CFG = $cfg;
	$this->openDatabaseConnection();
  }

  private function openDatabaseConnection()  {
	// set the (optional) options of the PDO connection. in this case, we set the fetch mode to
	// "objects", which means all results will be objects, like this: $result->user_name !
	// For example, fetch mode FETCH_ASSOC would return results like this: $result["user_name] !
//	$options = array(PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ, PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING);
	$options = array();

	// generate a database connection, using the PDO connector
	// @see http://net.tutsplus.com/tutorials/php/why-you-should-be-using-phps-pdo-for-database-access/
    try {
	  $this->dbh = new PDO($this->CFG['DB_TYPE'] . ':host=' . $this->CFG['DB_HOST'] . ';dbname=' . $this->CFG['DB_NAME'], $this->CFG['DB_USER'], $this->CFG['DB_PASS'], $options);
	}  catch (PDOException $e) {
	  echo 'Connection failed: ' . $e->getMessage();
	  exit;
	}

  }

}