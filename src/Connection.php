<?php

namespace React\MySQL;

use React\EventLoop\LoopInterface;
use React\MySQL\Connector;
use React\MySQL\Commands\AuthenticateCommand;
use React\MySQL\Commands\PingCommand;
use React\MySQL\Commands\QueryCommand;
use React\MySQL\Commands\QuitCommand;
use React\SocketClient\ConnectionException;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

class Connection extends EventEmitter
{
    const STATE_INIT                = 0;
    const STATE_CONNECT_FAILED      = 1;
    const STATE_AUTHENTICATE_FAILED = 2;
    const STATE_CONNECTING          = 3;
    const STATE_CONNECTED           = 4;
    const STATE_AUTHENTICATED       = 5;
    const STATE_CLOSEING            = 6;
    const STATE_CLOSED              = 7;

    private $loop;

    private $connector;

    private $options = [
        'host'   => '127.0.0.1',
        'port'   => 3306,
        'user'   => 'root',
        'passwd' => '',
        'dbname' => '',
    ];

    private $serverOptions;

    private $executor;

    private $state = self::STATE_INIT;

    private $stream;

    private $buffer;
    /**
     * @var Protocal\Parser
     */
    public $parser;

    public function __construct(LoopInterface $loop, array $connectOptions = array())
    {
        $this->loop       = $loop;
        $resolver         = (new \React\Dns\Resolver\Factory())->createCached('8.8.8.8', $loop);
        $this->connector  = new Connector($loop, $resolver);;
        $this->executor   = new Executor($this);
        $this->options    = $connectOptions + $this->options;
    }

    /**
     * Do a async query.
     *
     * @param string $sql
     * @param mixed  $args...
     * @return PromiseInterface Promise <QueryCommand, Exception>
     */
    public function query($sql)
    {
        $args = func_get_args();
        array_shift($args);

        $query = new Query($sql);

        $command = new QueryCommand($this);
        $command->setQuery($query);

        $query->bindParamsFromArray($args);
        $this->_doCommand($command);

        $deferred = new Deferred();

        $command->on('results', function ($rows, $command) use ($deferred) {
            $deferred->resolve($command);
        });
        $command->on('error', function ($err, $command) use ($deferred) {
            $deferred->reject($err);
        });
        $command->on('success', function ($command) use ($deferred) {
            $deferred->resolve($command);
        });

        return $deferred->promise();
    }

    /**
     * Sends a ping to the mysql server.
     *
     * @return PromiseInterface Promise<Connection, Exception>
     */
    public function ping()
    {
        $deferred = new Deferred();

        $this->_doCommand(new PingCommand($this))
            ->on('error', function ($reason) use ($deferred) {
                $deferred->reject($reason);
            })
            ->on('success', function () use ($deferred) {
                $deferred->resolve($this);
            });

        return $deferred->promise();
    }

    public function selectDb($dbname)
    {
        return $this->query(sprintf('USE `%s`', $dbname));
    }

    public function listFields()
    {
    }

    public function setOption($name, $value)
    {
        $this->options[$name] = $value;

        return $this;
    }

    public function getOption($name, $default = null)
    {
        if (isset($this->options[$name])) {
            return $this->options[$name];
        }

        return $default;
    }

    public function getState()
    {
        return $this->state;
    }

    /**
     * Close the connection.
     *
     * @return PromiseInterface Promise<Connection>
     */
    public function close()
    {
        $deferred = new Deferred();

        $this->_doCommand(new QuitCommand($this))
            ->on('success', function () use ($deferred) {
                $this->state = self::STATE_CLOSED;
                $this->emit('end', [$this]);
                $this->emit('close', [$this]);

                $deferred->resolve($this);
            });
        $this->state = self::STATE_CLOSEING;

        return $deferred->promise();
    }

    /**
     * Connnect to mysql server.
     *
     * @return PromiseInterface Promise<Connection, Exception>
     */
    public function connect()
    {
        $this->state = self::STATE_CONNECTING;
        $options     = $this->options;
        $streamRef   = $this->stream;

        $deferred = new Deferred();

        $errorHandler = function ($reason) use ($deferred) {
            $this->state = self::STATE_AUTHENTICATE_FAILED;
            $deferred->reject($reason);
        };
        $connectedHandler = function ($serverOptions) use ($deferred) {
            $this->state = self::STATE_AUTHENTICATED;
            $this->serverOptions = $serverOptions;
            $deferred->resolve($this);
        };

        $this->connector
            ->create($this->options['host'], $this->options['port'])
            ->then(function ($stream) use (&$streamRef, $options, $errorHandler, $connectedHandler) {
                $streamRef = $stream;

                $stream->on('error', [$this, 'handleConnectionError']);
                $stream->on('close', [$this, 'handleConnectionClosed']);

                $parser = $this->parser = new Protocal\Parser($stream, $this->executor);

                $parser->setOptions($options);

                $command = $this->_doCommand(new AuthenticateCommand($this));
                $command->on('authenticated', $connectedHandler);
                $command->on('error', $errorHandler);

                //$parser->on('close', $closeHandler);
                $parser->start();

            }, [$this, 'handleConnectionError']);

        return $deferred->promise();
    }

    public function handleConnectionError($err)
    {
        $this->emit('error', [$err, $this]);
    }

    public function handleConnectionClosed()
    {
        if ($this->state < self::STATE_CLOSEING) {
            $this->state = self::STATE_CLOSED;
            $this->emit('error', [new ConnectionException('mysql server has gone away'), $this]);
        }
    }

    protected function _doCommand(Command $command)
    {
        if ($command->equals(Command::INIT_AUTHENTICATE)) {
            return $this->executor->undequeue($command);
        } elseif ($this->state >= self::STATE_CONNECTING && $this->state <= self::STATE_AUTHENTICATED) {
            return $this->executor->enqueue($command);
        } else {
            throw new Exception("Cann't send command");
        }
    }

    public function getServerOptions()
    {
        return $this->serverOptions;
    }
}
