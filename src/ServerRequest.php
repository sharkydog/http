<?php
namespace SharkyDog\HTTP;

class ServerRequest extends Request {
  use DataTrait;

  private $_conn;
  private $server;
  private $localHost;
  private $routePath;
  private $bufferBody = false;

  public $GET = [];
  public $POST = [];

  public function setServerParams(Server $server, ServerConnection $conn) {
    if($this->server) return;
    $this->_conn = $conn;
    $this->server = $server;
    $this->localHost = explode(':',$this->getHeader('Host')?:'')[0] ?: $conn->localAddr;
    @parse_str($this->getQuery(), $this->GET);
  }

  public function setRoutePath(string $path) {
    if($this->routePath) return;
    $this->routePath = $path;
  }

  public function setBufferBody(bool $p) {
    $this->bufferBody = $p;
  }

  public function abort() {
    $this->_conn->close();
  }

  public function __get($prop) {
    if($prop == 'attr') return $this->_data();
    if($prop[0] == '_') return null;
    return $this->$prop ?? $this->_conn->$prop ?? null;
  }
}
