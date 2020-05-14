<?php
include('lib/gratisdns.php');

$username = 'your-login';
$password = 'your-password';

$dns = new GratisDNS($username, $password);

//Returns array with domains
$domains = $dns->getDomains();

//Returns records for domain
$records = $dns->getRecords('test123.dk');

//Create domain
$dns->createDomain('test123.dk');

//Delete domain
$dns->deleteDomain('test123.dk');

//Create records for domain
$dns->createRecord('test123.dk', 'A', 'www.test123.dk', '127.0.0.1', 3600);
$dns->createRecord('test123.dk', 'AAAA', 'www.test123.dk', '::1', 3600);

//Get recordid+domainid - also available in getRecords
$record = $dns->getRecordByDomain('test123.dk', 'A', 'www.test123.dk');

//Update record
$dns->updateRecord('test123.dk', $record['recordid'], 'A', 'www.test123.dk', '127.0.0.2', 4800);

//Get recordid+domainid - also available in getRecords
$record = $dns->getRecordByDomain('test123.dk', 'AAAA', 'www.test123.dk');

//Delete record
$dns->deleteRecord('test123.dk', $record['recordid']);

//Get text response from GratisDNS. (Often empty)
echo $dns->getResponse();


