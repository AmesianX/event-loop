<?php

namespace React\Tests\EventLoop;

use React\EventLoop\StreamSelectLoop;
use React\EventLoop\ExtUvLoop;

abstract class AbstractLoopTest extends TestCase
{
    /**
     * @var \React\EventLoop\LoopInterface
     */
    protected $loop;

    /** @var float */
    private $tickTimeout;

    /** @var ?string */
    private $received;

    const PHP_DEFAULT_CHUNK_SIZE = 8192;

    /**
     * @before
     */
    public function setUpLoop()
    {
        // It's a timeout, don't set it too low. Travis and other CI systems are slow.
        $this->tickTimeout = 0.02;
        $this->loop = $this->createLoop();
    }

    abstract public function createLoop();

    public function createSocketPair()
    {
        $domain = (DIRECTORY_SEPARATOR === '\\') ? STREAM_PF_INET : STREAM_PF_UNIX;
        $sockets = stream_socket_pair($domain, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        foreach ($sockets as $socket) {
            if (function_exists('stream_set_read_buffer')) {
                stream_set_read_buffer($socket, 0);
            }
        }

        return $sockets;
    }

    public function testAddReadStreamTriggersWhenSocketReceivesData()
    {
        list ($input, $output) = $this->createSocketPair();

        $timeout = $this->loop->addTimer(0.1, function () use ($input) {
            $this->loop->removeReadStream($input);
        });

        $called = 0;
        $this->loop->addReadStream($input, function () use (&$called, $input, $timeout) {
            ++$called;
            $this->loop->removeReadStream($input);
            $this->loop->cancelTimer($timeout);
        });

        fwrite($output, "foo\n");

        $this->loop->run();

        $this->assertEquals(1, $called);
    }

    public function testAddReadStreamTriggersWhenSocketCloses()
    {
        list ($input, $output) = $this->createSocketPair();

        $timeout = $this->loop->addTimer(0.1, function () use ($input) {
            $this->loop->removeReadStream($input);
        });

        $called = 0;
        $this->loop->addReadStream($input, function () use (&$called, $input, $timeout) {
            ++$called;
            $this->loop->removeReadStream($input);
            $this->loop->cancelTimer($timeout);
        });

        fclose($output);

        $this->loop->run();

        $this->assertEquals(1, $called);
    }

    public function testAddWriteStreamTriggersWhenSocketConnectionSucceeds()
    {
        $server = stream_socket_server('127.0.0.1:0');

        $errno = $errstr = null;
        $connecting = stream_socket_client(stream_socket_get_name($server, false), $errno, $errstr, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);

        $timeout = $this->loop->addTimer(0.1, function () use ($connecting) {
            $this->loop->removeWriteStream($connecting);
        });

        $called = 0;
        $this->loop->addWriteStream($connecting, function () use (&$called, $connecting, $timeout) {
            ++$called;
            $this->loop->removeWriteStream($connecting);
            $this->loop->cancelTimer($timeout);
        });

        $this->loop->run();

        $this->assertEquals(1, $called);
    }

    public function testAddWriteStreamTriggersWhenSocketConnectionRefused()
    {
        // first verify the operating system actually refuses the connection and no firewall is in place
        // use higher timeout because Windows retires multiple times and has a noticeable delay
        // @link https://stackoverflow.com/questions/19440364/why-do-failed-attempts-of-socket-connect-take-1-sec-on-windows
        $errno = $errstr = null;
        if (@stream_socket_client('127.0.0.1:1', $errno, $errstr, 10.0) !== false || (defined('SOCKET_ECONNREFUSED') && $errno !== SOCKET_ECONNREFUSED)) {
            $this->markTestSkipped('Expected host to refuse connection, but got error ' . $errno . ': ' . $errstr);
        }

        $connecting = stream_socket_client('127.0.0.1:1', $errno, $errstr, 0, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);

        $timeout = $this->loop->addTimer(10.0, function () use ($connecting) {
            $this->loop->removeWriteStream($connecting);
        });

        $called = 0;
        $this->loop->addWriteStream($connecting, function () use (&$called, $connecting, $timeout) {
            ++$called;
            $this->loop->removeWriteStream($connecting);
            $this->loop->cancelTimer($timeout);
        });

        $this->loop->run();

        $this->assertEquals(1, $called);
    }

    public function testAddReadStream()
    {
        if ($this->loop instanceof ExtUvLoop && DIRECTORY_SEPARATOR === '\\') {
            $this->markTestIncomplete('Ticking ExtUvLoop not supported on Windows');
        }

        list ($input, $output) = $this->createSocketPair();

        $this->loop->addReadStream($input, $this->expectCallableExactly(2));

        fwrite($output, "foo\n");
        $this->tickLoop($this->loop);

        fwrite($output, "bar\n");
        $this->tickLoop($this->loop);
    }

    public function testAddReadStreamIgnoresSecondCallable()
    {
        if ($this->loop instanceof ExtUvLoop && DIRECTORY_SEPARATOR === '\\') {
            $this->markTestIncomplete('Ticking ExtUvLoop not supported on Windows');
        }

        list ($input, $output) = $this->createSocketPair();

        $this->loop->addReadStream($input, $this->expectCallableExactly(2));
        $this->loop->addReadStream($input, $this->expectCallableNever());

        fwrite($output, "foo\n");
        $this->tickLoop($this->loop);

        fwrite($output, "bar\n");
        $this->tickLoop($this->loop);
    }

    public function testAddReadStreamReceivesDataFromStreamReference()
    {
        $this->received = '';
        $this->subAddReadStreamReceivesDataFromStreamReference();
        $this->assertEquals('', $this->received);

        $this->assertRunFasterThan($this->tickTimeout * 2);
        $this->assertEquals('[hello]X', $this->received);
    }

    /**
     * Helper for above test. This happens in another helper method to verify
     * the loop keeps track of assigned stream resources (refcount).
     */
    private function subAddReadStreamReceivesDataFromStreamReference()
    {
        list ($input, $output) = $this->createSocketPair();

        fwrite($input, 'hello');
        fclose($input);

        $this->loop->addReadStream($output, function ($output) {
            $chunk = fread($output, 1024);
            if ($chunk === '') {
                $this->received .= 'X';
                $this->loop->removeReadStream($output);
                fclose($output);
            } else {
                $this->received .= '[' . $chunk . ']';
            }
        });
    }

    public function testAddWriteStream()
    {
        if ($this->loop instanceof ExtUvLoop && DIRECTORY_SEPARATOR === '\\') {
            $this->markTestIncomplete('Ticking ExtUvLoop not supported on Windows');
        }

        list ($input) = $this->createSocketPair();

        $this->loop->addWriteStream($input, $this->expectCallableExactly(2));
        $this->tickLoop($this->loop);
        $this->tickLoop($this->loop);
    }

    public function testAddWriteStreamIgnoresSecondCallable()
    {
        if ($this->loop instanceof ExtUvLoop && DIRECTORY_SEPARATOR === '\\') {
            $this->markTestIncomplete('Ticking ExtUvLoop not supported on Windows');
        }

        list ($input) = $this->createSocketPair();

        $this->loop->addWriteStream($input, $this->expectCallableExactly(2));
        $this->loop->addWriteStream($input, $this->expectCallableNever());
        $this->tickLoop($this->loop);
        $this->tickLoop($this->loop);
    }

    public function testRemoveReadStreamInstantly()
    {
        if ($this->loop instanceof ExtUvLoop && DIRECTORY_SEPARATOR === '\\') {
            $this->markTestIncomplete('Ticking ExtUvLoop not supported on Windows');
        }

        list ($input, $output) = $this->createSocketPair();

        $this->loop->addReadStream($input, $this->expectCallableNever());
        $this->loop->removeReadStream($input);

        fwrite($output, "bar\n");
        $this->tickLoop($this->loop);
    }

    public function testRemoveReadStreamAfterReading()
    {
        if ($this->loop instanceof ExtUvLoop && DIRECTORY_SEPARATOR === '\\') {
            $this->markTestIncomplete('Ticking ExtUvLoop not supported on Windows');
        }

        list ($input, $output) = $this->createSocketPair();

        $this->loop->addReadStream($input, $this->expectCallableOnce());

        fwrite($output, "foo\n");
        $this->tickLoop($this->loop);

        $this->loop->removeReadStream($input);

        fwrite($output, "bar\n");
        $this->tickLoop($this->loop);
    }

    public function testRemoveWriteStreamInstantly()
    {
        if ($this->loop instanceof ExtUvLoop && DIRECTORY_SEPARATOR === '\\') {
            $this->markTestIncomplete('Ticking ExtUvLoop not supported on Windows');
        }

        list ($input) = $this->createSocketPair();

        $this->loop->addWriteStream($input, $this->expectCallableNever());
        $this->loop->removeWriteStream($input);
        $this->tickLoop($this->loop);
    }

    public function testRemoveWriteStreamAfterWriting()
    {
        if ($this->loop instanceof ExtUvLoop && DIRECTORY_SEPARATOR === '\\') {
            $this->markTestIncomplete('Ticking ExtUvLoop not supported on Windows');
        }

        list ($input) = $this->createSocketPair();

        $this->loop->addWriteStream($input, $this->expectCallableOnce());
        $this->tickLoop($this->loop);

        $this->loop->removeWriteStream($input);
        $this->tickLoop($this->loop);
    }

    public function testRemoveStreamForReadOnly()
    {
        if ($this->loop instanceof ExtUvLoop && DIRECTORY_SEPARATOR === '\\') {
            $this->markTestIncomplete('Ticking ExtUvLoop not supported on Windows');
        }

        list ($input, $output) = $this->createSocketPair();

        $this->loop->addReadStream($input, $this->expectCallableNever());
        $this->loop->addWriteStream($output, $this->expectCallableOnce());
        $this->loop->removeReadStream($input);

        fwrite($output, "foo\n");
        $this->tickLoop($this->loop);
    }

    public function testRemoveStreamForWriteOnly()
    {
        if ($this->loop instanceof ExtUvLoop && DIRECTORY_SEPARATOR === '\\') {
            $this->markTestIncomplete('Ticking ExtUvLoop not supported on Windows');
        }

        list ($input, $output) = $this->createSocketPair();

        fwrite($output, "foo\n");

        $this->loop->addReadStream($input, $this->expectCallableOnce());
        $this->loop->addWriteStream($output, $this->expectCallableNever());
        $this->loop->removeWriteStream($output);

        $this->tickLoop($this->loop);
    }

    public function testRemoveReadAndWriteStreamFromLoopOnceResourceClosesEndsLoop()
    {
        list($stream, $other) = $this->createSocketPair();
        stream_set_blocking($stream, false);
        stream_set_blocking($other, false);

        // dummy writable handler
        $this->loop->addWriteStream($stream, function () { });

        // remove stream when the stream is readable (closes)
        $this->loop->addReadStream($stream, function ($stream) {
            $this->loop->removeReadStream($stream);
            $this->loop->removeWriteStream($stream);
            fclose($stream);
        });

        // close other side
        fclose($other);

        $this->assertRunFasterThan($this->tickTimeout);
    }

    public function testRemoveReadAndWriteStreamFromLoopOnceResourceClosesOnEndOfFileEndsLoop()
    {
        list($stream, $other) = $this->createSocketPair();
        stream_set_blocking($stream, false);
        stream_set_blocking($other, false);

        // dummy writable handler
        $this->loop->addWriteStream($stream, function () { });

        // remove stream when the stream is readable (closes)
        $this->loop->addReadStream($stream, function ($stream) {
            $data = fread($stream, 1024);
            if ($data !== '') {
                return;
            }

            $this->loop->removeReadStream($stream);
            $this->loop->removeWriteStream($stream);
            fclose($stream);
        });

        // send data and close stream
        fwrite($other, str_repeat('.', static::PHP_DEFAULT_CHUNK_SIZE));
        $this->loop->addTimer(0.01, function () use ($other) {
            fclose($other);
        });

        $this->assertRunFasterThan(0.1);
    }

    public function testRemoveReadAndWriteStreamFromLoopWithClosingResourceEndsLoop()
    {
        // get only one part of the pair to ensure the other side will close immediately
        list($stream) = $this->createSocketPair();
        stream_set_blocking($stream, false);

        // dummy writable handler
        $this->loop->addWriteStream($stream, function () { });

        // remove stream when the stream is readable (closes)
        $this->loop->addReadStream($stream, function ($stream) {
            $this->loop->removeReadStream($stream);
            $this->loop->removeWriteStream($stream);
            fclose($stream);
        });

        $this->assertRunFasterThan($this->tickTimeout);
    }

    public function testRemoveInvalid()
    {
        list ($stream) = $this->createSocketPair();

        // remove a valid stream from the event loop that was never added in the first place
        $this->loop->removeReadStream($stream);
        $this->loop->removeWriteStream($stream);

        $this->assertTrue(true);
    }

    /** @test */
    public function emptyRunShouldSimplyReturn()
    {
        $this->assertRunFasterThan($this->tickTimeout);
    }

    /** @test */
    public function runShouldReturnWhenNoMoreFds()
    {
        list ($input, $output) = $this->createSocketPair();

        $this->loop->addReadStream($input, function ($stream) {
            $this->loop->removeReadStream($stream);
        });

        fwrite($output, "foo\n");

        $this->assertRunFasterThan($this->tickTimeout * 2);
    }

    /** @test */
    public function stopShouldStopRunningLoop()
    {
        list ($input, $output) = $this->createSocketPair();

        $this->loop->addReadStream($input, function ($stream) {
            $this->loop->stop();
        });

        fwrite($output, "foo\n");

        $this->assertRunFasterThan($this->tickTimeout * 2);
    }

    public function testStopShouldPreventRunFromBlocking()
    {
        $this->loop->addTimer(
            1,
            function () {
                $this->fail('Timer was executed.');
            }
        );

        $this->loop->futureTick(
            function () {
                $this->loop->stop();
            }
        );

        $this->assertRunFasterThan($this->tickTimeout * 2);
    }

    public function testIgnoreRemovedCallback()
    {
        // two independent streams, both should be readable right away
        list ($input1, $output1) = $this->createSocketPair();
        list ($input2, $output2) = $this->createSocketPair();

        $called = false;
        $this->loop->addReadStream($input1, function ($stream) use (&$called, $input2) {
            // stream1 is readable, remove stream2 as well => this will invalidate its callback
            $this->loop->removeReadStream($stream);
            $this->loop->removeReadStream($input2);

            $called = true;
        });

        // this callback would have to be called as well, but the first stream already removed us
        $this->loop->addReadStream($input2, function () use (&$called) {
            if ($called) {
                $this->fail('Callback 2 must not be called after callback 1 was called');
            }
        });

        fwrite($output1, "foo\n");
        fwrite($output2, "foo\n");

        $this->loop->run();

        $this->assertTrue($called);
    }

    public function testFutureTickEventGeneratedByFutureTick()
    {
        $this->loop->futureTick(
            function () {
                $this->loop->futureTick(
                    function () {
                        echo 'future-tick' . PHP_EOL;
                    }
                );
            }
        );

        $this->expectOutputString('future-tick' . PHP_EOL);

        $this->loop->run();
    }

    public function testFutureTick()
    {
        $called = false;

        $callback = function () use (&$called) {
            $called = true;
        };

        $this->loop->futureTick($callback);

        $this->assertFalse($called);

        $this->tickLoop($this->loop);

        $this->assertTrue($called);
    }

    public function testFutureTickFiresBeforeIO()
    {
        if ($this->loop instanceof ExtUvLoop && DIRECTORY_SEPARATOR === '\\') {
            $this->markTestIncomplete('Ticking ExtUvLoop not supported on Windows');
        }

        list ($stream) = $this->createSocketPair();

        $this->loop->addWriteStream(
            $stream,
            function () {
                echo 'stream' . PHP_EOL;
            }
        );

        $this->loop->futureTick(
            function () {
                echo 'future-tick' . PHP_EOL;
            }
        );

        $this->expectOutputString('future-tick' . PHP_EOL . 'stream' . PHP_EOL);

        $this->tickLoop($this->loop);
    }

    /**
     * @depends testFutureTickFiresBeforeIO
     */
    public function testRecursiveFutureTick()
    {
        list ($stream) = $this->createSocketPair();

        $this->loop->addWriteStream(
            $stream,
            function () use ($stream) {
                echo 'stream' . PHP_EOL;
                $this->loop->removeWriteStream($stream);
            }
        );

        $this->loop->futureTick(
            function () {
                echo 'future-tick-1' . PHP_EOL;
                $this->loop->futureTick(
                    function () {
                        echo 'future-tick-2' . PHP_EOL;
                    }
                );
            }
        );

        $this->expectOutputString('future-tick-1' . PHP_EOL . 'stream' . PHP_EOL . 'future-tick-2' . PHP_EOL);

        $this->loop->run();
    }

    public function testRunWaitsForFutureTickEvents()
    {
        list ($stream) = $this->createSocketPair();

        $this->loop->addWriteStream(
            $stream,
            function () use ($stream) {
                $this->loop->removeWriteStream($stream);
                $this->loop->futureTick(
                    function () {
                        echo 'future-tick' . PHP_EOL;
                    }
                );
            }
        );

        $this->expectOutputString('future-tick' . PHP_EOL);

        $this->loop->run();
    }

    public function testFutureTickEventGeneratedByTimer()
    {
        $this->loop->addTimer(
            0.001,
            function () {
                $this->loop->futureTick(
                    function () {
                        echo 'future-tick' . PHP_EOL;
                    }
                );
            }
        );

        $this->expectOutputString('future-tick' . PHP_EOL);

        $this->loop->run();
    }

    public function testRemoveSignalNotRegisteredIsNoOp()
    {
        $this->loop->removeSignal(2, function () { });
        $this->assertTrue(true);
    }

    /**
     * @requires extension pcntl
     * @requires function posix_kill()
     * @requires function posix_getpid()
     */
    public function testSignal()
    {
        if ($this->loop instanceof StreamSelectLoop && !(\function_exists('pcntl_signal') && \function_exists('pcntl_signal_dispatch'))) {
            $this->markTestSkipped('Signal handling with StreamSelectLoop requires pcntl_signal() and pcntl_signal_dispatch(), see also disable_functions');
        }

        $called = false;
        $calledShouldNot = true;

        $timer = $this->loop->addPeriodicTimer(1, function () {});

        $this->loop->addSignal(SIGUSR2, $func2 = function () use (&$calledShouldNot) {
            $calledShouldNot = false;
        });

        $this->loop->addSignal(SIGUSR1, $func1 = function () use (&$func1, &$func2, &$called, $timer) {
            $called = true;
            $this->loop->removeSignal(SIGUSR1, $func1);
            $this->loop->removeSignal(SIGUSR2, $func2);
            $this->loop->cancelTimer($timer);
        });

        $this->loop->futureTick(function () {
            posix_kill(posix_getpid(), SIGUSR1);
        });

        $this->loop->run();

        $this->assertTrue($called);
        $this->assertTrue($calledShouldNot);
    }

    /**
     * @requires extension pcntl
     */
    public function testSignalMultipleUsagesForTheSameListener()
    {
        if ($this->loop instanceof StreamSelectLoop && !(\function_exists('pcntl_signal') && \function_exists('pcntl_signal_dispatch'))) {
            $this->markTestSkipped('Signal handling with StreamSelectLoop requires pcntl_signal() and pcntl_signal_dispatch(), see also disable_functions');
        }

        $funcCallCount = 0;
        $func = function () use (&$funcCallCount) {
            $funcCallCount++;
        };
        $this->loop->addTimer(1, function () {});

        $this->loop->addSignal(SIGUSR1, $func);
        $this->loop->addSignal(SIGUSR1, $func);

        $this->loop->addTimer(0.4, function () {
            posix_kill(posix_getpid(), SIGUSR1);
        });
        $this->loop->addTimer(0.9, function () use (&$func) {
            $this->loop->removeSignal(SIGUSR1, $func);
        });

        $this->loop->run();

        $this->assertSame(1, $funcCallCount);
    }

    /**
     * @requires extension pcntl
     */
    public function testSignalsKeepTheLoopRunning()
    {
        if ($this->loop instanceof StreamSelectLoop && !(\function_exists('pcntl_signal') && \function_exists('pcntl_signal_dispatch'))) {
            $this->markTestSkipped('Signal handling with StreamSelectLoop requires pcntl_signal() and pcntl_signal_dispatch(), see also disable_functions');
        }

        $function = function () {};
        $this->loop->addSignal(SIGUSR1, $function);
        $this->loop->addTimer(1.5, function () use ($function) {
            $this->loop->removeSignal(SIGUSR1, $function);
            $this->loop->stop();
        });

        $this->assertRunSlowerThan(1.4);
    }

    /**
     * @requires extension pcntl
     */
    public function testSignalsKeepTheLoopRunningAndRemovingItStopsTheLoop()
    {
        if ($this->loop instanceof StreamSelectLoop && !(\function_exists('pcntl_signal') && \function_exists('pcntl_signal_dispatch'))) {
            $this->markTestSkipped('Signal handling with StreamSelectLoop requires pcntl_signal() and pcntl_signal_dispatch(), see also disable_functions');
        }

        $function = function () {};
        $this->loop->addSignal(SIGUSR1, $function);
        $this->loop->addTimer(1.5, function () use ($function) {
            $this->loop->removeSignal(SIGUSR1, $function);
        });

        $this->assertRunFasterThan(1.6);
    }

    public function testTimerIntervalCanBeFarInFuture()
    {
        // Maximum interval for ExtUvLoop implementation
        $interval = ((int) (PHP_INT_MAX / 1000)) - 1;
        // start a timer very far in the future
        $timer = $this->loop->addTimer($interval, function () { });

        $this->loop->futureTick(function () use ($timer) {
            $this->loop->cancelTimer($timer);
        });

        $this->assertRunFasterThan($this->tickTimeout);
    }

    private function assertRunSlowerThan($minInterval)
    {
        $start = microtime(true);

        $this->loop->run();

        $end = microtime(true);
        $interval = $end - $start;

        $this->assertLessThan($interval, $minInterval);
    }

    private function assertRunFasterThan($maxInterval)
    {
        $start = microtime(true);

        $this->loop->run();

        $end = microtime(true);
        $interval = $end - $start;

        $this->assertLessThan($maxInterval, $interval);
    }
}
