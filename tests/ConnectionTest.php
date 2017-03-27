<?php

namespace React\Tests\MySQL;

use React\MySQL\Connection;

class ConnectionTest extends \PHPUnit_Framework_TestCase
{
    private $connectOptions = array(
        'dbname' => 'test',
        'user'   => 'travis',
        'passwd' => ''
    );

    public function testConnectWithInvalidPass()
    {
        $loop = \React\EventLoop\Factory::create();
        $conn = new Connection($loop, array('passwd' => 'invalidpass') + $this->connectOptions );

        $conn->connect()->then(
            function () use ($loop) {
                $loop->stop();
                $this->fail();
            },
            function ($err) {
                $this->assertEquals("Access denied for user 'test'@'localhost' (using password: YES)", $err->getMessage());
            }
        );

        $loop->run();
    }

    public function testConnectWithValidPass()
    {
        $this->expectOutputString('endclose');

        $loop = \React\EventLoop\Factory::create();
        $conn = new Connection($loop, $this->connectOptions );

        $conn->on('end', function ($conn){
            $this->assertInstanceOf('React\MySQL\Connection', $conn);
            echo 'end';
        });

        $conn->on('close', function ($conn){
            $this->assertInstanceOf('React\MySQL\Connection', $conn);
            echo 'close';
        });

        $conn->connect()->then(
            function ($conn) {
                $this->assertInstanceOf('React\MySQL\Connection', $conn);
            },
            function () use ($loop) {
                $loop->stop();
                $this->fail();
            }
        );

        $conn->ping()->then(
            function ($conn) use ($loop) {
                $conn->close(
                    function ($conn) {
                        $this->assertEquals($conn::STATE_CLOSED, $conn->getState());
                    },
                    function () use ($loop) {
                        $loop->stop();
                        $this->fail();
                    }
                );
            },
            function () use ($loop) {
                $loop->stop();
                $this->fail();
            }
        );

        $loop->run();
    }
}
