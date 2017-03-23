<?php

require __DIR__ . '/init.php';

//create the main loop
$loop = React\EventLoop\Factory::create();

//create a mysql connection for executing queries
$connection = new React\MySQL\Connection($loop, array(
    'dbname' => 'test',
    'user'   => 'test',
    'passwd' => 'test',
));

//connecting to mysql server, not required.

$connection->connect();

$connection->query('select * from book')->then(
    function (React\MySQL\Commands\QueryCommand $command) use ($loop) {
        $results = $command->resultRows; //get the results
        $fields  = $command->resultFields; // get table fields

        var_dump($results);

        $loop->stop(); //stop the main loop.
    },
    function (Exception $error) use ($loop) {
        //error

        var_dump($error->getMessage());

        $loop->stop();
    }
);

$loop->run();
