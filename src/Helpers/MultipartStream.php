<?php
namespace SharkyDog\HTTP\Helpers;
use React\Stream;
use Evenement\EventEmitter;
use SharkyDog\HTTP\Log;

class MultipartStream extends EventEmitter implements Stream\DuplexStreamInterface {
  private $boundary;
  private $checkBuffer = '';
  private $parts = [];
  private $forceNonZeroParts = false;
  private $size = 0;
  private $currentPart = -1;
  private $currentLen = 0;
  private $closed = false;
  private $paused = false;
  private $drain = false;

  public function __construct(string $boundary=null, $forceNonZeroParts=false) {
    $this->boundary = $boundary ?: '------'.hash('sha256', uniqid('', true));
    $this->forceNonZeroParts = $forceNonZeroParts;
  }

  public function __destruct() {
    Log::destruct(static::class);
  }

  public function boundary() {
    return $this->boundary;
  }

  public function part(array $headers=[], int $size=0) {
    if($this->closed) {
      return;
    }

    $size = max($size,0);

    if(!$size && $this->forceNonZeroParts) {
      return;
    }

    if($size && $this->size !== false && $this->currentPart > -1) {
      return;
    }

    $this->parts[] = [
      'size' => $size,
      'headers' => []
    ];
    $idx = array_key_last($this->parts);

    if($size && $this->size !== false) {
      // --boundary\r\n
      $szpt = strlen($this->boundary) + 4;
    }

    foreach($headers as $name => $value) {
      $name = trim($name);
      $name = implode('-',array_map('ucfirst',explode('-',$name)));
      $header = $name.': '.trim($value);
      $this->parts[$idx]['headers'][] = $header;

      if($size && $this->size !== false) {
        // Header: value\r\n
        $szpt += strlen($header) + 2;
      }
    }

    if($size && $this->size !== false) {
      // \r\n
      $szpt += 2;

      // data\r\n
      $szpt += $size + 2;

      if(!$this->size) {
        // --boundary--
        $this->size = strlen($this->boundary) + 4;
      }

      $this->size += $szpt;
    } else {
      $this->size = false;
    }
  }

  public function size() {
    return (int)$this->size;
  }

  public function pause() {
    $this->paused = true;
  }

  public function resume() {
    if($this->drain) {
      $this->drain = false;
      $this->emit('drain');
    }
    $this->paused = false;
  }

  public function pipe(Stream\WritableStreamInterface $dest, array $options=[]) {
    return Stream\Util::pipe($this, $dest, $options);
  }

  public function write($data) {
    if($this->closed) {
      return false;
    }
    if(!is_string($data)) {
      $data = '';
    }

    $bndrlen = strlen($this->boundary) + 2;
    $this->checkBuffer .= $data;
    if(strlen($this->checkBuffer) >= $bndrlen) {
      if(strpos($this->checkBuffer, '--'.$this->boundary) !== false) {
        $this->_error('Boundary found in data');
        return false;
      }
      $this->checkBuffer = substr($this->checkBuffer, 0-$bndrlen);
    }

    $out = "";

    while(strlen($data)) {
      $out .= $this->_write($data, $done);
    }

    if($out) {
      $this->emit('data', [$out]);
    }

    if($this->paused) {
      $this->drain = true;
    }

    if($done == 1) {
      $this->end();
    }

    if($done == 2) {
      $this->close();
    }

    return !$this->closed && !$this->paused;
  }

  private function _write(&$data, &$done) {
    if(!$this->currentLen) {
      unset($this->parts[$this->currentPart]);
      $this->currentPart++;
    }

    if(!isset($this->parts[$this->currentPart])) {
      if($this->size === false) {
        $this->part([], 0);
      } else if(!$this->size && $this->currentPart == 0) {
        $this->part([], 0);
      }
    }

    if(!isset($this->parts[$this->currentPart])) {
      $done = 2;
      $data = "";
      return "";
    }

    $part = &$this->parts[$this->currentPart];
    $out = "";

    if(!$this->currentLen) {
      $this->currentLen = $part['size'];

      if($this->currentPart > 0) {
        $out .= "\r\n";
      }

      $out .= "--".$this->boundary."\r\n";

      foreach($part['headers'] as $header) {
        $out .= $header."\r\n";
      }

      $out .= "\r\n";
    }

    if($this->currentLen) {
      $write = substr($data, 0, $this->currentLen);
      $wrlen = strlen($write);

      $this->currentLen -= $wrlen;
      $data = substr($data, $wrlen);
    } else {
      $write = $data;
      $data = "";
    }

    $done = 0;
    $out .= $write;

    if(!$this->currentLen && $this->size !== false) {
      if($this->currentPart == array_key_last($this->parts)) {
        $done = 1;
        $data = "";
        $out .= "\r\n--".$this->boundary."--";
      }
    }

    return $out;
  }

  private function _error($msg) {
    $this->emit('error', [$msg]);
    $this->close();
  }

  public function end($data=null) {
    if(!is_string($data)) {
      $data = '';
    }
    if(strlen($data)) {
      $this->write($data);
    }

    if(!$this->closed && $this->size === false) {
      $this->emit('data', ["\r\n--".$this->boundary."--"]);
    }

    if($this->closed) {
      return;
    }

    $this->emit('end');
    $this->close();
  }

  public function close() {
    if($this->closed) {
      return;
    }

    $this->emit('close');

    $this->closed = true;
    $this->paused = true;
    $this->parts = [];
    $this->boundary = null;
    $this->removeAllListeners();
  }

  public function isReadable() {
    return !$this->closed;
  }
  public function isWritable() {
    return !$this->closed;
  }
}
