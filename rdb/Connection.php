<?php

namespace r;

use EventLoop\EventLoop;
use r\DatumConverter;
use r\Handshake;
use r\Queries\Dbs\Db;
use r\ProtocolBuffer\QueryQueryType;
use r\ProtocolBuffer\ResponseResponseType;
use r\Exceptions\RqlServerError;
use r\Exceptions\RqlDriverError;
use r\ProtocolBuffer\VersionDummyVersion;
use r\ProtocolBuffer\VersionDummyProtocol;
use Rx\Observable;
use Rx\Observer\AutoDetachObserver;
use Rx\Observer\CallbackObserver;
use Rx\ObserverInterface;
use Rx\Subject\Subject;
use Rxnet\Connector\Tcp;
use Rxnet\Connector\Tls;
use Rxnet\Event\ConnectorEvent;
use Rxnet\Event\Event;
use Rxnet\Stream\StreamEvent;
use Rxnet\Transport\BufferedStream;
use Rxnet\Transport\Stream;

class Connection extends DatumConverter
{
    /** @var  Stream */
    private $socket;
    private $host;
    private $port;
    private $defaultDb;
    private $user;
    private $password;
    private $activeTokens;
    private $timeout;
    private $ssl;
    private $loop;

    public $defaultDbName;

    public function __construct(
        $optsOrHost = null,
        $port = null,
        $db = null,
        $apiKey = null,
        $timeout = null,
        EventLoop $loop = null
    )
    {
        $this->loop = ($loop) ?: EventLoop::getLoop();
        if (is_array($optsOrHost)) {
            $opts = $optsOrHost;
            $host = null;
        } else {
            $opts = null;
            $host = $optsOrHost;
        }

        $ssl = null;
        $user = null;
        $password = null;

        if (isset($opts)) {
            if (isset($opts['host'])) {
                $host = $opts['host'];
            }
            if (isset($opts['port'])) {
                $port = $opts['port'];
            }
            if (isset($opts['db'])) {
                $db = $opts['db'];
            }
            if (isset($opts['apiKey'])) {
                $apiKey = $opts['apiKey'];
            }
            if (isset($opts['user'])) {
                $user = $opts['user'];
            }
            if (isset($opts['password'])) {
                $password = $opts['password'];
            }
            if (isset($opts['timeout'])) {
                $timeout = $opts['timeout'];
            }
            if (isset($opts['ssl'])) {
                $ssl = $opts['ssl'];
            }
        }

        if (isset($apiKey) && !is_string($apiKey)) {
            throw new RqlDriverError("The API key must be a string.");
        }
        if (isset($user) && !is_string($user)) {
            throw new RqlDriverError("The user name must be a string.");
        }
        if (isset($password) && !is_string($password)) {
            throw new RqlDriverError("The password must be a string.");
        }

        if (!isset($user)) {
            $user = "admin";
        }
        if (isset($apiKey) && isset($password)) {
            throw new RqlDriverError("Either user or apiKey can be specified, but not both.");
        }
        if (isset($apiKey) && !isset($password)) {
            $password = $apiKey;
        }
        if (!isset($password)) {
            $password = "";
        }
        if (!isset($host)) {
            $host = "localhost";
        }
        if (!isset($port)) {
            $port = 28015;
        }

        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->password = $password;
        $this->timeout = null;
        $this->ssl = $ssl;

        if (isset($db)) {
            $this->useDb($db);
        }
    }

    public function __destruct()
    {
        if ($this->isOpen()) {
            $this->close(false);
        }
    }

    public function close($noreplyWait = true)
    {
        if (!$this->isOpen()) {
            throw new RqlDriverError("Not connected.");
        }

        if ($noreplyWait) {
            $this->noreplyWait();
        }
        $this->socket->close();

    }

    public function reconnect($noreplyWait = true)
    {
        if ($this->isOpen()) {
            $this->close($noreplyWait);
        }
        $this->connect();
    }

