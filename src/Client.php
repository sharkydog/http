<?php
namespace SharkyDog\HTTP;
use SharkyDog\PrivateEmitter\PrivateEmitterTrait;
use React\Socket\Connector;
use React\Stream;

final class Client {
  use PrivateEmitterTrait;

  private $_scheme;
  private $_tls;
  private $_host;
  private $_port;
  private $_path;
  private $_headers = [];
  private $_timeout = 5;

  private $_requests = [];
  private $_paused = false;
  private $_conn;
  private $_connector;

  public function __construct(string $url, array $headers=[]) {
    $url = parse_url($url);

    if(empty($url['host'])) {
      throw new \Exception('URL must contain at least a host');
    }

    $this->tls($this->scheme($url['scheme']??'http')=='https');
    $this->host($url['host']);
    $this->port($url['port'] ?? 0);

    $path = $url['path'] ?? '';
    $qry = $url['query'] ?? '';
    $frag = $url['fragment'] ?? '';
    $this->path($path.($qry?'?'.$qry:'').($frag?'#'.$frag:''));

    if($url['user'] ?? '') {
      $headers['Authorization'] = 'Basic '.base64_encode($url['user'].':'.($url['pass'] ?? ''));
    }

    $this->_headers = $headers;
  }

  public function __destruct() {
    Log::destruct(static::class);
  }

  public function scheme(?string $p=null): string {
    if($p) $this->_scheme = strtolower($p);
    return $this->_scheme;
  }

  public function tls(?bool $p=null): bool {
    if(!is_null($p)) $this->_tls = $p;
    return $this->_tls;
  }

  public function host(?string $p=null): string {
    if($p) $this->_host = $p;
    return $this->_host;
  }

  public function port(?int $p=null): int {
    if(!is_null($p)) $this->_port = $p;
    return $this->_port;
  }

  public function path(?string $p=null): string {
    if(!is_null($p)) $this->_path = '/'.ltrim($p,'/');
    return $this->_path;
  }

  public function timeout(?int $p=null): int {
    if($p) $this->_timeout = max(1,$p);
    return $this->_timeout;
  }

  public function pause() {
    $this->_paused = true;
  }

  public function paused(): bool {
    return $this->_paused;
  }

  public function resume() {
    if(!$this->_paused) return;

    $this->_paused = false;

    if(!empty($this->_requests)) {
      new Tick(function() {
        $this->_sendRequest();
      });
    }
  }

  public function abort() {
    $this->_requests = [];

    if($this->_connector) {
      $this->_connector->cancel();
      $this->_connector = null;
    } else if($this->_conn) {
      $this->_conn->close();
    }
  }

  public function request(Request $request): ClientRequest {
    $clientRequest = new ClientRequest($request, $emitter);

    $reqid = (int)array_key_last($this->_requests) + 1;
    $this->_requests[$reqid] = (object)[
      'request' => $request,
      'emitter' => $emitter
    ];

    $clientRequest->on('abort', function() use($reqid) {
      $this->_abortRequest($reqid);
    });

    $reqBody = $request->getBody();
    if($reqBody instanceOf Stream\ReadableStreamInterface) {
      $reqBody->pause();
    }

    if(!$this->_paused && count($this->_requests)==1) {
      new Tick(function() {
        $this->_sendRequest();
      });
    }

    return $clientRequest;
  }

  private function _connect() {
    if($this->_connector) {
      return;
    }

    $ctx = [
      'unix' => false,
      'happy_eyeballs' => false,
      'timeout' => $this->timeout(),
      'tls' => $this->tls()
    ];

    $url  = $this->tls() ? 'tls://' : 'tcp://';
    $url .= $this->host().':'.($this->port() ?: ($this->tls() ? 443 : 80));

    $this->_connector = (new Connector($ctx))->connect($url);
    $this->_connector->then(
      function($conn) {
        $this->_onConnect($conn);
      },
      function($e) {
        $this->_connector = null;
        $this->_paused = true;
        $this->_emit('error-connect', [$e]);
      }
    );
  }

