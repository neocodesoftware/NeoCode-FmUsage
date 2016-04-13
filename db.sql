/* Create and configure user
 create database fmusage;
 create user 'fmusage'@'localhost' identified by 'MyPASS4FmUsage';
 use mysql;
 insert into db Set Host="localhost", Db='fmusage', User='fmusage', Select_priv='Y', Insert_priv='Y', Update_priv='Y',Delete_priv='Y';
 flush privileges;
 use fmusage;
 */

DROP TABLE IF EXISTS `FmAccessLog`;
CREATE TABLE `FmAccessLog` (
  `Id` int(11) NOT NULL AUTO_INCREMENT,
  `LogDate` date NOT NULL,
  `LogTime` time NOT NULL,
  `LogSec` int(11) NOT NULL,
  `LogCode` int(11) NOT NULL,
  `ServerName` varchar(255) NOT NULL default '',
  `FmClient` varchar(255) NOT NULL default '',
  `FmClientIP` varchar(255) NOT NULL default '',
  `DbName` varchar(255) NOT NULL default '',
  `FmLoginName` varchar(255) NOT NULL default '',
  `FmApp` varchar(255) NOT NULL default '',
  `OwnerName` varchar(255) NOT NULL default '',
  `Comments` text,
  `SessionId` int(11) NOT NULL default 0,
  PRIMARY KEY (`Id`),
  INDEX LogDate(LogDate),
  INDEX LogCode(LogCode),
  INDEX SessionId(SessionId)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

DROP TABLE IF EXISTS `FmClientSession`;
CREATE TABLE `FmClientSession` (
  `SessionId` int(11) NOT NULL AUTO_INCREMENT,
  `SessionType`  varchar(255) NOT NULL default '',
  `StartDate` datetime NOT NULL,
  `EndDate` datetime NOT NULL,
  `SessionTime` int(11) NOT NULL default 0,
  `ServerName` varchar(255) NOT NULL default '',
  `FmClient` varchar(255) NOT NULL default '',
  `FmClientIP` varchar(255) NOT NULL default '',
  `FmLoginName` varchar(255) NOT NULL default '',
  `FmApp` varchar(255) NOT NULL default '',
  `FmAppType` varchar(255) NOT NULL default '',
  `ConnectionType` varchar(255) NOT NULL default '',
  `OwnerName` varchar(255) NOT NULL default '',
  `Processed`  int(4) NOT NULL default 0,
  PRIMARY KEY (`SessionId`),
  INDEX SessionType(SessionType),
  INDEX StartDate(StartDate),
  INDEX OwnerName(OwnerName),
  INDEX Processed(Processed)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

DROP TABLE IF EXISTS `FmClientStats`;
CREATE TABLE `FmClientStats` (
  `Id` int(11) NOT NULL AUTO_INCREMENT,
  `StartDate` datetime NOT NULL,
  `EndDate` datetime NOT NULL,
  `MaxClients` int(11) NOT NULL default 0,

  PRIMARY KEY (`Id`),
  INDEX StartDate(StartDate)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

