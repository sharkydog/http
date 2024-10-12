<?php
namespace SharkyDog\HTTP;
use React\Stream;

class FileResponse extends Response {
  public static $ctypes = [
    'htm' => 'text/html',
    'html' => 'text/html',
    'js'  => 'application/javascript',
    'css' => 'text/css'
  ];
  private static $finfo;

  private function mime($file) {
    $ext = ($pos=strrpos($file,'.'))!==false ? strtolower(substr($file,$pos+1)) : null;
    if($ext && isset(static::$ctypes[$ext])) return static::$ctypes[$ext];
    if(!self::$finfo) self::$finfo = new \finfo(FILEINFO_MIME_TYPE);
    return self::$finfo->file($file);
  }

  public function __construct(string $file, ServerRequest $request, array $headers=[], bool $ranges=false) {
    parent::__construct(200, '', $headers);

    if(!is_file($file)) {
      $this->setStatus(404);
      return;
    }

    clearstatcache(true, $file);
    $mtime = filemtime($file);

    $ifModifiedSince = (int)strtotime($request->getHeader('If-Modified-Since')?:'');
    if($mtime <= $ifModifiedSince) {
      $this->setStatus(304);
      return;
    }

    if($ranges) {
      $ifUnmodifiedSince = (int)strtotime($request->getHeader('If-Unmodified-Since')?:'');
      if($ifUnmodifiedSince && $mtime > $ifUnmodifiedSince) {
        $this->setStatus(412);
        return;
      }

      $hasRange = $request->hasHeader('Range');
      if($hasRange) {
        $ifRange = (int)strtotime($request->getHeader('If-Range')?:'');
      }
    } else {
      $hasRange = false;
    }

    $fh = fopen($file,'rb');
    $this->addHeader('Content-Type', $this->mime($file)?:'text/plain; charset="utf-8"');
    $this->addHeader('Last-Modified', gmdate('D, d M Y H:i:s', $mtime).' GMT');

    if($ranges) {
      $this->addHeader('Accept-Ranges', 'bytes');
    }

    if($hasRange && (!$ifRange || $mtime <= $ifRange)) {
      Helpers\ByteRangeStream::response(new Helpers\RangeResourceStream($fh), $request, $this);
    } else {
      $this->addHeader('Content-Length', filesize($file));
      $this->setBody(new Stream\ReadableResourceStream($fh));
    }
  }
}
