<?php
namespace SharkyDog\HTTP\WebSocket;
use SharkyDog\HTTP;
use SharkyDog\HTTP\Log;
use Ratchet\RFC6455\Messaging as WsM;

class Connection {
  use HTTP\DataTrait;
  private $_request;
  private $request;

  public function __construct(HTTP\ServerRequest $request) {
    $this->_request = $request;
    $this->request = new Request($request);
  }

  public function __destruct() {
    Log::destruct(static::class);
  }

  public function __get($prop) {
    if($prop == 'attr') return $this->_data();
    if($prop[0] == '_') return null;
    return $this->$prop ?? $this->_request->$prop ?? null;
  }

  public function send($data) {
    if(!($ws=$this->_request->attr->ws) || !$ws->stream) {
      return;
    }

    try {
      if($data instanceOf WsM\Frame) {
        $ws->buffer->sendFrame($data);
      } else if(is_string($data)) {
        $ws->buffer->sendMessage($data);
      } else {
        return;
      }
    } catch(\Exception $e) {
      Log::error('WS Connection: '.$e->getMessage());
      $ws->stream->close();
      $ws->stream = null;
    }
  }

  public function end(?int $code = WsM\Frame::CLOSE_NORMAL) {
    if(!($ws=$this->_request->attr->ws) || !$ws->stream) {
      return;
    }

    if($code !== null) {
      $code = max(WsM\Frame::CLOSE_NORMAL, $code);
      $code = min(WsM\Frame::CLOSE_TLS, $code);
      $this->send($ws->buffer->newCloseFrame($code));
    }

    $ws->stream->end();
    $ws->stream = null;
  }

  public function close() {
    if(!($ws=$this->_request->attr->ws) || !$ws->stream) {
      return;
    }
    $ws->stream->close();
    $ws->stream = null;
  }
}