  private function _onConnect($conn) {
    $this->_connector = null;
    $this->_conn = $conn = new ObjectDecorator($conn);

    if(Log::loggerLoaded()) {
      Log::debug('HTTP: Client: New connection', 'http','client');
      Log::destruct($conn->obj(), 'HTTP: Client', 'http','client');
      $conn->hnd = new \SharkyDog\Log\Handle(function() {
        Log::debug('HTTP: Client: Close connection', 'http','client');
      });
    }

    $conn->buffer = null;
    $conn->reqid = null;
    $conn->request = null;
    $conn->emitter = null;
    $conn->reqBody = null;
    $conn->resBody = null;
    $conn->close = false;
    $conn->upgrade = false;

    $conn->on('close', function() {
      $this->_onClose();
    });

    $this->_emit('open');

    if(!$this->_conn) {
      return;
    }

    $conn->buffer = new MessageBuffer;

    $conn->on('data', function($data) {
      if(!$this->_conn->reqid) return;
      $this->_conn->buffer->feed($data);
    });

    $conn->buffer->on('headers', function($headers) {
      $this->_onHeaders($headers);
    });
    $conn->buffer->on('error', function($code, $msg) {
      ($this->_conn->emitter)('error', ['('.$code.') '.$msg]);
      $this->_conn->close();
    });

    if(!$this->_paused) {
      $this->_sendRequest();
    }
  }

  private function _abortRequest($reqid) {
    if(isset($this->_requests[$reqid])) {
      unset($this->_requests[$reqid]);
      return;
    }
    if(!$this->_conn || !$this->_conn->reqid) {
      return;
    }
    if($reqid == $this->_conn->reqid) {
      $this->_conn->close();
    }
  }

  private function _sendRequest() {
    if(empty($this->_requests)) {
      return;
    }
    if(!$this->_conn) {
      $this->_connect();
      return;
    }

    $conn = $this->_conn;

    if($conn->reqid) {
      return;
    }

    $conn->reqid = array_key_first($this->_requests);
    $request = $this->_requests[$conn->reqid];
    unset($this->_requests[$conn->reqid]);

    $conn->request = clone $request->request;
    $conn->emitter = $request->emitter;
    $request = $conn->request;

    foreach($this->_headers as $name => $value) {
      $request->addHeader($name, $value, false);
    }

    $host  = $this->host();
    $host .= (($port=$this->port()) && $port != ($this->tls() ? 443 : 80)) ? ':'.$port : '';

    $request->addHeader('Host', $host, false);
    $request->addHeader('TE', 'trailers');
    $request->addHeader('Date', gmdate('D, d M Y H:i:s').' GMT');

    ($conn->emitter)('request', [$request]);

    if(!$this->_conn) {
      return;
    }

    if(!($hConn = $request->getHeader('Connection'))) {
      $conn->close = $request->getVer() != '1.1';
      $request->addHeader('Connection', $conn->close ? 'close' : 'keep-alive');
    } else {
      $hConn = strtolower($hConn);
      if(strpos($hConn,'close') !== false) {
        $conn->close = true;
      } else if(strpos($hConn,'upgrade') !== false) {
        $conn->upgrade = true;
      }
    }

    if(in_array($request->getMethod(), ['GET'])) {
      $reqBody = null;
    } else {
      $reqBody = $request->getBody();
      $isStrBody = is_string($reqBody);
    }

    if($reqBody && !$isStrBody && !($reqBody instanceOf Stream\ReadableStreamInterface)) {
      ($conn->emitter)('error', ['Request body must be a string or readable stream']);
      $conn->close();
      return;
    }

    if(is_null($reqBody)) {
      $request->removeHeader('Content-Length');
    } else if($isStrBody) {
      $request->addHeader('Content-Length', strlen($reqBody));
    }

    $conn->write($request->render(false));
    ($conn->emitter)('request-headers', [$request]);

    if(!$this->_conn) {
      return;
    }

    $ctLen = (int)$request->getHeader('Content-Length');

    if(!$reqBody || $isStrBody) {
      if($reqBody && $ctLen) {
        $conn->write(substr($reqBody,0,$ctLen));
      }
      return;
    }

    $conn->reqBody = $reqBody;
    Log::destruct($reqBody, 'HTTP: Client: Request body destroyed', 'http','client');

    $datacb = function($data) use($conn) {
      $conn->write($data);
    };

    if($ctLen) {
      $datacb = function($data) use($datacb, &$ctLen) {
        $data = substr($data, 0, $ctLen);
        $ctLen -= strlen($data);

        $datacb($data);
        if($ctLen) return;

        $this->_conn->reqBody->close();
      };
      $reqBody->on('close', function() {
        $this->_conn->reqBody = null;
      });
    } else {
      $reqBody->on('close', function() {
        $this->_conn->reqBody = null;
        if(!$this->_conn->buffer) return;
        $this->_conn->end();
      });
    }

    $reqBody->on('data', $datacb);
    $reqBody->resume();
  }

