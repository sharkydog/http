<?php
namespace SharkyDog\HTTP\Handler;
use SharkyDog\HTTP;

class Dir extends HTTP\Handler {
  private $basedir;
  private $index;
  private $ranges;

  public function __construct(string $basedir, ?string $index=null, bool $ranges=false) {
    $this->basedir = rtrim($basedir, '/').'/';
    $this->index = $index;
    $this->ranges = $ranges;
  }

  public function onRequest(HTTP\ServerRequest $request) {
    if(!is_dir($this->basedir)) {
      return 404;
    }

    $path = $request->getPath();
    $route = rtrim($request->routePath??'', '/');

    if(strpos($path, $route) !== 0) {
      return 404;
    }

    $filename = $this->basedir.substr($path, strlen($route)+1);

    if(is_file($filename)) {
      return new HTTP\FileResponse($filename, $request, [], $this->ranges);
    }
    if(!is_dir($filename)) {
      return 404;
    }

    if(($path[-1]??'') != '/') {
      return new HTTP\Response(301,'',['Location'=>$path.'/']);
    }

    if($this->index) {
      $filename = rtrim($filename, '/').'/'.$this->index;

      if(is_file($filename)) {
        return new HTTP\FileResponse($filename, $request, [], $this->ranges);
      }
    }

    return 403;
  }
}
