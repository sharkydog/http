<?php
namespace SharkyDog\HTTP;

trait DataTrait {
  private $_data;

  private function _data() {
    if(!$this->_data) $this->_data = (object)[];
    return $this->_data;
  }
}
