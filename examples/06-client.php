<?php

// include this file or add your autoloader


use SharkyDog\HTTP;
use React\Stream;
use React\EventLoop\Loop;

function pn($d) {
  print "*** Ex.06: ".print_r($d,true)."\n";
}

function tmpf($d) {
  if(($h = tmpfile()) === false) return false;
  $f = stream_get_meta_data($h)['uri'];
  fwrite($h, $d);
  rewind($h);
  return (object)[
    'file' => $f,
    'handle' => $h
  ];
}

//HTTP\Log::level(99);

// Setup the server with some routes our client will be using
//
$httpd = new HTTP\Server;
$httpd->setServerStr('ShDServer/1.0');
$httpd->listen('0.0.0.0', 23480);
$httpd->route('/favicon.ico', 410);

// hello there user agent
$httpd->route('/hello', function($request) {
  return 'Hello there '.$request->getHeader('User-Agent');
});

// urlencoded post
$httpd->route('/post', function($request) {
  return print_r($request->POST,true);
});

// download temp file
$httpd->route('/download', function($request) {
  $file = tmpf('tmpfile1');
  $resp = new HTTP\FileResponse($file->file,$request);
  fclose($file->handle);
  return $resp;
});

// upload (PUT)
$httpd->route('/upload', function($request) {
  return $request->getBody();
});


// The client
//
// Multiple requests can be scheduled on one client,
// and one client can be used only on one scheme://host:port,
// because requests will use the same connection if not closed.
// If the connection is closed (dropped or with Connection: close header),
// Next requests will open new connection.
//
// Following redirects is not supported yet.
//
// $headers are default headers, will be used by every request.
// Url must have at least a host, \Exception will be thrown if not.
//
$headers = ['User-Agent'=>'ShDClient/1.0'];
$client = new HTTP\Client('http://127.0.0.1:23480', $headers);

// some debug messages
$client->on('open', function() {
  pn('Client: Open connection');
});
$client->on('close', function() {
  pn('Client: Close connection');
});

// stop the loop when finished
// without this, server will keep the loop running
$client->on('close', function() use($client) {
  if(!$client->pending()) {
    pn('Client: All requests finished');
    $client->removeAllListeners();
    Loop::stop();
  }
});

// Simple GET request
// $rq is a HTTP\ClientRequest instance
// $response is HTTP\Response
//
// Request path can be empty,
// then path will be set from the url used to create the client (default /).
//
// The second parameter is omitted here, it is an array of headers,
// these headers will ovewrite headers from the constructor.
//
$rq = $client->GET('/hello');
$rq->on('response', function($response) {
  pn('Response1: '.$response->getHeader('Server').': '.$response->getBody());
});

// POST
// If the body is an array, it will be sent as urleancoded form
// If anything else Content-Length must be added to headers
// or fully compliant server will probably reject the request.
// This server will simply treat it as a GET request.
$rq = $client->POST(['a'=>'b','c'=>'d'], '/post', ['Connection'=>'close']);
$rq->on('response', function($response) {
  pn("Response2: <<<\n".$response->getBody()."\n>>>");
});

// Save response to a file
$file = tmpf('');
$rq = $client->GET('/download');
$rq->saveToFile($file->file);
$rq->on('response', function($response) use($file) {
  pn('Response3: '.file_get_contents($file->file));
  fclose($file->handle);
});

// Send a file.
// Client->request() is used here as there is no PUT() method yet.
// Client->GET() and Client->POST() are wrappers for Client->request().
$file = tmpf('tmpfile2');
$rq = $client->request(new HTTP\Request('PUT','/upload'));
$rq->readFromFile($file->file);
$rq->on('response', function($response) use($file) {
  pn('Response4: '.$response->getBody());
  fclose($file->handle);
});

// A proxy
//
// Server will use Client to request an url and forward client response,
// body is directly forwarded through a stream.
//
$httpd->route('/proxy', function($rq) {
  $headers = ['User-Agent'=>'ShDProxyClient/1.0'];
  $client = new HTTP\Client('http://127.0.0.1:23480/hello',$headers);
  $promise = new HTTP\Promise;

  $req = $client->GET();
  $req->on('response-headers', function($response) use($promise) {
    if((int)$response->getHeader('Content-Length')) {
      $response->setBody(new Stream\ThroughStream);
    }
    $promise->resolve($response);
  });

  return $promise;
});

$rq = $client->GET('/proxy');
$rq->on('response', function($response) {
  pn('Response5: '.$response->getHeader('Server').': '.$response->getBody());
});

// Client methods
//
// Set or get parts of the url used in constructor
//   scheme(?string $p=null): string
//   tls(?bool $p=null): bool
//   host(?string $p=null): string
//   port(?int $p=null): int
//   path(?string $p=null): string
//
// Connect timeout (default 5s)
//   timeout(?int $p=null): int
//
// Pause or resume client, pausing will not stop a running request,
// it will prevent pending requests from starting.
// Client will auto pause on connect failure.
//   pause(),
//   paused(): bool
//   resume()
//
// Abort connection attempt, stop running request and clear all pending requests
//   abort()
//
// Get pending requests count
//   pending(): int
//
// Is there an active request
//   active(): bool
//
// Add requests
//   request(Request $request): ClientRequest
//   GET(string $path='', array $headers=[]): ClientRequest
//   POST($body, string $path='', array $headers=[]): ClientRequest
//
// First added request will start immediately,
// if this is not desired, call Client->pause() before adding request.
//
//
// Client events
//
// Connection failed, host not found or timeout
// Client will be paused
//   error-connect
//     args: Exception $e
//
// New connection
//   open
//
// Close connection
//   close
//
// ClientRequest methods
//
// Stop request if running, remove it from queue if not.
// The next request will start, call Client->pause() before abort()
//   abort()
//
// Get the decorated request
//   getRequest(): Request
//
// Save response body to file
//   saveToFile(string $filename): ClientRequest
//
// Send a file as request body
//   readFromFile(string $filename, string $contentType=''): ClientRequest
//
//
// ClientRequest events
//
// Starting request
//   request
//     args: Request $request
//
// Request headers sent
//   request-headers
//     args: Request $request
//
// Responce headers received
//   response-headers
//     args: Response $response, Request $request
//
// Response data
// If body was not set to a stream (on 'response-headers')
// and connection is upgraded
//   response-data
//     args: string $data, Response $response, Request $request
//
// Chunked transfer encoding, new chunk
// $len - length
// $ext - extension
// $cnt - counter
//   response-chunk
//     args: int $len, string $ext, int $cnt, Response $response, Request $request
//
// Chunked transfer encoding, chunk data, may be in pieces,
// First, 'response-chunk' is emitted, then one or more 'response-chunk-data'
// $cnt of 'response-chunk-data' will be the same as in the preceding 'response-chunk'
// $len is remaining length to be received, if 0, this is the last piece
//   response-chunk-data
//     args: string $data, int $len, int $cnt, Response $response, Request $request
//
// Response received with body
// Connection is cleaned for next request or closed
//   response
//     args: Response $response, Request $request
//
// Error, followed by 'close'
//   error
//     args: string $msg
//
// Connection closed, followed by 'close' event on Client
//   close
//     args: Request $request
//
