<?php
namespace SharkyDog\HTTP;
use Evenement\EventEmitter;

class MessageBuffer extends EventEmitter {
  private $_err = false;
  private $_buff = '';

  public function __destruct() {
    Log::destruct(static::class);
  }

  public function reset() {
    $this->_err = false;
    $this->_buff = '';
  }

  public function feed(string $data) {
    if($this->_err) {
      return;
    }
    if(is_null($this->_buff)) {
      $this->emit('data', [$data]);
      return;
    }

    $this->_buff .= $data;
    $data = '';

    if(!preg_match("#\r?\n\r?\n#", $this->_buff, $m, PREG_OFFSET_CAPTURE)) {
      if(strlen($this->_buff) > 4096) {
        $this->_error(431, 'Headers too big, max 4KiB');
      }
      return;
    }

    if($m[0][1] > 4096) {
      $this->_error(431, 'Headers too big, max 4KiB');
      return;
    }

    $headers = substr($this->_buff, 0, $m[0][1]);
    $data = substr($this->_buff, $m[0][1] + strlen($m[0][0]));
    $this->_buff = null;

    $this->emit('headers', [$headers]);

    if($data) {
      $this->emit('data', [$data]);
    }
  }

  private function _error($code, $msg) {
    $this->_err = true;
    $this->_buff = null;
    $this->emit('error', [$code, $msg]);
  }
}
