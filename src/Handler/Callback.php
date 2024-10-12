<?php
namespace SharkyDog\HTTP\Handler;
use SharkyDog\HTTP;

class Callback extends HTTP\Handler {
  private $_callback;
  private $_onHeaders = false;

  public function __construct(callable $callback) {
    if(is_string($callback) && preg_match('/([^\:]+)\:\:([^\:]+)/', $callback, $m)) {
      unset($m[0]);
      $callback = array_values($m);
    }

    try {
      if(is_array($callback))  {
        $rfl = new \ReflectionMethod(...$callback);
      } else {
        $rfl = new \ReflectionFunction($callback);
      }
      $this->_onHeaders = $rfl->getNumberOfParameters() > 1;
    } catch(\Throwable $e) {}

    $this->_callback = $callback;
  }

  public function onHeaders(HTTP\ServerRequest $request) {
    if(!$this->_onHeaders) {
      $request->setBufferBody(true);
      return null;
    }
    return ($this->_callback)($request, true);
  }

  public function onRequest(HTTP\ServerRequest $request) {
    return ($this->_callback)($request, false);
  }
}
