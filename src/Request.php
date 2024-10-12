<?php
namespace SharkyDog\HTTP;

class Request extends Message {
  private $method;
  private $path;
  private $qry;
  private $frag;

  public static function parse(string $message): ?self {
    $message = preg_split("#\r?\n\r?\n#", $message, 2);
    $headers = preg_split("#\r?\n#", $message[0]);

    if(!($line=array_shift($headers))) {
      return null;
    }
    if(!preg_match("#^([a-z]+)\s+(.+)\s+http/(\d(?:\.\d)?)$#i", $line, $m)) {
      return null;
    }

    $req = new static($m[1],$m[2]);
    $req->ver = $m[3];

    foreach($headers as $header) {
      if(!preg_match("#^([^\:\s]+)\s*\:\s*(.*)$#", $header, $m)) {
        continue;
      }
      $req->addHeaderLine($m[1], $m[2]);
    }

    if(!empty($message[1])) {
      $req->setBody($message[1]);
    }

    return $req;
  }

  public function __construct(string $method, string $path, string $body='', array $headers=[]) {
    parent::__construct();

    $this->method = strtoupper($method);
    $this->path = '/';
    $this->qry = '';
    $this->frag = '';

    if(preg_match('#^/*([^\?\#]+)?(?:\?([^\#]*))?(?:\#(.*))?#', $path, $m)) {
      $this->path = '/'.($m[1] ?? '');
      $this->qry = $m[2] ?? '';
      $this->frag = $m[3] ?? '';
    }

    foreach($headers as $name => $value) {
      $this->addHeader($name, $value);
    }

    $this->setBody($body);
  }

  public function getMethod(): string {
    return $this->method;
  }

  public function getPath(): string {
    return $this->path;
  }

  public function getQuery(): string {
    return $this->qry;
  }

  public function getFragment(): string {
    return $this->frag;
  }

  public function render(bool $body=true): string {
    $str  = $this->method." ".$this->path;
    $str .= $this->qry ? '?'.$this->qry : '';
    $str .= $this->frag ? '#'.$this->frag : '';
    $str .= " HTTP/".$this->ver;
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
