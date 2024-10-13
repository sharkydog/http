<?php

// include this file or add your autoloader


use SharkyDog\HTTP;

// if sharkydog/logger package is installed
// if not, only errors and warnings will be printed
HTTP\Log::level(99);

// Handlers are the central part of the server,
// they must all extend HTTP\Handler
//
// The callbacks added as routes in 01-server-quickstart.php example
// are decorated by HTTP\Handler\Callback.
// It implements only onHeaders() and onRequest()

// A little helper function to print messages
// Not using HTTP\Log as sharkydog/logger may not be installed
function pn($d) {
  print "*** Ex.02: ".print_r($d,true)."\n";
}

$httpd = new HTTP\Server;
$httpd->listen('0.0.0.0', 23480);
$httpd->route('/favicon.ico', 410);


// Only onRequest() is (soft) required.
// If not defined in child, a http 500 error will be returned.
//
class MyHandler extends HTTP\Handler {
  // called after headers are received
  // must return:
  //  null - request continues to onRequest()
  //  new handler or callback - request continues to onHeaders() of the new handler
  //  Promise - delayed response, more on that bellow
  //  response - Response object, int or string
  //
  // If a request body is expected (Content-Length > 0),
  // server will close the connection if response is returned
  //
  // ServerRequest->setBufferBody() and ServerRequest->setBody()
  // can only be used here
  public function onHeaders(HTTP\ServerRequest $request) {
    pn(static::class.'->onHeaders');
    return null;
  }

  // called after request body is received
  // or immediately after onHeaders() when there is no request body
  // must return Promise, Response object, int or string
  public function onRequest(HTTP\ServerRequest $request) {
    pn(static::class.'->onRequest');
    return 'Hello there!';
  }

  // called when a chunk from the request body is received
  //   if request body was not set to a stream
  //    and
  //   if ServerRequest->setBufferBody() was not set to true (defaults to false)
  //
  // will also be called on upgraded connections
  // without a request body stream
  public function onData(HTTP\ServerRequest $request, string $data) {
    pn(static::class.'->onData');
  }

  // called just before the response is rendered and headers sent
  // response headers can be modified here
  public function onResponse(HTTP\ServerRequest $request, HTTP\Response $response) {
    pn(static::class.'->onResponse');
  }

  // called after response headers were sent
  // no point modifying headers or body here
  public function onResponseHeaders(HTTP\ServerRequest $request, HTTP\Response $response) {
    pn(static::class.'->onResponseHeaders');
  }

  // called after response body is sent
  // or immediately after onResponseHeaders() when there is no response body
  // or when connection is closed
  //
  // request is finished,
  // server will be listening for another request on a keep-alive connecten
  public function onEnd(HTTP\ServerRequest $request, ?HTTP\Response $response) {
    pn(static::class.'->onEnd');
  }
}

$httpd->route('/hello', new MyHandler);


// Promise
//
// these are simple promises
// no easy chaining, no rejections
// can be resolved only once
// extends EventEmitter and emits 'resolve' and 'cancel'
// resolve event has one parameter - the response
//
// the response can be a Response object, int or string
// can also be another promise (for now),
// but I am not sure what will happen with chaining
// resolving to promise may be disabled in the future
//
// if a listener is attached to resolved or cancelled promise,
// the respective event will be emitted on that listener only
//
class PromiseHandler extends MyHandler {
  private $_resp;

  public function __construct(string $response) {
    $this->_resp = $response;
  }

  public function onRequest(HTTP\ServerRequest $request) {
    // just to print the pn() message from MyHandler
    parent::onRequest($request);

    $pr = new HTTP\Promise;

    // Also, $pr->resolve() will do nothing if Promise was created with a resolver
    //
    //$pr = new HTTP\Promise($resolver);
    //
    //... somewhere else
    //    $resolver('my promised response');
    //
    //return $pr;

    // resolve the promise after 5 seconds
    new HTTP\Timer(5, fn() => $pr->resolve($this->_resp));

    return $pr;
  }
}

$httpd->route('/promise', new PromiseHandler('Slow hello there'));


// Handler redirect
//
// by default a redirect can happen up to 5 times per request
// this can be changed with Server->setMaxHandlerRedirects()
// once a handler redirects, it is no longer used for the current request
// not even onEnd() is called on it,
// the new handler is used for everything, starting with onHeaders()
//
class RedirectHandler extends MyHandler {
  public function onHeaders(HTTP\ServerRequest $request) {
    // just to print the pn() message from MyHandler
    parent::onHeaders($request);

    return new PromiseHandler('Redirected slow hello there');
  }
}

$httpd->route('/redirect', new RedirectHandler);

// Trying to brake server
//
class TooManyRedirects extends MyHandler {
  public function onHeaders(HTTP\ServerRequest $request) {
    // just to print the pn() message from MyHandler
    parent::onHeaders($request);
    return new self;
  }
}

$httpd->route('/error', new TooManyRedirects);
