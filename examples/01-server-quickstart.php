<?php

// include this file or add your autoloader


use SharkyDog\HTTP;

// if sharkydog/logger package is installed
// if not, only errors and warnings will be printed
HTTP\Log::level(99);

$httpd = new HTTP\Server;

// contents of "Server" header
// default: ShD HTTP Server vX.X
// empty string to disable
//$httpd->setServerStr('Test server');

// Keep-Alive connections
// default: 10s
// 0 to disable
//  if disabled, "Connection: close" response header will be sent
//  and connection will be closed by the server
//$httpd->setKeepAliveTimeout(5);

// how many times a handler can return another handler in onHeaders()
// default: 5
//$httpd->setMaxHandlerRedirects(2);

// may listen on multiple addr:port pairs
// throws \RuntimeException if addr:port already used
$httpd->listen('0.0.0.0', 23480);

// https, third parameter sets SSL/TLS
//  an array with SSL context options (https://www.php.net/manual/en/context.ssl.php)
//  or path to PEM certificate (must include private key)
//   then ssl options will be set to: ['local_cert'=>file, 'verify_peer'=>false]
//   throws \RuntimeException if file is not found
//$httpd->listen('0.0.0.0', 23481, './cert.pem');

// routes are matched only on request path
//  /aa/bb will match /aa/bb/cc, but not /aa/bbcc
// first matched route wins
// the matched route (/aa/bb) will be set to ServerRequest->routePath
//  useable in routes with Handler object or a callback

// http status code
// no Content-Type, Content-Length: 0
$httpd->route('/favicon.ico', 410);

// http status code 200
// no Content-Type
$httpd->route('/string', 'Hello World');

// Response object
$httpd->route('/response', new HTTP\Response(200, '<h3>Hello World</h3>', [
  'Content-Type' => 'text/html; charset=utf-8'
]));

// int, string and Response are "fast routes"
// and static, data from request can not be used
//  response is sent right after all request headers are received
//  if request has a body (Content-Length > 0)
//  connection will be closed after sending response

// A handler class
class Handler extends HTTP\Handler {
  // called after headers are received
  // must return:
  //  null - request continues to onRequest()
  //  new handler or callback - request continues to onHeaders() of the new handler
  //  response - Response object, int or string
  public function onHeaders(HTTP\ServerRequest $request) {
    return null;
  }

  // called after request body is received
  // must return Response object, int or string
  public function onRequest(HTTP\ServerRequest $request) {
    return 'Hello from Handler';
  }
}

$httpd->route('/handler', new Handler);

// A callback, converted to a HTTP\Handler\Callback
//
// if the callable has one parameter (or none)
// it will be called in onRequest()
$httpd->route('/callback1', function($request) {
  return 'Hello '.$request->remoteAddr.' from callback 1';
});

// if the callable has two parameters
// it will be called twice
//  in onHeaders(), $onHeaders = true
//  in onRequest(), $onHeaders = false
$httpd->route('/callback2', function($request,$onHeaders) {
  // store some data in request
  $request->attr->stuff = ($request->attr->stuff ?? 0) + 1;
  if($onHeaders) return null;
  return 'Hello '.$request->remoteAddr.' from callback 2, stuff: '.$request->attr->stuff;
});

// Ready to use handlers for static files
//
// second parameter is to allow ranged requests, default: false
$httpd->route('/file', new HTTP\Handler\File('./www/hello.html', false));
// second parameter is an index file,
//  if null or omitted and request is for a directory that exists,
//  a 403 response will be returned
// third parameter is to allow ranged requests
$httpd->route('/dir', new HTTP\Handler\Dir('./www', 'index.html', false));

// default route
// if omitted and no route is matched
// server will return 404 response
$httpd->route('/', 403);


/* A word (or more) about some important classes
 *
 *** ServerRequest, extends Request, extends Message
 *
 * methods:
 *  setBufferBody(bool $p) - default: true
 *   body is buffered and can by retrieved by Message->getBody()
 *   if set to false, request body will be received by a Handler
 *   in Handler->onData(), possibly in chunks
 *  abort() - close connection
 *
 * properties:
 *  server - get the server instance
 *  localHost - contents of Host header or local ip address
 *  routePath - matched route
 *  attr - stdClass for per request user storage
 *  GET - parsed query
 *  POST - parsed body if content type is application/x-www-form-urlencoded
 * properties from ServerConnection:
 *  ID - int, unique id of the connection
 *  closed, closing, TLS - bool, connection is closed, closing, is over https
 *  localAddr, localPort - local ip and port
 *  remoteAddr, remotePort - remote ip and port
 *
 *** Request, extends Message
 *  getMethod(), getPath(), getQuery(), getFragment()
 *
 *** Response, extends Message
 *  setStatus(int $status), getStatus()
 *
 *** Message
 *  setVer(string $ver), getVer() - HTTP version, 1, 1.0, 1.1
 *  setBody($body), getBody() - can be a string or a stream (see React\Stream)
 *
 *  addHeader(string $name, string $value, bool $overwrite=true)
 *    adds a new header, single header line
 *  addHeaderLine(string $name, string $value)
 *    adds a header line for a new or existing header
 *    per HTTP specs, multiple headers with the same name are allowed
 *
 *  hasHeader(string $name)
 *  removeHeader(string $name)
 *
 *  getHeader(string $name)
 *    multiple header lines are concatenated with a comma
 *    return null if header is not set
 *  getHeaderLines(string $name)
 *    header lines are returned as array, or null if not set
 *  getAllHeaders()
 *    two-dimensional array [name][i] = header_line
 *
 */