    public function isOpen()
    {
        return isset($this->socket) && is_resource($this->socket->getSocket());
    }

    public function useDb($dbName)
    {
        if (!is_string($dbName)) {
            throw new RqlDriverError("Database must be a string.");
        }
        $this->defaultDbName = $dbName;
        $this->defaultDb = new Db($dbName);
    }


    public function noreplyWait()
    {
        if (!$this->isOpen()) {
            throw new RqlDriverError("Not connected.");
        }

        // Generate a token for the request
        $token = $this->generateToken();

        // Send the request
        $jsonQuery = array(QueryQueryType::PB_NOREPLY_WAIT);
        return $this->sendQuery($token, $jsonQuery)
            ->map(
                function ($response) {
                    if ($response['t'] != ResponseResponseType::PB_WAIT_COMPLETE) {
                        throw new RqlDriverError("Unexpected response type to noreplyWait query.");
                    }
                }
            );
    }

    public function server()
    {
        if (!$this->isOpen()) {
            throw new RqlDriverError("Not connected.");
        }

        // Generate a token for the request
        $token = $this->generateToken();

        // Send the request
        $jsonQuery = array(QueryQueryType::PB_SERVER_INFO);
        $this->sendQuery($token, $jsonQuery);

        // Await the response
        $response = $this->receiveResponse($token);

        if ($response['t'] != ResponseResponseType::PB_SERVER_INFO) {
            throw new RqlDriverError("Unexpected response type to server info query.");
        }

        $toNativeOptions = array();
        return $this->createDatumFromResponse($response)->toNative($toNativeOptions);
    }

    public function run(Query $query, $options = array(), &$profile = '')
    {
        if (isset($options) && !is_array($options)) {
            throw new RqlDriverError("Options must be an array.");
        }
        if (!$this->isOpen()) {
            throw new RqlDriverError("Not connected.");
        }

        // Grab PHP-RQL specific options
        $toNativeOptions = array();
        foreach (array('binaryFormat', 'timeFormat') as $opt) {
            if (isset($options) && isset($options[$opt])) {
                $toNativeOptions[$opt] = $options[$opt];
                unset($options[$opt]);
            }
        }

        // Generate a token for the request
        $token = $this->generateToken();

        // Send the request
        $globalOptargs = $this->convertOptions($options);
        if (isset($this->defaultDb) && !isset($options['db'])) {
            $globalOptargs['db'] = $this->defaultDb->encodeServerRequest();
        }

        $jsonQuery = array(
            QueryQueryType::PB_START,
            $query->encodeServerRequest(),
            (Object)$globalOptargs
        );

        return $this->sendQuery($token, $jsonQuery)
            ->map(function ($response) use ($token, $toNativeOptions, &$profile) {
                if (isset($options['noreply']) && $options['noreply'] === true) {
                    return null;
                }
                if ($response['t'] == ResponseResponseType::PB_SUCCESS_PARTIAL) {
                    $this->activeTokens[$token] = true;
                }

                if (isset($response['p'])) {
                    $profile = $this->decodedJSONToDatum($response['p'])->toNative($toNativeOptions);
                }

                if ($response['t'] == ResponseResponseType::PB_SUCCESS_ATOM) {
                    return $this->createDatumFromResponse($response)->toNative($toNativeOptions);
                }
                return $this->createCursorFromResponse($response, $token, $response['n'], $toNativeOptions);
            });
    }

    public function continueQuery($token)
    {
        if (!$this->isOpen()) {
            throw new RqlDriverError("Not connected.");
        }
        if (!is_numeric($token)) {
            throw new RqlDriverError("Token must be a number.");
        }

        // Send the request
        $jsonQuery = array(QueryQueryType::PB_CONTINUE);
        return $this->sendQuery($token, $jsonQuery)
            ->map(
                function ($response) use ($token) {
                    if ($response['t'] != ResponseResponseType::PB_SUCCESS_PARTIAL) {
                        unset($this->activeTokens[$token]);
                    }
                    return $response;
                }
            );
    }

