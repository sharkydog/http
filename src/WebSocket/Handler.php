<?php
namespace SharkyDog\HTTP\WebSocket;
use SharkyDog\HTTP;
use SharkyDog\HTTP\Log;
use Ratchet\RFC6455\Messaging as WsM;
use Ratchet\RFC6455\Handshake as WsH;
use React\Stream;

abstract class Handler extends HTTP\Handler {
  protected static $protocols = [];
  private $_pingInterval = 0;
  private $_pingForced = false;

  public function __construct(int $pingInterval=0, bool $pingForced=false) {
    $this->pingInterval($pingInterval, $pingForced);
  }

  public function pingInterval(int $pingInterval, bool $pingForced=false) {
    $this->_pingInterval = max(0,$pingInterval);
    $this->_pingForced = $pingForced;
  }

  public function onHeaders(HTTP\ServerRequest $request) {
    $resUpgrade = new HTTP\Response(101, '', [
      'Connection' => 'Upgrade',
      'Upgrade' => 'websocket',
      'Sec-Websocket-Version' => 13
    ]);

    $h = $request->getHeader('Upgrade');
    if(!$h || strtolower($h) != 'websocket') {
      $resUpgrade->setStatus(426);
      $resUpgrade->setBody('Upgrade header must be "websocket"');
      return $resUpgrade;
    }

    if((int)$request->getHeader('Sec-Websocket-Version') != 13) {
      $resUpgrade->setStatus(426);
      $resUpgrade->setBody('Sec-Websocket-Version must be 13');
      return $resUpgrade;
    }

    $key = $request->getHeader('Sec-Websocket-Key');
    if(!$key || strlen(base64_decode($key)) != 16) {
      return new HTTP\Response(400, 'Invalid Sec-Websocket-Key');
    }

    $h = $request->getHeader('Sec-Websocket-Protocol');
    if($h) {
      $h = array_intersect(
        array_map(
          fn($v) => strtolower(trim($v)),
          explode(',',$h)
        ),
        static::$protocols
      );

      if(empty($h)) {
        $resUpgrade->setStatus(426);
        $resUpgrade->setBody('None of Sec-Websocket-Protocol supported');
        return $resUpgrade;
      } else {
        $resUpgrade->addHeader('Sec-Websocket-Protocol', implode(',',$h));
      }
    }

    $ws = $request->attr->ws = (object)[];
    $ws->buffer = null;
    $ws->stream = null;
    $ws->timer = null;
    $ws->ping = null;
    $ws->conn = null;

    try {
      $ws->buffer = new WsM\MessageBuffer(
        new WsM\CloseFrameChecker,
        function($msg) use($ws) {
          if(!$ws->conn) return;
          $this->wsMsg($ws->conn, $msg->getPayload());
        },
        function($frame) use($ws) {
          if(!$ws->conn) return;
          $this->_onControlFrame($ws, $frame);
        },
        true, null, null, null,
        function($data) use($ws) {
          if(!$ws->stream) return;
          $ws->stream->write($data);
        },
        null
      );
      Log::destruct($ws->buffer);
    } catch(\Exception $e) {
      Log::error('WS Handler: '.$e->getMessage());
      $ws->buffer = null;
      return new HTTP\Response(500);
    }

    $key = $key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    $ws->stream = new Stream\ThroughStream;
    $ws->conn = new Connection($request);

    if($this->_pingInterval) {
      $ws->timer = new HTTP\Interval($this->_pingInterval, function() use($ws) {
        $this->_ping($ws);
      });
    }

    $resUpgrade->addHeader('Sec-Websocket-Accept', base64_encode(sha1($key,true)));
    $resUpgrade->setBody($ws->stream);

    return $resUpgrade;
  }

  public function onData(HTTP\ServerRequest $request, string $data) {
    if(!($ws=$request->attr->ws??null) || !$ws->buffer) {
      return;
    }
    $ws->buffer->onData($data);
  }

  public function onResponseHeaders(HTTP\ServerRequest $request, HTTP\Response $response) {
    if($response->getStatus() != 101) {
      return;
    }
    $this->wsOpen($request->attr->ws->conn);
  }

  public function onEnd(HTTP\ServerRequest $request, ?HTTP\Response $response) {
    if(!($ws=$request->attr->ws??null) || !$ws->buffer) {
      return;
    }

    if($ws->timer) {
      $ws->timer->cancel();
      $ws->timer = null;
    }

    $ws->buffer = null;
    $ws->stream = null;
    $ws->ping = null;

    $this->wsClose($ws->conn);
    $ws->conn = null;
  }

  private function _onControlFrame($ws, $frame) {
    $opCode = $frame->getOpCode();

    if($opCode == WsM\Frame::OP_CLOSE) {
      $ws->conn->end();
      return;
    }
    if($opCode == WsM\Frame::OP_PING) {
      $pong = new WsM\Frame($frame->getPayload(), true, WsM\Frame::OP_PONG);
      $ws->conn->send($pong);
      $ws->ping = time();
      return;
    }
    if($opCode == WsM\Frame::OP_PONG) {
      if(is_string($ws->ping) && $ws->ping==$frame->getPayload()) {
        $ws->ping = time();
      }
      return;
    }
  }

  private function _ping($ws) {
    if(is_string($ws->ping)) {
      Log::debug('WS Handler: Ping: no pong, closing...', 'ws','ping');
      $ws->conn->close();
      return;
    }

    if(!$this->_pingForced && is_int($ws->ping) && ($ws->ping+$this->_pingInterval)>time()) {
      return;
    }

    $ws->ping = uniqid('ping_');
    $ping = new WsM\Frame($ws->ping, true, WsM\Frame::OP_PING);
    $ws->conn->send($ping);
  }

  protected function wsOpen(Connection $conn) {
  }
  protected function wsMsg(Connection $conn, string $msg) {
  }
  protected function wsClose(Connection $conn) {
  }
}
