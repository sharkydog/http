<?php
namespace SharkyDog\HTTP;
use React\Socket\Connection;

class ServerConnection {
  use DataTrait;

  private $_conn;
  private $closed;
  private $closing;
  private $ID;
  private $TLS;
  private $localAddr;
  private $localPort;
  private $remoteAddr;
  private $remotePort;

  public function __construct(Connection $conn) {
    $this->_conn = $conn;
    $this->closed = false;
    $this->closing = false;

    $this->ID = (int)$conn->stream;

    $url = parse_url($conn->getLocalAddress());
    $this->TLS = ($url['scheme']??'') == 'tls';
    $this->localAddr = trim(($url['host']??''),'[]');
    $this->localPort = $url['port']??($this->TLS?443:80);

    $url = parse_url($conn->getRemoteAddress());
    $this->remoteAddr = trim(($url['host']??''),'[]');
    $this->remotePort = $url['port']??0;
  }

  public function __destruct() {
    Log::destruct(static::class);
  }

  public function __get($prop) {
    if($prop == 'attr') return $this->_data();
    if($prop[0] == '_') return null;
    return $this->$prop ?? null;
  }

  public function end() {
    if($this->closed || $this->closing) return;
    $this->closing = true;
    $this->_conn->end();
  }

  public function close() {
    if($this->closed) return;
    $this->closed = true;
    $this->closing = true;
    $this->_conn->close();
  }
}
