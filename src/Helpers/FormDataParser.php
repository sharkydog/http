<?php
namespace SharkyDog\HTTP\Helpers;
use SharkyDog\HTTP;
use SharkyDog\HTTP\Log;
use React\Stream;
use Evenement\EventEmitter;

class FormDataParser extends EventEmitter implements Stream\WritableStreamInterface {
  private $buffer;
  private $dataCb;
  private $writable = true;
  private $closed = false;

  public static function request(HTTP\ServerRequest $request, string $tmpDir=null): ?self {
    if(!preg_match('#^multipart/form-data\s*;\s*boundary=(.+)#i', $request->getHeader('Content-Type'), $m)) {
      return null;
    }

    if(!($m[1] = trim($m[1],'"'))) {
      throw new \Exception('Empty boundary');
    }

    $parser = new self($m[1]);
    $request->setBody($parser);

    if($tmpDir && !is_dir($tmpDir)) {
      $tmpDir = null;
    }

    $fileData = null;
    if($tmpDir) {
      $fileData = (object)[
        'dir' => rtrim($tmpDir,'\\/'),
        'file' => null,
        'name' => null,
        'type' => null,
        'handle' => null
      ];
    }

    $parser->on('field', function($name,$type,$file) use($fileData,$parser,$request) {
      if(!$fileData && $file) return;

      if($fileData) {
        if($fileData->handle) fclose($fileData->handle);
        $fileData->file = null;
        $fileData->name = null;
        $fileData->type = null;
        $fileData->handle = null;
      }

      if($file) {
        $fileData->name = $file;
        $fileData->type = $type;
      }

      $parser->outCallback(function($data) use($name,$fileData,$parser,$request) {
        self::_outDefault($data,$name,$fileData,$parser,$request);
      });
    });

    $parser->on('close', function() use($fileData) {
      if($fileData && $fileData->handle) {
        fclose($fileData->handle);
      }
    });

    return $parser;
  }

  private static function _outDefault($data,$name,$fileData,$parser,$request) {
    if(!isset($request->POST[$name])) {
      $request->POST[$name] = '';
      $var = &$request->POST[$name];
    } else {
      if(!is_array($request->POST[$name]) || !isset($request->POST[$name][0])) {
        $request->POST[$name] = [$request->POST[$name]];
      }
      $request->POST[$name][] = '';
      $key = array_key_last($request->POST[$name]);
      $var = &$request->POST[$name][$key];
    }

    if($fileData && $fileData->name) {
      $f = preg_replace('#[^a-z0-9-_\.]+#i', '_', $fileData->name);
      $i = 0;

      do {
        $fn = $fileData->dir.'/tmpfile_'.(++$i).'_'.$f;
      } while(is_file($fn));

      $fileData->file = $fn;
      $fileData->handle = fopen($fn,'wb');

      $var = [
        'file' => $fileData->file,
        'name' => $fileData->name,
        'type' => $fileData->type
      ];

      fwrite($fileData->handle, $data);
      $parser->outCallback(function($data) use($fileData) {
        fwrite($fileData->handle, $data);
      });
    } else {
      $var .= $data;
      $parser->outVar($var);
    }
  }

  public function __construct(string $boundary) {
    $this->buffer = new MultipartBuffer($boundary);

    $this->buffer->on('part', function($hdrs, $cntr) {
      $this->_onPart($hdrs, $cntr);
    });

    $this->buffer->on('data', function($data, $cntr) {
      if(!$this->dataCb) return;
      $this->emit('data-out', [$data]);
      ($this->dataCb)($data);
    });

    $this->buffer->on('error', function($code, $msg, $cntr) {
      $this->_error($msg);
    });

    $this->buffer->on('done', function() {
      $this->end();
    });
  }

  public function __destruct() {
    Log::destruct(static::class);
  }

  private function _onPart($hdrs, $cntr) {
    $ctDisp = $hdrs['Content-Disposition'] ?? null;
    if(!$ctDisp || !preg_match('#^\s*form-data\s*;\s*#i',$ctDisp,$m)) {
      $this->_error('Content-Disposition header not found in part#'.$cntr);
      return;
    }

    $ctDisp = substr($ctDisp, strlen($m[0]));
    $ctType = $hdrs['Content-Type'] ?? 'text/plain';
    $name = $filename = null;
    preg_match_all('#([^;=]+)\s*=\s*([^;]+)#',$ctDisp,$m,PREG_SET_ORDER);

    foreach($m as $p) {
      $k = strtolower(trim($p[1]));
      $v = trim(trim($p[2]),'"');

      switch($k) {
        case 'name':
          $name = $v;
          break;
        case 'filename':
          $filename = $v;
          break;
      }
    }

    if(!$name) {
      $this->_error('Content-Disposition header does not contain "name" parameter in part#'.$cntr);
      return;
    }

    $this->dataCb = null;
    $this->emit('field', [$name, $ctType, $filename]);
  }

  private function _error($msg) {
    $this->writable = false;
    $this->emit('error', [$msg]);
    $this->close();
  }

  public function outIgnore() {
    $this->dataCb = null;
  }

  public function outVar(string &$var) {
    if(!$this->writable) return;
    $this->dataCb = function($data) use(&$var) {
      $var .= $data;
    };
  }

  public function outCallback(callable $callback) {
    if(!$this->writable) return;
    $this->dataCb = $callback;
  }

  public function isWritable() {
    return $this->writable;
  }

  public function write($data) {
    if(!$this->writable) return false;
    $this->emit('data-in', [$data]);
    $this->buffer->feed($data);
    return true; // or $this->writable?
  }

  public function end($data=null) {
    if($this->closed) return;

    if($this->writable && !is_null($data)) {
      $this->write($data);
    }

    $this->writable = false;
    $this->emit('end');
    $this->close();
  }

  public function close() {
    if($this->closed) return;
    $this->buffer->close();
    $this->dataCb = null;
    $this->writable = false;
    $this->closed = true;
    $this->emit('close');
    $this->removeAllListeners();
  }
}
