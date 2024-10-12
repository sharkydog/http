<?php
namespace SharkyDog\HTTP;
use React\EventLoop\Loop;

class Timer_Noop extends Timer {
  public function __construct() {}
}

class Timer {
  private $timer;
  private $count = 0;

  public static function noop(): self {
    return new Timer_Noop;
  }

  public function __construct(float $interval, callable $callback, bool $periodic=false) {
    $callback = function() use($callback) {
      $this->_run($callback);
    };

    if($periodic) {
      $this->timer = Loop::addPeriodicTimer($interval, $callback);
    } else {
      $this->timer = Loop::addTimer($interval, $callback);
    }
  }

  private function _run($callback) {
    $callback($this, ++$this->count);

    if($this->timer && !$this->timer->isPeriodic()) {
      $this->timer = null;
    }
  }

  public function active(): bool {
    return (bool)$this->timer;
  }

  public function cancel() {
    if($this->timer) {
      Loop::cancelTimer($this->timer);
      $this->timer = null;
    }
  }
}