    public function stopQuery($token)
    {
        if (!$this->isOpen()) {
            throw new RqlDriverError("Not connected.");
        }
        if (!is_numeric($token)) {
            throw new RqlDriverError("Token must be a number.");
        }

        // Send the request
        $jsonQuery = array(QueryQueryType::PB_STOP);
        return $this->sendQuery($token, $jsonQuery)
            ->map(
                function ($response) use ($token) {
                    if ($response['t'] != ResponseResponseType::PB_SUCCESS_PARTIAL) {
                        unset($this->activeTokens[$token]);
                    }
                    return $response;
                }
            );
    }

    private function generateToken()
    {
        $tries = 0;
        $maxToken = 1 << 30;
        do {
            $token = \rand(0, $maxToken);
            $haveCollision = isset($this->activeTokens[$token]);
        } while ($haveCollision && $tries++ < 1024);
        if ($haveCollision) {
            throw new RqlDriverError("Unable to generate a unique token for the query.");
        }
        return $token;
    }

    private function checkResponse($response, $responseToken, $token, $query = null)
    {
        if (!isset($response['t'])) {
            throw new RqlDriverError("Response message has no type.");
        }

        if ($response['t'] == ResponseResponseType::PB_CLIENT_ERROR) {
            throw new RqlDriverError("Server says PHP-RQL is buggy: " . $response['r'][0]);
        }

        if ($responseToken != $token) {
            throw new RqlDriverError(
                'Received wrong token. Response does not match the request. '
                . 'Expected ' . $token . ', received ' . $responseToken
            );
        }

        if ($response['t'] == ResponseResponseType::PB_COMPILE_ERROR) {
            $backtrace = null;
            if (isset($response['b'])) {
                $backtrace = Backtrace::decodeServerResponse($response['b']);
            }
            throw new RqlServerError("Compile error: " . $response['r'][0], $query, $backtrace);
        } elseif ($response['t'] == ResponseResponseType::PB_RUNTIME_ERROR) {
            $backtrace = null;
            if (isset($response['b'])) {
                $backtrace = Backtrace::decodeServerResponse($response['b']);
            }
            throw new RqlServerError("Runtime error: " . $response['r'][0], $query, $backtrace);
        }
    }

    private function createCursorFromResponse($response, $token, $notes, $toNativeOptions)
    {
        return new Cursor($this, $response, $token, $notes, $toNativeOptions);
    }

    private function createDatumFromResponse($response)
    {
        return $this->decodedJSONToDatum($response['r'][0]);
    }

