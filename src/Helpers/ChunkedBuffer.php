<?php
namespace SharkyDog\HTTP\Helpers;
use Evenement\EventEmitter;
use SharkyDog\HTTP\Log;

class ChunkedBuffer extends EventEmitter {
  private $_err = false;
  private $_cnt = 1;
  private $_len = null;
  private $_buff = '';

  public function __construct() {
  }

  public function __destruct() {
    Log::destruct(static::class);
  }

  public function feed(string $data) {
    if($this->_err) {
      return;
    }
    if(is_null($this->_buff)) {
      return;
    }

    $this->_buff .= $data;
    $data = '';

    if(is_null($this->_len)) {
      if(!preg_match("#^(\r?\n)?([a-f\d]{1,8})(?:\h*;(\V+))?\r?\n#i", $this->_buff, $m)) {
        if(strlen($this->_buff) > 1024) {
          return $this->_error('Chunk length line too big, max 1KiB');
        }
        return;
      }

      if(($pos=strlen($m[0])) > 1024) {
        return $this->_error('Chunk length line too big, max 1KiB');
      }

      $this->_len = hexdec($m[2]);
      $this->_buff = substr($this->_buff, $pos);

      $this->emit('chunk', [$this->_len, trim($m[3]??''), $this->_cnt]);
    }

    if(!$this->_len) {
      if(!preg_match("#\r?\n\r?\n#", "\n".$this->_buff, $m, PREG_OFFSET_CAPTURE)) {
        if(strlen($this->_buff) > 4096) {
          return $this->_error('Trailers too big, max 4KiB');
        }
        return;
      }

      if($m[0][1] > 4096) {
        return $this->_error('Trailers too big, max 4KiB');
      }

      $trls = substr($this->_buff, 0, $m[0][1]);
      $this->_len = null;
      $this->_buff = null;

      $this->emit('done', [$trls, $this->_cnt]);
      return;
    }

    if($this->_buff) {
      $write = substr($this->_buff, 0, $this->_len);
      $wrlen = strlen($write);

      $this->_len -= $wrlen;
      $this->_buff = substr($this->_buff, $wrlen);

      $this->emit('data', [$write, $this->_len, $this->_cnt]);
    }

    if(!$this->_len) {
      $this->_cnt++;
      $this->_len = null;
    }

    if($this->_buff) {
      $this->feed('');
    }
  }

  private function _error($msg) {
    $this->_err = true;
    $this->_len = null;
    $this->_buff = null;
    $this->emit('error', [$msg, $this->_cnt]);
  }
}
