<?php
namespace SharkyDog\HTTP;
use React\Socket\TcpServer;
use React\Socket\SecureServer;
use React\Stream;

class Server {
  private $_serverStr = 'ShD HTTP Server v1.1';
  private $_maxHandlerRedirects = 5;
  private $_keepAliveTimeout = 10;
  private $_routes = [];
  private $_filters = [];
  private $_sockCnt = 0;

  public function setServerStr(string $str) {
    $this->_serverStr = $str;
  }

  public function setMaxHandlerRedirects(int $int) {
    $this->_maxHandlerRedirects = max(0,$int);
  }

  public function setKeepAliveTimeout(int $int) {
    $this->_keepAliveTimeout = max(0,$int);
  }

  public function getListenCount(): int {
    return $this->_sockCnt;
  }

  public function getHandlerRoutes($handler): array {
    if(is_callable($handler)) {
      return [];
    }

    $routes = [];

    foreach($this->_routes as $path => $hnd) {
      if($handler !== $hnd) {
        continue;
      }
      $routes[] = $path;
    }

    return $routes;
  }

  public function listen(string $addr, int $port, $tls=null) {
    $socket = new TcpServer($addr.':'.$port);

    if(is_string($tls)) {
      if(!is_file($tls)) {
        throw new \RuntimeException('Certificate file '.$tls.' not found');
      }
      $tls = ['local_cert'=>$tls, 'verify_peer'=>false];
    }
    if(is_array($tls)) {
      $socket = new SecureServer($socket, null, $tls);
    }

    $socket->on('connection', function($conn) {
      $this->_onOpen($conn);
    });

    $this->_sockCnt++;
  }

  public function route(string $path, $handler) {
    $path = '/'.trim($path,'/');

    if(is_callable($handler)) {
      $handler = new Handler\Callback($handler);
    }

    $this->_routes[$path] = $handler;

    return $handler;
  }

  public function filter(Filter $filter): Filter {
    foreach((new \ReflectionObject($filter))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
      if($method->isStatic() || $method->isConstructor() || $method->isDestructor()) continue;
      if($method->class == Filter::class) continue;
      if(!isset($this->_filters[$method->name])) $this->_filters[$method->name] = [];
      $this->_filters[$method->name][] = $filter;
    }
    return $filter;
  }

  private function _filter($conn, $close, $method, ...$args) {
    if(empty($this->_filters[$method])) return true;

    foreach($this->_filters[$method] as $filter) {
      $response = $filter->$method(...$args);

      if((!$conn->conn || $conn->conn->closing) && $method != 'onConnClose') {
        Log::debug('HTTP: Filter: '.get_class($filter).'::'.$method.'(): closing connection', 'http','filter');
        return false;
      }
      if($response) {
        Log::debug('HTTP: Filter: '.get_class($filter).'::'.$method.'(): response', 'http','filter');
        $conn->filterResponse = true;
        $this->_response($conn, $response, $close);
        return false;
      }
    }

    return true;
  }

  private function _onOpen($conn) {
    $conn = new ObjectDecorator($conn);
    $conn->conn = new ServerConnection($conn->obj());
    $conn->timer = Timer::noop();
    $conn->buffer = new MessageBuffer;
    $conn->handler = null;
    $conn->request = null;
    $conn->response = null;
    $conn->reqBody = null;
    $conn->reqBodyEnding = false;
    $conn->resBody = null;
    $conn->responsePromise = null;
    $conn->filterResponse = false;

    if(Log::loggerLoaded()) {
      $c = $conn->conn;
      $c = $c->ID.','.$c->remoteAddr.':'.$c->remotePort.'=>'.$c->localAddr.':'.$c->localPort;
      Log::debug('HTTP: Server: New connection '.$c, 'http','server');
      Log::destruct($conn->obj(), 'HTTP: Server: '.$c, 'http','server');
      Log::memory();
      $conn->hnd = new \SharkyDog\Log\Handle(function() use($c) {
        Log::debug('HTTP: Server: Close connection '.$c, 'http','server');
        Log::memory();
      });
    }

    $conn->on('close', function() use($conn) {
      $this->_onClose($conn);
    });

    if(!$this->_filter($conn, true, 'onConnOpen', $conn->conn)) {
      return;
    }

    $conn->on('data', function($data) use($conn) {
      $this->_onData($conn, $data);
    });
    $conn->on('error', function($e) use($conn) {
      $this->_onError($conn, $e);
    });

    $conn->buffer->on('headers', function($headers) use($conn) {
      $this->_onHeaders($conn, $headers);
    });
    $conn->buffer->on('error', function($code, $text) use($conn) {
      $this->_sendError($conn, $code, $text);
    });
  }

