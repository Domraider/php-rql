<?php
require_once('vendor/autoload.php');
/**
 * Created by PhpStorm.
 * User: vince
 * Date: 16/02/2017
 * Time: 20:33
 */
$loop = new \React\EventLoop\StreamSelectLoop();
\EventLoop\EventLoop::setLoop($loop);
// Connect to localhost
$conn = \Rxnet\await(r\connect('127.0.0.1', 28015));
echo " ! Connected !  ";
// Insert a document
$document = array('someKey' => 'someValue');


r\db("test")
    // Create a test table
    ->tableCreate("tablePhpTest")
    ->run($conn)
    ->subscribeCallback(
        function () {
            echo "Table created\n";
        }
    );
$loop->run();
/*

    ->doOnNext(
        function ($result) {
            echo "Insert: $result\n";
        }
    )
    ->concat(r\table("tablePhpTest")
        ->count()
        ->run($conn)
    )
    ->doOnNext(
        function ($result) {
            echo "Count: $result\n";
        }
    )
    ->concat(r\table("tablePhpTest")
        ->map(
            function ($x) {
                return $x('someKey');
            }
        )->run($conn))
    ->doOnNext(
        function ($result) {
            // List the someKey values of the documents in the table
            // (using a mapping-function)
            foreach ($result as $doc) {
                print_r($doc);
            }
        })
    ->concat(r\db("test")
        ->tableDrop("tablePhpTest")
        ->run($conn)
    )->subscribeCallback(function () {
        echo "Everything is clean";
    });
*/