  private function _onHeaders($headers) {
    $conn = $this->_conn;

    if(!($response = Response::parse($headers))) {
      ($conn->emitter)('error', ['Can not parse response headers']);
      $conn->close();
      return;
    }

    if(!$conn->close && ($hConn=$response->getHeader('Connection'))) {
      $hConn = strtolower($hConn);
      if(strpos($hConn,'close') !== false) {
        $conn->close = true;
      }
    } else if(!$conn->close) {
      $conn->close = $response->getVer() != '1.1';
      $conn->upgrade = false;
    }

    if($conn->upgrade && $response->getStatus() != 101) {
      $conn->close = true;
      $conn->upgrade = false;
    }

    ($conn->emitter)('response-headers', [$response, $conn->request]);

    if(!$this->_conn) {
      return;
    }

    if(!$conn->upgrade) {
      $hEnc = $response->getHeader('Transfer-Encoding');
      $hEnc = $hEnc ? strtolower($hEnc) : '';
      $chunked = $hEnc && strpos($hEnc,'chunked') !== false;
      $ctLen = $chunked ? 0 : (int)$response->getHeader('Content-Length');

      if(!$ctLen && !$chunked) {
        $this->_onResponse($response);
        return;
      }
    }

    if($conn->upgrade && !$conn->reqBody) {
      $reqBody = $conn->request->getBody();

      if($reqBody instanceOf Stream\ReadableStreamInterface) {
        $reqBody->on('data', function($data) {
          $this->_conn->write($data);
        });

        $reqBody->on('close', function() {
          $this->_conn->reqBody = null;
          if(!$this->_conn->buffer) return;
          $this->_conn->end();
        });

        $conn->reqBody = $reqBody;
        Log::destruct($reqBody, 'HTTP: Client: Request body destroyed', 'http','client');
      } else {
        ($conn->emitter)('error', ['Upgraded connection must have a stream request body']);
        $conn->close();
        return;
      }
    }

    $resBody = $response->getBody();

    if(!$conn->upgrade && is_string($resBody)) {
      $resBody = '';
      $bufferBody = '';
      $datacb = function($data, $end=false) use(&$bufferBody, $response) {
        if($data) $bufferBody .= $data;
        if(!$end) return;
        $response->setBody($bufferBody);
        $bufferBody = '';
        $this->_onResponse($response);
      };
    } else if($resBody instanceOf Stream\WritableStreamInterface) {
      $datacb = function($data, $end=false) use($resBody) {
        if($data) $resBody->write($data);
        if($end) $resBody->end();
      };
      $resBody->on('close', function() use($response) {
        $this->_conn->resBody = null;
        if($this->_conn->upgrade) return;
        $this->_onResponse($response);
      });
      $conn->resBody = $resBody;
      Log::destruct($resBody, 'HTTP: Client: Response body destroyed', 'http','client');
    } else {
      $resBody = null;
      $datacb = function($data, $end=false) use($response) {
        if($data) ($this->_conn->emitter)('response-data', [$data, $response, $this->_conn->request]);
        if($end) $this->_onResponse($response);
      };
    }

    if(!$conn->upgrade) {
      if($ctLen) {
        $datacb = function($data) use($datacb, &$ctLen, $response) {
          $data = substr($data, 0, $ctLen);
          $ctLen -= strlen($data);
          $datacb($data, !$ctLen);
        };
      } else if($chunked) {
        $bufferChunked = new Helpers\ChunkedBuffer;
        $bufferChunked->on('chunk', function($len,$ext,$cnt) use($response) {
          ($this->_conn->emitter)('response-chunk', [$len, $ext, $cnt, $response, $this->_conn->request]);
        });
        $bufferChunked->on('data', function($data,$len,$cnt) use($datacb,$response) {
          ($this->_conn->emitter)('response-chunk-data', [$data, $len, $cnt, $response, $this->_conn->request]);
          $datacb($data);
        });
        $bufferChunked->on('done', function($trailers) use($datacb, $response) {
          $response->parseTrailers($trailers);
          $datacb('', true);
        });
        $bufferChunked->on('error', function($msg) use($datacb) {
          $datacb('', true);
        });
        $datacb = function($data) use($bufferChunked) {
          $bufferChunked->feed($data);
        };
      }
    }

    $conn->buffer->on('data', $datacb);

    if($conn->upgrade) {
      $this->_onResponse($response);
    }
  }