  private function _onData($conn, $data) {
    if(!$this->_filter($conn, true, 'onConnData', $conn->conn, $data)) {
        return;
    }
    $conn->buffer->feed($data);
  }

  private function _onHeaders($conn, $headers) {
    if(!($request=ServerRequest::parse($headers))) {
      $this->_sendError($conn, 400, 'Can not parse headers');
      return;
    }

    $request->setServerParams($this, $conn->conn);
    $conn->timer->cancel();
    $conn->request = $request;

    $reqConn = $request->getHeader('Connection');
    $reqConn = $reqConn ? strtolower($reqConn) : '';
    $upgrade = strpos($reqConn,'upgrade') !== false;
    $ctLen = !$upgrade ? (int)$request->getHeader('Content-Length') : 0;

    if(!$this->_filter($conn, $ctLen>0, 'onReqHeaders', $conn->conn, $request)) {
      return;
    }

    $reqPath = rtrim($request->getPath(),'/').'/';
    $response = null;

    foreach($this->_routes as $routePath => $handler) {
      $rtPath = rtrim($routePath,'/').'/';

      if(strpos($reqPath, $rtPath) !== 0) {
        continue;
      }

      $request->setRoutePath($routePath);

      if($handler instanceOf Handler) {
        $conn->handler = $handler;
      } else if($handler instanceOf Response) {
        $response = clone $handler;
      } else {
        $response = $handler;
      }

      break;
    }

    if($conn->handler === null && $response === null) {
      $response = 404;
    }

    if(!$this->_filter($conn, $ctLen>0, 'onReqRoute', $conn->conn, $request, $response)) {
      return;
    }

    if($response !== null) {
      $this->_response($conn, $response, $ctLen>0);
      return;
    }

    $redirectsLeft = $this->_maxHandlerRedirects;

    while($r = $conn->handler->onHeaders($request)) {
      if(!$conn->conn || $conn->conn->closing) {
        return;
      }

      if(!($r instanceOf Handler)) {
        if(is_callable($r)) {
          $r = new Handler\Callback($r);
        } else {
          $response = $r;
          break;
        }
      }

      if(!($redirectsLeft--)) {
        $this->_onError($conn, 'Too many handler redirects');
        return;
      }

      $conn->handler = $r;
    }

    if(!$this->_filter($conn, $ctLen>0, 'afterReqHeaders', $conn->conn, $request, $response)) {
      return;
    }

    if($response !== null) {
      if(!($response instanceOf Response)) {
        $this->_response($conn, $response, $ctLen>0);
        return;
      }
      if(!$upgrade || $response->getStatus() != 101) {
        $this->_response($conn, $response, $ctLen>0);
        return;
      }
    }

    if(!$upgrade && !$ctLen) {
      $this->_request($conn);
      return;
    }

    if($ctLen) {
      $ctType = strtolower($request->getHeader('Content-Type')?:'');
    }

    $reqBody = $request->getBody();

    if($reqBody instanceOf Stream\WritableStreamInterface) {
      if(!$reqBody->isWritable()) {
        $this->_onError($conn, 'Request stream not writable');
        return;
      }
      $datacb = function($data,$ctLen=null) use($conn,$reqBody) {
        $reqBody->write($data);
        if($ctLen !== 0) return;
        $conn->reqBodyEnding = true;
        $reqBody->end();
      };
      $reqBody->on('close', function() use($conn) {
        $conn->reqBody = null;
      });
      $conn->reqBody = $reqBody;
      Log::destruct($reqBody, 'HTTP: Server: Request body destroyed', 'http','server');
    } else if($ctLen && $request->bufferBody) {
      $bufferBody = '';
      $datacb = function($data,$ctLen) use($request,$ctType,&$bufferBody) {
        $bufferBody .= $data;
        if($ctLen) return;
        if($ctType == 'application/x-www-form-urlencoded') {
          @parse_str($bufferBody, $request->POST);
        } else {
          $request->setBody($bufferBody);
        }
        $bufferBody = '';
      };
    } else if($ctLen || $upgrade) {
      $datacb = function($data) use($conn) {
        $this->_requestData($conn, $data);
      };
    } else {
      $datacb = null;
    }

    if($ctLen) {
      $datacb = function($data) use($datacb, $conn, &$ctLen) {
        $data = substr($data, 0, $ctLen);
        $ctLen -= strlen($data);

        $datacb($data, $ctLen);
        if($ctLen) return;

        if($conn->reqBody) {
          $conn->reqBody->on('close', function() use($conn) {
            if(!$conn->reqBodyEnding) return;
            $this->_request($conn);
          });
        } else {
          $this->_request($conn);
        }
      };
    }

    if($datacb) {
      $conn->buffer->on('data', function($data) use($datacb, $conn) {
        if(!$this->_filter($conn, true, 'onReqData', $conn->conn, $conn->request, $data)) {
          if($conn->buffer) $conn->buffer->removeAllListeners('data');
          return;
        }
        $datacb($data);
      });
    }

    if($response) {
      $this->_response($conn, $response);
      return;
    }

    if($upgrade) {
      $this->_request($conn);
      return;
    }
  }

