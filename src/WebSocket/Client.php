<?php
namespace SharkyDog\HTTP\WebSocket;
use SharkyDog\HTTP;
use SharkyDog\HTTP\Log;
use SharkyDog\PrivateEmitter\PrivateEmitterTrait;
use Ratchet\RFC6455\Messaging as WsM;
use Ratchet\RFC6455\Handshake as WsH;
use React\Stream;

final class Client {
  use PrivateEmitterTrait;

  private $_client;
  private $_reconnInterval = 0;
  private $_pingInterval = 60;
  private $_pingForced = false;
  private $_closing = false;
  private $_running = false;
  private $_connected = false;
  private $_upgradeRequest;
  private $_clientRequest;
  private $_buffer;
  private $_stream;
  private $_ping;
  private $_timer;

  public function __construct(string $url, array $headers=[]) {
    $headers = array_replace($headers, [
      'Connection' => 'Upgrade',
      'Upgrade' => 'websocket',
      'Sec-WebSocket-Version' => 13
    ]);

    $this->_client = new HTTP\Client($url);
    if($this->_client->scheme() == 'wss') {
      $this->_client->tls(true);
    }

    $this->_upgradeRequest = new HTTP\Request('GET', $this->_client->path(), '', $headers);
  }

  public function __destruct() {
    Log::destruct(static::class);
  }

  public function resolver($resolver) {
    $this->_client->resolver($resolver);
  }

  public function reconnect(int $interval) {
    $this->_reconnInterval = max(0,$interval);
  }

  public function pingInterval(int $pingInterval, bool $pingForced=false) {
    $this->_pingInterval = max(0,$pingInterval);
    $this->_pingForced = $pingForced;
  }

  public function connect(int $timeout=0) {
    if($this->_running) return;

    $this->_client->on('error-connect', function($e) {
      $this->_client->removeAllListeners();
      $this->_client->abort();
      $this->_clientRequest = null;
      $this->_emit('error-connect', [$e]);
      $this->_reconnect();
    });
    $this->_client->on('open', function() {
      $this->_onOpen();
    });
    $this->_client->on('close', function() {
      $this->_onClose();
    });

    if($this->_timer) {
      $this->_timer->cancel();
      $this->_timer = null;
    }

    $this->_closing = false;
    $this->_running = true;
    $this->_clientRequest = $this->_client->request($this->_upgradeRequest);
    $this->_client->timeout($timeout ?: null);
    $this->_client->resume();
  }

  public function connected(): bool {
    return $this->_connected;
  }
  public function running(): bool {
    return $this->_running;
  }

  private function _reconnect() {
    if(!$this->_reconnInterval) {
      $this->_running = false;
      $this->_emit('stop');
      return;
    }

    $this->_emit('reconnect', [$this->_reconnInterval]);

    if(!$this->_reconnInterval) {
      $this->_running = false;
      $this->_emit('stop');
      return;
    }

    $this->_timer = new HTTP\Timer($this->_reconnInterval, function() {
      $this->_running = false;
      $this->connect();
    });
  }

  private function _onOpen() {
    $rq = $this->_clientRequest;

    $rq->on('request', function($request) {
      $request->addHeader('Sec-WebSocket-Key', base64_encode(random_bytes(16)));
      $this->_emit('request', [$request]);
    });
    $rq->on('response-headers', function($response, $request) {
      $this->_onHeaders($response, $request);
    });
  }

