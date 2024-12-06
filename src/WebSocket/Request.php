<?php
namespace SharkyDog\HTTP\WebSocket;
use SharkyDog\HTTP;

class Request extends HTTP\WeakDecorator {
  public function __construct(HTTP\Request $request) {
    parent::__construct($request);
  }

  public function obj(): ?object {
    return null;
  }

  public function setVer() {
  }
  public function setBody() {
  }
  public function addHeader() {
  }
  public function addHeaderLine() {
  }
  public function removeHeader() {
  }
}
