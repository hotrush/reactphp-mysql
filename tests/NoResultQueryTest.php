<?php

namespace React\Tests\MySQL;

use React\MySQL\Query;

class NoResultQueryTest extends  BaseTestCase
{
    public function testUpdateSimple()
    {
        $loop = \React\EventLoop\Factory::create();

        $connection = new \React\MySQL\Connection($loop, array(
            'dbname' => 'test',
            'user'   => 'test',
            'passwd' => 'test',
        ));

        $connection->connect();

        $connection->query('update book set created=999 where id=1')->then(function ($command) use ($loop) {
            $loop->stop();

            $this->assertEquals(false, $command->hasError());
            $this->assertEquals(1, $command->affectedRows);
        });

        $loop->run();
    }

    public function testInsertSimple()
    {
        $loop = \React\EventLoop\Factory::create();

        $connection = new \React\MySQL\Connection($loop, array(
            'dbname' => 'test',
            'user'   => 'test',
            'passwd' => 'test',
        ));

        $connection->connect();

        $connection->query("insert into book (`name`) values('foo')")->then(function ($command) use ($loop) {
            $loop->stop();

            $this->assertEquals(false, $command->hasError());
            $this->assertEquals(1, $command->affectedRows);
            $this->assertEquals(3, $command->insertId);
        });

        $loop->run();
    }
}
