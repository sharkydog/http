<?php
namespace SharkyDog\HTTP;

abstract class Message {
  protected $ver = '1.1';
  protected $headers = [];
  protected $body = '';

  protected function __construct() {
  }

  public function __destruct() {
    Log::destruct(static::class);
  }

  public function setVer(string $ver) {
    $this->ver = $ver;
  }
  public function getVer(): string {
    return $this->ver;
  }

  public function setBody($body) {
    $this->body = $body;
  }
  public function getBody() {
    return $this->body;
  }

  public function addHeader(string $name, string $value, bool $overwrite=true) {
    $name = strtolower($name);

    if(!$overwrite && isset($this->headers[$name][0])) {
      return;
    }

    $this->headers[$name] = [$value];
  }

  public function addHeaderLine(string $name, string $value) {
    $name = strtolower($name);

    if(!isset($this->headers[$name][0])) {
      $this->headers[$name] = [];
    }

    $this->headers[$name][] = $value;
  }

  public function hasHeader(string $name): bool {
    return isset($this->headers[strtolower($name)][0]);
  }

  public function removeHeader(string $name) {
    unset($this->headers[strtolower($name)]);
  }

  public function getHeader(string $name): ?string {
    $name = strtolower($name);

    if(!isset($this->headers[$name][0])) {
      return null;
    }

    if(isset($this->headers[$name][1])) {
      return implode(', ', $this->headers[$name]);
    }

    return $this->headers[$name][0];
  }

  public function getHeaderLines(string $name): ?array {
    return $this->headers[strtolower($name)] ?? null;
  }

  public function getAllHeaders(): array {
    return $this->headers;
  }
}
