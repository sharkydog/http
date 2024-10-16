<?php
namespace SharkyDog\HTTP\Helpers;
use React\Stream;
use Evenement\EventEmitter;
use SharkyDog\HTTP\Log;

class ChunkedStream extends EventEmitter implements Stream\DuplexStreamInterface {
  private $minChunk = 0;
  private $buffer = '';
  private $extension = '';
  private $trailers = [];
  private $closed = false;
  private $paused = false;
  private $drain = false;

  public function __construct(int $minChunk=0) {
    $this->minChunk = max($minChunk,0);
  }

  public function __destruct() {
    Log::destruct(static::class);
  }

  public function extension(string $extension) {
    $this->extension = preg_replace('/\v+/','',$extension);
  }

  public function trailers(array $trailers) {
    foreach($trailers as $name => $value) {
      $name = trim($name);
      $name = implode('-',array_map('ucfirst',explode('-',$name)));
      $this->trailers[] = $name.': '.trim($value);
    }
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
    if(!is_string($data)) {
      $data = '';
    }
    if(!strlen($data)) {
      return !$this->closed && !$this->paused;
    } else {
      return $this->write_($data);
    }
  }

  private function write_($data) {
    if($this->closed) {
      return false;
    }

    $end = !strlen($data);

    if($this->minChunk) {
      $this->buffer .= $data;

      if(!$end && strlen($this->buffer) < $this->minChunk) {
        return true;
      }

      $data = $this->buffer;
      $this->buffer = '';
    }

    $this->emit('chunk', [$data,$this->extension]);
    $out = dechex(strlen($data));

    if($this->extension) {
      $out .= ";".$this->extension;
      $this->extension = '';
    }

    $out .= "\r\n".$data;

    if(!strlen($data)) {
      foreach($this->trailers as $trailer) {
        $out .= $trailer."\r\n";
      }
    }

    $out .= "\r\n";
    $this->emit('data', [$out]);

    if(strlen($data) && $end) {
      $this->write_('');
    }

    if($this->paused) {
      $this->drain = true;
    }

    return !$this->closed && !$this->paused;
  }

  public function end($data=null) {
    if(!is_string($data)) {
      $data = '';
    }
    if(strlen($data)) {
      $this->write_($data);
    }

    $this->write_('');

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
    $this->buffer = '';
    $this->extension = '';
    $this->trailers = [];
    $this->removeAllListeners();
  }

  public function isReadable() {
    return !$this->closed;
  }
  public function isWritable() {
    return !$this->closed;
  }
}
