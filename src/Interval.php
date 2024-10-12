<?php
namespace SharkyDog\HTTP;

class Interval extends Timer {
  public function __construct(float $interval, callable $callback) {
    parent::__construct($interval, $callback, true);
  }
}