    private function sendQuery($token, $json)
    {
        // PHP by default loses some precision when encoding floats, so we temporarily
        // bump up the `precision` option to avoid this.
        // The 17 assumes IEEE-754 double precision numbers.
        // Source: http://docs.oracle.com/cd/E19957-01/806-3568/ncg_goldberg.html
        //         "The same argument applied to double precision shows that 17 decimal
        //          digits are required to recover a double precision number."
        $previousPrecision = ini_set("precision", 17);
        $request = json_encode($json);
        if ($previousPrecision !== false) {
            ini_set("precision", $previousPrecision);
        }
        if ($request === false) {
            throw new RqlDriverError("Failed to encode query as JSON: " . json_last_error());
        }

        // The 8-byte unique query token
        $binaryToken = pack("V", $token) . pack("V", 0);
        // The size of the JSON-serialized, UTF8-encoded query, as a 4-byte little-endian integer
        $requestSize = pack("V", strlen($request));
        // Total query
        $query = $binaryToken . $requestSize . $request;

        // Listen socket
        $subject = new Subject();
        // Dispose when everything received
        $dispose = $this->socket
            ->subscribe($subject);


        // Read headers
        $responseHeader = null;
        $responseSize = null;
        $responseToken = null;
        $subject
            ->takeWhile(
                function () use (&$responseSize) {
                    return !$responseSize;
                }
            )
            ->subscribeCallback(
                function (StreamEvent $event) use (&$responseHeader, &$responseToken, &$responseSize) {
                    $responseHeader .= $event->getData();
                    echo "header > $responseHeader ";
                    if (strlen($responseHeader) >= 4 + 8) {
                        $responseHeader = unpack("Vtoken/Vtoken2/Vsize", $responseHeader);
                        $responseToken = $responseHeader['token'];
                        if ($responseHeader['token2'] != 0) {
                            throw new RqlDriverError("Invalid response from server: Invalid token.");
                        }
                        echo "header {$responseSize} > ";
                        $responseSize = $responseHeader['size'];
                    }
                }
            );
        // Write to socket
        echo "write {$query} > ";
        $this->socket->write($query);

        // Read response
        $responseBuf = '';
        $response = '';
        $subject
            ->skipWhile(
                function () use (&$responseSize) {
                    return $responseSize;
                }
            )
            ->subscribeCallback(
                function (StreamEvent $event) use (&$query, $token, &$response, $responseBuf, &$responseToken, &$responseSize) {
                    $responseBuf .= $event->getData();
                    if (strlen($responseBuf) >= $responseSize) {
                        echo "response > $responseBuf ";
                        $responseBuf = json_decode($responseBuf);
                        var_dump($responseBuf);

                        if (json_last_error() != JSON_ERROR_NONE) {
                            throw new RqlDriverError("Unable to decode JSON response (error code " . json_last_error() . ")");
                        }
                        if (!is_object($responseBuf)) {
                            throw new RqlDriverError("Invalid response from server: Not an object.");
                        }
                        $this->checkResponse((array)$responseBuf, $responseToken, $token, $query);

                        $response = (array)$responseBuf;
                    }
                }
            );

        // Wait complete response
        return $subject
            ->skipWhile(
                function () use (&$response) {
                    return $response;
                }
            )
            ->map(
                function () use (&$response, $dispose) {
                    // Unplug from socket
                    $dispose->dispose();
                    return $response;
                }
            )
            // nothing more complete
            ->take(1);
    }


    public function connect()
    {

        if ($this->isOpen()) {
            throw new RqlDriverError("Already connected");
        }

        if ($this->ssl) {
            if (is_array($this->ssl)) {
                $context = stream_context_create(array("ssl" => $this->ssl));
            } else {
                $context = null;
            }
            $connector = (new Tls($this->loop))
                ->setContext($context)
                ->connect($this->host, $this->port);

        } else {
            $connector = (new Tcp($this->loop))
                ->connect($this->host, $this->port);
        }
        return $connector
            ->catchError(
                function (\Exception $e) {
                    throw new RqlDriverError("Unable to connect: " . $e->getMessage());
                }
            )
            ->flatMap(
                function (ConnectorEvent $connectorEvent) {
                    echo "wired > ";
                    $handshakeResponse = null;
                    $handshake = new Handshake($this->user, $this->password);
                    try {
                        $msg = $handshake->nextMessage($handshakeResponse);
                    } catch (\Exception $e) {
                        $this->close(false);
                        throw $e;
                    }
                    $subject = new Subject();
                    $socket = $connectorEvent->getStream();

                    $disposable = $socket->subscribe($subject);

                    // Receive 2 json
                    //  - server config
                    //  - authentication response
                    // we are connected
                    $subject
                        ->take(2)
                        ->subscribeCallback(
                            null,
                            null,
                            function () use ($disposable) {
                                $disposable->dispose();

                            }
                        );
                    echo "handshake > ";
                    $socket->write($msg);
                    return $subject
                        ->skip(1)
                        ->map(
                            function () use ($socket) {
                                echo "identified > ";
                                $connection = clone $this;
                                $connection->socket = $socket;
                                return $connection;
                            }
                        );
                }
            );
    }

    private function convertOptions($options)
    {
        $opts = array();

        foreach ((array)$options as $key => $value) {
            $opts[$key] = $this->nativeToDatum($value)->encodeServerRequest();
        }
        return $opts;
    }
}
