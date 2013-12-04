<?php
require_once("MYSQLUndo.php");

/**
* setup example 1: lets enable logging for table "test_table"
*/
$class1 = new MYSQLUndo('localhost', 'test_db', 'root', 'password');
$result = $class1->enableLogging('test_table');
if (!$result){ echo $class1->getLastErrorMessage(); }

/**
* setup example 2: lets disable logging for table "test_table"
*/
$class1 = new MYSQLUndo('localhost', 'test_db', 'root', 'password');
$result = $class1->disableLogging('test_table');
if (!$result){ echo $class1->getLastErrorMessage(); }

/**
* usage example 1: lets revert back all records which affected in 1 hour before now
*/
$class1 = new MYSQLUndo('localhost', 'test_db', 'root', 'password');
$result = $class1->revertChanges('test_table', gmdate("Y-m-d H:i:s", time() - 60 * 60));
if (!$result){ echo $class1->getLastErrorMessage(); }

/**
* usage example 2: lets revert back all records which affected until 1 hour before now
*/
$class1 = new MYSQLUndo('localhost', 'test_db', 'root', 'password');
$result = $class1->revertChanges('test_table', null, gmdate("Y-m-d H:i:s", time() - 60 * 60));
if (!$result){ echo $class1->getLastErrorMessage(); }

/**
* usage example 3: lets revert back all records which affected in 3 hours before now until 1 hour before now
*/
$class1 = new MYSQLUndo('localhost', 'test_db', 'root', 'password');
$result = $class1->revertChanges('test_table', gmdate("Y-m-d H:i:s", time() - 3 * 60 * 60), gmdate("Y-m-d H:i:s", time() - 60 * 60));
if (!$result){ echo $class1->getLastErrorMessage(); }

/**
* usage example 4: lets revert back (actually delete in this example) only inserted records which affected in 1 hour before now
*/
$class1 = new MYSQLUndo('localhost', 'test_db', 'root', 'password');
$result = $class1->revertChanges('test_table', gmdate("Y-m-d H:i:s", time() - 3 * 60 * 60), null, true, false, false);
if (!$result){ echo $class1->getLastErrorMessage(); }

?>