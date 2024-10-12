<?php
namespace SharkyDog\HTTP;
use Evenement\EventEmitter;

class Promise extends EventEmitter {
  const PENDING = 1;
  const RESOLVED = 2;
  const CANCELLED = 4;
  const RESOLVER = 8;

  private $_state;
  private $_value;

  public function __construct(&$resolver=false) {
    $this->_state = self::PENDING;

    if($resolver !== false) {
      $resolver = function($value) {
        return $this->_resolve($value);
      };
      $this->_state |= self::RESOLVER;
    }
  }

  public function __destruct() {
    Log::destruct(static::class);
  }

  public function is(int $states): bool {
    return ($this->_state & $states) != 0;
  }

  public function on($event, callable $listener) {
    return $this->once($event, $listener);
  }

  public function once($event, callable $listener) {
    if($this->is(self::PENDING)) {
      return parent::once($event, $listener);
    }

    if($event == 'resolve' && $this->is(self::RESOLVED)) {
      $listener($this->_value);
    } else if($event == 'cancel' && $this->is(self::CANCELLED)) {
      $listener();
    }

    return $this;
  }

  public function emit($event, array $arguments = []) {
    return;
  }

  public function cancel(): bool {
    if(!$this->is(self::PENDING)) {
      return false;
    }

    $this->_state = self::CANCELLED;
    $this->_value = null;

    parent::emit('cancel');
    $this->removeAllListeners();
    return true;
  }

  public function resolve($value): bool {
    return !$this->is(self::RESOLVER) && $this->_resolve($value);
  }

  private function _resolve($value) {
    if(!$this->is(self::PENDING)) {
      return false;
    }

    $this->_state = self::RESOLVED;
    $this->_value = $value;

    parent::emit('resolve', [$value]);
    $this->removeAllListeners();
    return true;
  }
}