  private function _request($conn) {
    if(!$this->_filter($conn, false, 'onRequest', $conn->conn, $conn->request)) {
      return;
    }

    $response = $conn->handler->onRequest($conn->request);

    if(!$conn->conn || $conn->conn->closing) {
      return;
    }

    $this->_response($conn, $response);
  }

  private function _requestData($conn, $data) {
    $conn->handler->onData($conn->request, $data);
  }

  private function _response($conn, $response, $close=false) {
    if(!$conn->conn || $conn->conn->closing) {
      return;
    }
    if($conn->response) {
      return;
    }

    if(is_int($response)) {
      $response = new Response($response);
    }
    else if(is_string($response)) {
      $response = new Response(200, $response);
    }
    else if($response instanceOf Promise) {
      $response->on('resolve', function($resp) use($conn) {
        $conn->responsePromise = null;
        $this->_response($conn, $resp);
      });
      $response->on('cancel', function() use($conn) {
        $conn->responsePromise = null;
        if($conn->response) return;
        if(!$conn->conn) return;
        $conn->conn->close();
      });
      if($response->is(Promise::PENDING)) {
        $conn->responsePromise = $response;
        $this->_filter($conn, false, 'onResPromise', $conn->conn, $conn->request, $response);
      }
      return;
    }
    else if(!($response instanceOf Response)) {
      $this->_onError($conn, 'Response must be int, string, Response or Promise');
      return;
    }

    $status = $response->getStatus();
    $resBody = $response->getBody() ?: '';

    if($resBody) {
      if($resBody instanceOf Stream\ReadableStreamInterface) {
        $trEnc = $response->getHeader('Transfer-Encoding');
        $trEnc = $trEnc ? strtolower($trEnc) : '';

        if($status != 101 && strpos($trEnc,'chunked') === false) {
          if(!(int)$response->getHeader('Content-Length')) {
            $resBody->close();
            $this->_onError($conn, 'Stream response must have Content-Length or Transfer-Encoding: chunked');
            return;
          }
        }
      } else if(!is_string($resBody)) {
        $this->_onError($conn, 'Response body must be a string or readable stream');
        return;
      }
    }

    $upgrade = false;
    $resConn = $response->getHeader('Connection');
    $resConn = $resConn ? strtolower($resConn) : '';

    if(!$close) {
      if($conn->request) {
        $reqConn = $conn->request->getHeader('Connection');
        $reqConn = $reqConn ? strtolower($reqConn) : '';
      }

      if(!$conn->request) {
        $close = true;
      } else if(!$reqConn) {
        $close = $conn->request->getVer() != '1.1';
      } else if(strpos($reqConn,'close') !== false) {
        $close = true;
      }
    }

    if(!$close) {
      if(strpos($resConn,'close') !== false) {
        $close = true;
      } else if(!$close && strpos($resConn,'upgrade') !== false && $status == 101) {
        $upgrade = true;
      }

      if($upgrade && (!$resBody || is_string($resBody))) {
        $this->_onError($conn, 'Upgraded response must have a readable stream body');
        return;
      }
    }

    $conn->response = $response;

    if($conn->responsePromise) {
      $conn->responsePromise->cancel();
    }

    if($close) {
      $response->addHeader('Connection', 'close');
    } else if(!$resConn) {
      $response->addHeader('Connection', 'keep-alive');
      $response->addHeader('Keep-Alive', $this->_keepAliveTimeout);
    }

    if($this->_serverStr) {
      $response->addHeader('Server', $this->_serverStr);
    }
    if($conn->request) {
      $response->setVer($conn->request->getVer());
    }
    $response->addHeader('Date', gmdate('D, d M Y H:i:s').' GMT');

    if(is_string($resBody)) {
      $response->addHeader('Content-Length', strlen($resBody));
    }

    if(!$this->_filter(
      $conn, false, 'onResponse',
      $conn->conn, $conn->request, $response,
      $resBody, $conn->filterResponse
    )) {
      return;
    }

    if($conn->handler && !$conn->filterResponse) {
      $conn->handler->onResponse($conn->request, $response);

      if(!$conn->conn || $conn->conn->closing) {
        return;
      }
    }

    $conn->write($response->render(false));

    if(!$this->_filter(
      $conn, false, 'afterResHeaders',
      $conn->conn, $conn->request, $response,
      $resBody, $conn->filterResponse
    )) {
      return;
    }

    $ctLen = !$upgrade ? (int)$response->getHeader('Content-Length') : 0;

    if(!$resBody || is_string($resBody)) {
      if($conn->handler && !$conn->filterResponse) {
        $conn->handler->onResponseHeaders($conn->request, $response);

        if(!$conn->conn || $conn->conn->closing) {
          return;
        }
      }

      if($resBody && $ctLen) {
        $conn->write(substr($resBody,0,$ctLen));
      }

      $this->_resEnd($conn, $close);
      return;
    }

    $conn->resBody = $resBody;
    Log::destruct($resBody, 'HTTP: Server: Response body destroyed', 'http','server');

    if($ctLen) {
      $resBody->on('data', function($data) use($conn, $resBody, &$ctLen) {
        if(!$ctLen || !$conn->conn || $conn->conn->closing) {
          $resBody->close();
          return;
        }

        $data = substr($data, 0, $ctLen);
        $ctLen -= strlen($data);
        $conn->write($data);

        if($ctLen) {
          return;
        }

        $resBody->close();
      });
      $resBody->on('close', function() use($conn, &$ctLen) {
        if(!$ctLen) return;
        $conn->resBody = null;
        $this->_onError($conn, 'Response body closed before sending all data');
      });
    } else {
      $resBody->on('data', function($data) use($conn, $resBody) {
        if(!$conn->conn || $conn->conn->closing) {
          $resBody->close();
          return;
        }
        $conn->write($data);
      });
    }

    if($upgrade) {
      $close = true;
    }

    $resBody->on('close', function() use($conn, $close) {
      if(!$conn->resBody) return;
      $conn->resBody = null;
      $this->_resEnd($conn, $close);
    });

    if($conn->handler) {
      $conn->handler->onResponseHeaders($conn->request, $response);
    }
  }

