<?php
namespace SharkyDog\HTTP\WebSocket;
use SharkyDog\HTTP;
use SharkyDog\HTTP\Log;
use Ratchet\RFC6455\Messaging as WsM;

class Connection {
  use HTTP\DataTrait;
  private $request;

  public function __construct(HTTP\ServerRequest $request) {
    $this->request = $request;
  }

  public function __destruct() {
    Log::destruct(static::class);
  }

  public function __get($prop) {
    if($prop == 'attr') return $this->_data();
    return $this->request->$prop ?? null;
  }

  public function send($data) {
    if(!($ws=$this->request->attr->ws) || !$ws->stream) {
      return;
    }
    try {
      if(!($data instanceOf WsM\DataInterface)) {
        $ws->buffer->sendMessage($data);
      } else {
        $ws->buffer->sendFrame($data);
      }
    } catch(\Exception $e) {
      Log::error('WS Connection: '.$e->getMessage());
      $ws->stream->close();
      $ws->stream = null;
    }
  }

  public function end($code = WsM\Frame::CLOSE_NORMAL) {
    if(!($ws=$this->request->attr->ws) || !$ws->stream) {
      return;
    }
    if(!($code instanceOf WsM\DataInterface)) {
      if(!is_int($code)) $code = WsM\Frame::CLOSE_NORMAL;
      $code = $ws->buffer->newCloseFrame($code);
    }
    $this->send($code);
    $ws->stream->end();
    $ws->stream = null;
  }

  public function close() {
    if(!($ws=$this->request->attr->ws) || !$ws->stream) {
      return;
    }
    $ws->stream->close();
    $ws->stream = null;
  }
}
