<?php
namespace SharkyDog\HTTP\Helpers;
use React\Stream;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use Evenement\EventEmitter;
use SharkyDog\HTTP\Log;

class RangeResourceStream extends EventEmitter implements RangeStreamInterface {
  private $stream;
  private $loop;
  private $closed = false;
  private $listening = false;
  private $remaining = -1;

  public function __construct($stream, LoopInterface $loop = null) {
    if(!\is_resource($stream) || \get_resource_type($stream) !== "stream") {
      throw new \InvalidArgumentException('First parameter must be a valid stream resource');
    }

    $meta = stream_get_meta_data($stream);
    if(isset($meta['mode']) && $meta['mode'] !== '' && strpos($meta['mode'],'r') === strpos($meta['mode'],'+')) {
      throw new \InvalidArgumentException('Given stream resource is not opened in read mode');
    }
    if(($meta['seekable']??null) !== true) {
      throw new \RuntimeException('Stream is not seekable');
    }

    $stat = fstat($stream);
    if($stat === false) {
      throw new \RuntimeException('Stat failed on stream');
    }
    if(!($stat['size']??null)) {
      throw new \RuntimeException('Stream has no size');
    }

    if(stream_set_blocking($stream, false) !== true) {
      throw new \RuntimeException('Unable to set stream resource to non-blocking mode');
    }

    stream_set_read_buffer($stream, 0);
    $this->stream = $stream;
    $this->loop = $loop ?: Loop::get();

    $this->resume();
  }

  public function __destruct() {
    Log::destruct(static::class);
  }

  public function isReadable() {
    return !$this->closed;
  }

  public function pause() {
    if($this->listening) {
      $this->loop->removeReadStream($this->stream);
      $this->listening = false;
    }
  }

  public function resume() {
    if(!$this->listening && !$this->closed) {
      $this->loop->addReadStream($this->stream, function() {
        $this->_handleData();
      });
      $this->listening = true;
    }
  }

  public function pipe(Stream\WritableStreamInterface $dest, array $options=[]) {
    return Stream\Util::pipe($this, $dest, $options);
  }

  public function close() {
    if($this->closed) return;

    $this->closed = true;
    $this->emit('close');
    $this->pause();
    $this->removeAllListeners();

    if(is_resource($this->stream)) {
      fclose($this->stream);
    }
  }

  public function size() {
    if($this->closed) throw new \RuntimeException('Stream is closed');
    return fstat($this->stream)['size'] ?? false;
  }

  public function seek(int $pos) {
    if($this->closed) throw new \RuntimeException('Stream is closed');
    fseek($this->stream, $pos);
  }

  public function tell() {
    if($this->closed) throw new \RuntimeException('Stream is closed');
    return ftell($this->stream);
  }

  public function read(int $len) {
    $this->remaining = $len;
  }

  public function eof() {
    if($this->closed) throw new \RuntimeException('Stream is closed');
    return feof($this->stream) ?: $this->tell()==$this->size();
  }

  private function _handleData() {
    $len = $this->remaining < 0 ? 65536 : $this->remaining;

    if($len) {
      $data = fread($this->stream, $len);
      $len = strlen($data);
    }

    if($len && $this->remaining > 0) {
      $this->remaining -= $len;
    }

    if($len) {
      $this->emit('data', [$data]);
      if($this->closed) return;
    }

    if($this->remaining == 0) {
      $this->emit('endrange');
      if($this->closed) return;
    }

    if($this->eof()) {
      $this->emit('end');
      $this->close();
      return;
    }

    if($this->remaining == 0) {
      $this->close();
      return;
    }
  }
}
