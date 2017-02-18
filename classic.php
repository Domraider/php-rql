<?php

// Load the driver
require_once("rdb/rdb.php");

// Connect to localhost
$conn = r\connect('localhost', 28015);

// Create a test table
r\db("test")->tableCreate("tablePhpTest")->run($conn);

// Insert a document
$document = array('someKey' => 'someValue');
$result = r\table("tablePhpTest")->insert($document)
    ->run($conn);
echo "Insert: $result\n";

// How many documents are in the table?
$result = r\table("tablePhpTest")->count()->run($conn);
echo "Count: $result\n";

// List the someKey values of the documents in the table
// (using a mapping-function)
$result = r\table("tablePhpTest")->map(function ($x) {
    return $x('someKey');
})->run($conn);

foreach ($result as $doc) {
    print_r($doc);
}

// Delete the test table
r\db("test")->tableDrop("tablePhpTest")->run($conn);
