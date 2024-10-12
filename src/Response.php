<?php
namespace SharkyDog\HTTP;

class Response extends Message {
  public static $statusMsg = [
    101 => 'Switching Protocols',
    200 => 'OK',
    304 => 'Not Modified',
    400 => 'Bad Request',
    401 => 'Unauthorized',
    403 => 'Forbidden',
    404 => 'Not Found',
    410 => 'Gone',
    426 => 'Upgrade Required',
    500 => 'Internal Server Error',
    503 => 'Service Unavailable'
  ];
  private $status;
  private $trailers = [];

  public static function parse(string $message): ?self {
    $message = preg_split("#\r?\n\r?\n#", $message, 2);
    $headers = preg_split("#\r?\n#", $message[0]);

    if(!($line=array_shift($headers))) {
      return null;
    }
    if(!preg_match("#^http/(\d(?:\.\d)?)\s+(\d{3})\s*(.*?)\s*$#i", $line, $m)) {
      return null;
    }

    $res = new self((int)$m[2]);
    $res->ver = $m[1];

    foreach($headers as $header) {
      if(!preg_match("#^([^\:\s]+)\s*\:\s*(.*)$#", $header, $m)) {
        continue;
      }
      $res->addHeaderLine($m[1], $m[2]);
    }

    if(!empty($message[1])) {
      $res->setBody($message[1]);
    }

    return $res;
  }

  public function __construct(int $status=200, string $body='', array $headers=[]) {
    parent::__construct();

    $this->setStatus($status);

    foreach($headers as $name => $value) {
      $this->addHeader($name, $value);
    }

    $this->setBody($body);
  }

  public function setStatus(int $status) {
    $this->status = $status;
  }
  public function getStatus(): int {
    return $this->status;
  }

  public function parseTrailers($trailers) {
    $trailers = preg_split("#\r?\n#", $trailers);

    foreach($trailers as $trailer) {
      if(!preg_match("#^([^\:\s]+)\s*\:\s*(.*)$#", $trailer, $m)) {
        continue;
      }
      $this->trailers[strtolower($m[1])] = $m[2];
    }
  }

  public function getTrailers(): array {
    return $this->trailers;
  }
  public function getTrailer($name): ?string {
    return $this->trailers[strtolower($name)] ?? null;
  }

  public function render(bool $body=true): string {
    $str  = "HTTP/".$this->ver." ".$this->status;
    $str .= ($msg=(static::$statusMsg[$this->status]??'')) ? ' '.$msg : '';
    $str .= "\r\n";

    foreach($this->headers as $name => $values) {
      $name = implode('-',array_map('ucfirst',explode('-',$name)));
      foreach($values as $value) $str .= $name.": ".$value."\r\n";
    }

    $str .= "\r\n";

    if($body && $this->body && is_string($this->body)) {
      $str .= $this->body;
    }

    return $str;
  }
}