  private function _sendError($conn, $code, $msg='', $close=true) {
    if(!$conn->conn || $conn->conn->closing) {
      return;
    }
    $this->_response($conn, new Response($code, $msg), $close);
  }

  private function _onError($conn, $e, $close=true) {
    if($e instanceOf \Throwable) {
      $e = '('.$e->getCode().') '.$e->getMessage().' ['.$e->getFile().':'.$e->getLine().']';
    }

    Log::error($e, 'http','server');

    if($close && $conn->conn) {
      if($conn->response) {
        $conn->conn->close();
      } else {
        $this->_sendError($conn, 500);
      }
    }
  }

  private function _resEnd($conn, $close) {
    if(!$this->_filter(
      $conn, false, 'onResEnd',
      $conn->conn, $conn->request, $conn->response,
      $close||!$conn->buffer, $conn->filterResponse
    )) {
      return;
    }

    if(!$conn->buffer) {
      $this->_onClose($conn);
      return;
    }
    if($close) {
      $conn->conn->end();
      return;
    }

    if($conn->handler) {
      $handler = $conn->handler;
      $conn->handler = null;

      $handler->onEnd($conn->request, $conn->response);

      if(!$conn->conn || $conn->conn->closing) {
        return;
      }
    }

    if($conn->reqBody) {
      $conn->reqBodyEnding = false;
      $conn->reqBody->close();
    }

    $conn->buffer->removeAllListeners('data');
    $conn->buffer->reset();

    $conn->request = null;
    $conn->response = null;
    $conn->filterResponse = false;

    $conn->timer->cancel();
    $conn->timer = new Timer($this->_keepAliveTimeout, function() use($conn) {
      $conn->conn->close();
    });
  }

  private function _onClose($conn) {
    if($conn->buffer) {
      $conn->buffer->removeAllListeners();
      $conn->buffer->reset();
      $conn->buffer = null;
    }

    if($conn->reqBody) {
      $conn->reqBodyEnding = false;
      $conn->reqBody->close();
    }
    if($conn->resBody) {
      $conn->resBody->close();
      return;
    }

    if($conn->responsePromise) {
      $conn->responsePromise->cancel();
      $conn->responsePromise = null;
    }

    if($conn->handler) {
      $conn->handler->onEnd($conn->request, $conn->response);
      $conn->handler = null;
    }

    $this->_filter($conn, false, 'onConnClose', $conn->conn, $conn->request, $conn->response);

    $conn->request = null;
    $conn->response = null;

    $conn->timer->cancel();
    $conn->timer = null;
    $conn->conn = null;
  }
}
