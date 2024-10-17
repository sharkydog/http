<?php

// include this file or add your autoloader


use SharkyDog\HTTP;

function pn($d) {
  print "*** Ex.04: ".print_r($d,true)."\n";
}

HTTP\Log::level(99);

$httpd = new HTTP\Server;
$httpd->listen('0.0.0.0', 23480);
$httpd->route('/favicon.ico', 410);


// Filters must extend HTTP\Filter class,
// only overwritten methods will be called.
//
// When multiple filters are added,
// a given method will be called on all of them
// in the order filters were added,
// until one closes the connection
// or returns a response.
//
// Methods that work after response is being send
// can not return reponse, they can only close the connection.
//
// When a method from one filter takes an action (close or response),
// this will stop the same method of the following filters from being called,
// but for the next stages, filters will start from the beginning
// and may continue to the last one.
//
// Example:
//
// There three filters: filter1, filter2 and filter3
//
// filter2 reacts on request headers and returns response
// filter3 is not called on request headers
//
// then, as a response is being returned (from filter2)
// execution of handlers and filters skips other request related stages
// and continues to response stage, therefore for filters:
//
// filter1 is called on response, does nothing
// filter2 is also called, does nothing as well
// filter3 is also called
//
// If any filter closes the connection,
// next possible method is only onConnClose()
// and it will always be called on all filters that define it.


// Any method bellow can close the connection with $conn->close()
//
// onConnOpen(ServerConnection $conn): ?Response
//   on connection open, can return
//   null - continue to following filters
//   response - continue to onResponse()
//
// onConnData(ServerConnection $conn, string $data): ?Response
//   on received data, could be the entire request message with body
//   or only (part of) headers
//   return null or Response
//
// onReqHeaders(ServerConnection $conn, ServerRequest $request): ?Response
//   when headers are parsed,
//   before route is selected and before Handler->onHeaders()
//
// onReqRoute(ServerConnection $conn, ServerRequest $request, $response): ?Response
//   when a route is selected, before Handler->onHeaders()
//   $response can be null, int, string or Response
//   if no route is found, $response will be int 404, $request->routePath will be null
//
// afterReqHeaders(ServerConnection $conn, ServerRequest $request, $response): ?Response
//   if route is not response, after Handler->onHeaders()
//   $request->routePath has the matched route
//   $response is same as above, but returned by onHeaders()
//
// onRequest(ServerConnection $conn, ServerRequest $request): ?Response
//   if route is not response, before Handler->onRequest()
//   request body received
//
// onReqData(ServerConnection $conn, ServerRequest $request, string $data): ?Response
//   on received data from request body
//
// The methods bellow may get null for $request,
// because responses and connection close can happen without a request
//
// onResPromise(ServerConnection $conn, ?ServerRequest $request, Promise $promise): ?Response
//   when a Promise is returned that is not resolved or cancelled
//
// onResponse(ServerConnection $conn, ?ServerRequest $request, Response $response, $body, bool $ftRes=false): void
//   on Response, before Handler->onResponse()
//   $body can be a string, an empty string or a stream
//   $ftRes will true if response is from a previous filter
//   response can not be returned, headers can be added to $response
//
// afterResHeaders(ServerConnection $conn, ?ServerRequest $request, Response $response, $body, bool $ftRes=false): void
//   after response was rendered and headers sent, before Handler->onResponseHeaders()
//
// onResEnd(ServerConnection $conn, ?ServerRequest $request, Response $response, bool $close, bool $ftRes=false): void
//   after request is finished and response is sent
//   $close flag indicates if connection is going to be closed
//   if not, connection is keep alive, Handler->onEnd() will be called next
//   and connection is cleaned for the next request
//
// public function onConnClose(ServerConnection $conn, ?ServerRequest $request, ?Response $response): void
//   always called on all filters that define it


use SharkyDog\HTTP\Filter;
use SharkyDog\HTTP\ServerConnection;
use SharkyDog\HTTP\ServerRequest;
use SharkyDog\HTTP\Promise;
use SharkyDog\HTTP\Response;


// A quick and dirty set of filters to stop Firefox reconnection attempts.
//
// When a connection is dropped (closed without response),
// Firefox tries four more times per request (at least on my PC).
// That makes total 10 connection, 5 for the route that drops
// and 5 more for /favicon.ico
//
// These filters reduce connection attempts to 3
//   - request is made for /test2 - drops
//   - new attempt from FF, StopFoxFlood filter reacts
//   - if FF requests /favicon.ico within 200ms, StopFoxFlood filter reacts
//
// This is obviously not production worthy, it's a simple example.
// Will block all connections from the same remote ip that happen to arrive within 200ms,
// even if requests are normal, from different user agents, on different routes.
// It also doesn't clear its $_lastOpen array.

class Contrack extends Filter {
  public function onConnOpen(ServerConnection $conn): ?Response {
    $ct = $conn->attr->contrack = (object)[];
    $ct->open = microtime(true);
    pn('Contrack '.($conn->ID.','.$conn->remoteAddr).': open');
    return null;
  }
}

class StopFoxFlood extends Filter {
  private $_lastOpen = [];

  public function onConnOpen(ServerConnection $conn): ?Response {
    $ct = $conn->attr->contrack;

    $this->_lastOpen[$conn->remoteAddr] = $this->_lastOpen[$conn->remoteAddr] ?? null;
    $last = &$this->_lastOpen[$conn->remoteAddr];
    $diff = $last ? ($ct->open - $last) : null;
    $last = $ct->open;

    if($diff === null || floor($diff)) {
      return null;
    }

    if(round($diff*1000) < 200) {
      pn('FoxFlood '.($conn->ID.','.$conn->remoteAddr).': Response(429)');
      return new Response(429, 'Hold your horses!');
    }

    return null;
  }
}

// Silence the logger (if installed)
HTTP\Log::level(0);

$httpd->filter(new Contrack);
$httpd->filter(new StopFoxFlood);

$httpd->route('/test1', 'test1');
$httpd->route('/test2', function($rq) {
  $rq->abort();
});


// Track response time

class RespTime extends Filter {
  // The Contrack filter above could be extended to mark when data starts arriving
  public function onConnData(ServerConnection $conn, string $data): ?Response {
    $conn->attr->usStart = $conn->attr->usStart ?? microtime(true);
    return null;
  }

  public function onResponse(ServerConnection $conn, ?ServerRequest $request, Response $response, $body): void {
    if(!isset($conn->attr->usStart) || !$request) return;
    $diff = round((microtime(true) - $conn->attr->usStart) * 1000, 3);
    $response->addHeader('X-ResHdr-Time', $diff.'ms');
    pn('RespTime: '.$request->routePath.': headers: '.$diff.'ms');
  }

  public function onResEnd(ServerConnection $conn, ?ServerRequest $request, Response $response, bool $close): void {
    if(!isset($conn->attr->usStart) || !$request) return;
    $diff = round((microtime(true) - $conn->attr->usStart) * 1000, 3);
    pn('RespTime: '.$request->routePath.': res end: '.$diff.'ms');
    $conn->attr->usStart = null;
  }
}

$httpd->filter(new RespTime);

$httpd->route('/test3', function($rq) {
  $pr = new Promise;

  new HTTP\Timer(1, function() use($pr) {
    $pr->resolve('test3');
  });

  return $pr;
});


// Filters can be used to implement various checks, restrictions and side tasks.
// Like no data timeout, slow request and response timeouts, max connections, etc.