  private function _onHeaders($response, $request) {
    if($response->getStatus() != 101) {
      $this->_closing = true;
      $this->_emit('error-response', [$response, $request]);
      return;
    } else {
      $this->_emit('response', [$response, $request]);
      if(!$this->_clientRequest) return;
    }

    try {
      $this->_buffer = new WsM\MessageBuffer(
        new WsM\CloseFrameChecker,
        function($msg) {
          $this->_emit('message', [$msg->getPayload()]);
        },
        function($frame) {
          $this->_onControlFrame($frame);
        },
        false, null, null, null,
        function($data) {
          $this->_stream->write($data);
        },
        null
      );
      Log::destruct($this->_buffer);
    } catch(\Exception $e) {
      Log::error('WS Client: '.$e->getMessage());
      $this->close(false);
      return;
    }

    $this->_stream = new Stream\ThroughStream;
    $request->setBody($this->_stream);

    if($this->_pingInterval) {
      $this->_timer = new HTTP\Interval($this->_pingInterval, function() {
        $this->_ping();
      });
    }

    $rq = $this->_clientRequest;

    $rq->on('response', function($response) {
      $this->_onResponse($response);
    });
    $rq->on('response-data', function($data) {
      $this->_buffer->onData($data);
    });
  }

  private function _onResponse($response) {
    if($response->getStatus() != 101) {
      return;
    }
    $this->_connected = true;
    $this->_emit('open');
  }

  private function _onClose() {
    if(!$this->_buffer) {
      $this->_client->removeAllListeners();
      $this->_clientRequest = null;
      $this->_emit('error-connect', [new \Exception('Connection rejected')]);
      $this->_reconnect();
      return;
    }

    if($this->_timer) {
      $this->_timer->cancel();
      $this->_timer = null;
    }

    $this->_client->removeAllListeners();
    $this->_clientRequest->removeAllListeners();
    $this->_clientRequest = null;

    $this->_buffer = null;
    $this->_stream = null;
    $this->_ping = null;

    $this->_connected = false;
    $this->_emit('close', [!$this->_closing]);

    if($this->_closing) {
      $this->_running = false;
      $this->_emit('stop');
    } else {
      $this->_reconnect();
    }
  }

  private function _onControlFrame($frame) {
    $opCode = $frame->getOpCode();

    if($opCode == WsM\Frame::OP_CLOSE) {
      $this->end(true);
      return;
    }
    if($opCode == WsM\Frame::OP_PING) {
      $pong = new WsM\Frame($frame->getPayload(), true, WsM\Frame::OP_PONG);
      $this->send($pong);
      $this->_ping = time();
      return;
    }
    if($opCode == WsM\Frame::OP_PONG) {
      if(is_string($this->_ping) && $this->_ping==$frame->getPayload()) {
        $this->_ping = time();
      }
      return;
    }
  }

  private function _ping() {
    if(is_string($this->_ping)) {
      Log::debug('WS Client: Ping: no pong, closing...', 'ws','ping');
      $this->close(true);
      return;
    }

    if(!$this->_pingForced && is_int($this->_ping) && ($this->_ping+$this->_pingInterval)>time()) {
      return;
    }

    $this->_ping = uniqid('ping_');
    $ping = new WsM\Frame($this->_ping, true, WsM\Frame::OP_PING);
    $this->send($ping);
  }

  public function send($data) {
    if(!$this->_connected) {
      return;
    }

    try {
      if($data instanceOf WsM\Frame) {
        $this->_buffer->sendFrame($data);
      } else if(is_string($data)) {
        $this->_buffer->sendMessage($data);
      } else {
        return;
      }
    } catch(\Exception $e) {
      Log::error('WS Client: '.$e->getMessage());
      $this->close(false);
    }
  }

  public function end(bool $reconnect=false, ?int $code = WsM\Frame::CLOSE_NORMAL) {
    if(!$this->_connected) {
      return;
    }

    if($code !== null) {
      $code = max(WsM\Frame::CLOSE_NORMAL, $code);
      $code = min(WsM\Frame::CLOSE_TLS, $code);
      $this->send($this->_buffer->newCloseFrame($code));
    }

    if($this->_timer) {
      $this->_timer->cancel();
      $this->_timer = null;
    }

    if(!$reconnect) {
      $this->_closing = true;
    }

    $this->_stream->end();
  }

  public function close(bool $reconnect=false) {
    if(!$this->_stream) return;

    if(!$reconnect) {
      $this->_closing = true;
    }

    $this->_stream->close();
  }
}
