<?php
namespace SharkyDog\HTTP;
use React\EventLoop\Loop;

class Tick {
  public function __construct(callable $callback) {
    Loop::futureTick($callback);
  }
}
