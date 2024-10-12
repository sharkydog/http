<?php
namespace SharkyDog\HTTP\Helpers;
use SharkyDog\HTTP;
use SharkyDog\HTTP\Log;
use React\Stream;
use Evenement\EventEmitter;

class ByteRangeStream extends EventEmitter implements Stream\ReadableStreamInterface {
  private $rgStream;
  private $mpStream;
  private $ranges = [];
  private $boundary;

  public static function response(RangeStreamInterface $stream, HTTP\Request $request, HTTP\Response $response) {
    try {
      if(!($range = $request->getHeader('Range'))) {
        throw new \RangeException('Empty range');
      }
      if(!preg_match('#bytes\s*=([\d\s,-]+)#i', $range, $m)) {
        throw new \RangeException('Can not parse range: "'.$range.'"');
      }
      if(!preg_match_all('#\s*(\d*)\s*-\s*(\d*)\s*#i', $m[1], $m, PREG_SET_ORDER)) {
        throw new \RangeException('Can not parse range: "'.$range.'"');
      }

      $btrgStream = new self($stream, array_filter(array_map(function($m) {
        return strlen($m[1].$m[2]) ? [$m[1],$m[2]] : null;
      }, $m)), $response->getHeader('Content-Type'));

      $response->setStatus(206);
      $response->setBody($btrgStream);
      $response->addHeader('Content-Length', $btrgStream->size());

      if($btrgStream->isMultipart()) {
        $contentType = 'multipart/byteranges; boundary='.$btrgStream->getBoundary();
        $response->addHeader('Content-Type', $contentType);
      } else {
        $contentRange = $btrgStream->getContentRange();
        $response->addHeader('Content-Range', $contentRange);
      }
    } catch(\RangeException $e) {
      $response->setStatus(416);
      $response->addHeader('X-Range-Msg',$e->getMessage());
      $response->addHeader('Content-Range','bytes */'.$stream->size());
      $stream->close();
    }
  }

  public function __construct(RangeStreamInterface $stream, array $ranges, string $contentType=null) {
    if(!($size=$stream->size())) {
      throw new \RuntimeException(get_class($stream).' has no size');
    }

    foreach($ranges as $k => $range) {
      $range[0] = (int)($v=$range[0]??null)||$v===0||$v==='0' ? (int)$v : null;
      $range[1] = (int)($v=$range[1]??null)||$v===0||$v==='0' ? (int)$v : null;
      $ranges[$k] = [$range[0]?:'', $range[1]?:''];

      if(is_null($range[0])) {
        if(!$range[1]) continue;
        $range[0] = $range[1] > $size ? 0 : $size - $range[1];
        $range[1] = $size - 1;
      } else if(is_null($range[1])) {
        if(is_null($range[0])) continue;
        $range[1] = $size - 1;
      } else {
        if($range[1] >= $size) $range[1] = $size - 1;
      }

      if($range[1] < $range[0]) continue;

      foreach($this->ranges as $k => $_range) {
        if($_range[0] < $range[0] && ($_range[1]+1) >= $range[0]) {
          $range[0] = $_range[0];
        }
        if($_range[1] > $range[1] && $_range[0] <= ($range[1]+1)) {
          $range[1] = $_range[1];
        }
        if($_range[0] >= $range[0] && $_range[1] <= $range[1]) {
          unset($this->ranges[$k]);
        }
      }

      $this->ranges[] = $range;
    }

    if(empty($this->ranges)) {
      $ranges = implode(',', array_map(fn($r)=>$r[0].'-'.$r[1], $ranges));
      throw new \RangeException('No valid range found in "'.$ranges.'", input size: '.$size);
    }

    $this->rgStream = $stream;
    $this->ranges = array_values($this->ranges);

    if(count($this->ranges) == 1) {
      $this->rgStream->seek($this->ranges[0][0]);
      $this->rgStream->read(($this->ranges[0][1] + 1) - $this->ranges[0][0]);

      $this->rgStream->on('data', function($data) {
        $this->emit('data', [$data]);
      });

      $this->rgStream->on('endrange', function() {
        $this->rgStream->close();
      });

      $this->rgStream->on('close', function() {
        $this->close();
      });

      return;
    }

    $this->boundary = '------'.hash('sha256', uniqid('', true));
    $this->mpStream = new MultipartStream($this->boundary);
    $contentType = $contentType ?: 'text/plain';

    foreach($this->ranges as $k => $range) {
      $this->mpStream->part([
        'Content-Type' => $contentType,
        'Content-Range' => $this->_contentRange($range)
      ], ($range[1] + 1) - $range[0]);
    }

    $endRangeFn = function() {
      if(empty($this->ranges)) {
        $this->close();
      }

      $range = array_shift($this->ranges);

      $this->rgStream->seek($range[0]);
      $this->rgStream->read(($range[1] + 1) - $range[0]);
    };

    $this->rgStream->on('data', function($data) {
      $this->mpStream->write($data);
    });

    $this->rgStream->on('endrange', $endRangeFn);

    $this->rgStream->on('close', function() {
      $this->close();
    });

    $this->mpStream->on('data', function($data) {
      $this->emit('data', [$data]);
    });

    $this->mpStream->on('close', function() {
      $this->close();
    });

    $endRangeFn();
  }

  public function __destruct() {
    Log::destruct(static::class);
  }

  public function isMultipart() {
    return (bool)$this->boundary;
  }

  public function getBoundary() {
    return $this->boundary;
  }

  public function isReadable() {
    return (bool)$this->rgStream && $this->rgStream->isReadable();
  }

  public function pause() {
    if(!$this->rgStream) {
      return;
    }
    $this->rgStream->pause();
  }

  public function resume() {
    if(!$this->rgStream) {
      return;
    }
    $this->rgStream->resume();
  }

  public function pipe(Stream\WritableStreamInterface $dest, array $options=[]) {
    return Stream\Util::pipe($this, $dest, $options);
  }

  public function close() {
    if(!$this->rgStream) {
      return;
    }

    $this->emit('close');

    $this->rgStream->close();
    $this->rgStream = null;

    if($this->mpStream) {
      $this->mpStream->close();
      $this->mpStream = null;
    }

    $this->ranges = [];
    $this->boundary = null;
    $this->removeAllListeners();
  }

  private function _contentRange($range) {
    if(!$this->rgStream) {
      throw new \RuntimeException(get_class($this->rgStream).' is closed');
    }
    return 'bytes '.$range[0].'-'.$range[1].'/'.$this->rgStream->size();
  }
  public function getContentRange() {
    return $this->_contentRange($this->ranges[0]);
  }

  public function size() {
    if(!$this->boundary) {
      return ($this->ranges[0][1] + 1) - $this->ranges[0][0];
    }

    if(!$this->mpStream) {
      throw new \RuntimeException(get_class($this->mpStream).' is closed');
    }

    return $this->mpStream->size();
  }
}
