<?php
namespace SharkyDog\HTTP\Helpers;
use React\Stream;

interface RangeStreamInterface extends Stream\ReadableStreamInterface {
  public function size();
  public function seek(int $pos);
  public function tell();
  public function read(int $len);
  public function eof();
}
