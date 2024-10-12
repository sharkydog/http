<?php
namespace SharkyDog\HTTP;
use SharkyDog\PrivateEmitter\PrivateEmitterTrait;
use React\Stream;

class ClientRequest {
  use PrivateEmitterTrait;

  private $_request;

  public function __construct(Request $request, &$emitter) {
    $this->_request = $request;
    $emitter = $this->_emitter();
  }

  public function __destruct() {
    Log::destruct(static::class);
  }

  public function getRequest() {
    return $this->_request;
  }

  public function saveToFile(string $filename): self {
    if(!($fh=@fopen($filename,'wb'))) {
      throw new \Exception('Can not open '.$filename.' for writing');
    }

    $this->once('response-headers', function($response) use($fh) {
      $response->setBody(new Stream\WritableResourceStream($fh));
    });

    return $this;
  }

  public function readFromFile(string $filename, string $contentType=''): self {
    if(!($fh=@fopen($filename,'rb'))) {
      throw new \Exception('Can not open '.$filename.' for reading');
    }

    $this->once('request', function($request) use($fh,$filename,$contentType) {
      $request->setBody(new Stream\ReadableResourceStream($fh));
      $request->addHeader('Content-Length', filesize($filename));
      if($contentType) {
        $request->addHeader('Content-Type', $contentType);
      }
    });

    return $this;
  }

  private function _event__end() {
    $this->removeAllListeners();
  }
}
