<?php
namespace SharkyDog\HTTP\Helpers;
use SharkyDog\HTTP\WebSocket as WS;
use SharkyDog\PrivateEmitter\PrivateEmitterTrait;

abstract class WsClientDecorator {
  use PrivateEmitterTrait;

  protected $ws;

  public function __construct(string $url, array $headers=[]) {
    $this->ws = new WS\Client($url, $headers);
  }

  public function __call($name, $args) {
    return $this->ws->$name(...$args);
  }

  protected function _event_stop() {
    $this->ws->removeAllListeners();
    $this->_emit('stop');
  }

  public function connect(int $timeout=0) {
    if($this->ws->running()) return;

    $this->ws->forwardEvents(
      $this->_emitter(),
      'open','close','stop','reconnect',
      'error-connect','error-response',
      'request','response','message'
    );

    $this->ws->connect($timeout);
  }
}