  private function _onResponse($response) {
    $conn = $this->_conn;

    ($conn->emitter)('response', [$response, $conn->request]);

    if(!$this->_conn) {
      return;
    }
    if(!$conn->buffer) {
      $this->_onClose();
      return;
    }
    if($conn->close) {
      $conn->close();
      return;
    }
    if($conn->upgrade) {
      return;
    }
    if(empty($this->_requests)) {
      $conn->close();
      return;
    }

    if($conn->reqBody) {
      $conn->reqBody->close();
    }

    $conn->buffer->removeAllListeners('data');
    $conn->buffer->reset();

    ($conn->emitter)('_end');

    $conn->reqid = null;
    $conn->request = null;
    $conn->emitter = null;
    $conn->close = false;
    $conn->upgrade = false;

    if(!$this->_paused) {
      new Tick(function() {
        $this->_sendRequest();
      });
    }
  }

  private function _onClose() {
    $conn = $this->_conn;    

    if($conn->buffer) {
      $conn->buffer->removeAllListeners();
      $conn->buffer->reset();
      $conn->buffer = null;
    }

    if($conn->reqBody) {
      $conn->reqBody->close();
    }
    if($conn->resBody) {
      $conn->resBody->end();
      return;
    }

    if($conn->request) {
      ($conn->emitter)('close', [$conn->request]);
      ($conn->emitter)('_end');
      $conn->reqid = null;
      $conn->request = null;
      $conn->emitter = null;
    }

    $this->_conn = null;
    $this->_emit('close');

    if(!$this->_paused) {
      $this->_sendRequest();
    }
  }

  public function GET(string $path='', array $headers=[]): ClientRequest {
    return $this->request(new Request('GET', $path ?: $this->path(), '', $headers));
  }

  public function POST($body, string $path='', array $headers=[]): ClientRequest {
    $request = new Request('POST', $path ?: $this->path(), '', $headers);

    if(is_array($body)) {
      $body = http_build_query($body);
      $request->addHeader('Content-Type', 'application/x-www-form-urlencoded');
    }

    $request->setBody($body);
    return $this->request($request);
  }
}
