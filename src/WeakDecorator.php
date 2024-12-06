<?php
namespace SharkyDog\HTTP;

class WeakDecorator extends \stdClass {
  private $_obj;

  public function __construct(object $obj) {
    $this->_obj = \WeakReference::create($obj);
  }

  public function __call($name, $args) {
    $obj = $this->_obj->get();
    return $obj!==null ? $obj->$name(...$args) : null;
  }

  public function valid(): bool {
    return $this->_obj->get() !== null;
  }

  public function obj(): ?object {
    return $this->_obj->get();
  }
}
