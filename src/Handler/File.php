<?php
namespace SharkyDog\HTTP\Handler;
use SharkyDog\HTTP;

class File extends HTTP\Handler {
  private $filename;
  private $ranges;

  public function __construct(string $filename, bool $ranges=false) {
    $this->filename = $filename;
    $this->ranges = $ranges;
  }

  public function onRequest(HTTP\ServerRequest $request) {
    return new HTTP\FileResponse($this->filename, $request, [], $this->ranges);
  }
}
