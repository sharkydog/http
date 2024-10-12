<?php
namespace SharkyDog\HTTP;

class ObjectDecorator extends \stdClass {
  private $_obj;

  public function __construct(object $obj) {
    $this->_obj = $obj;
  }

  public function __call($name, $args) {
    return $this->_obj->$name(...$args);
  }

  public function obj(): object {
    return $this->_obj;
  }
}
