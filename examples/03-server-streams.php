<?php

// include this file or add your autoloader


use SharkyDog\HTTP;
use React\Stream;

// AI powered debugger
function pn($d) {
  print "*** Ex.03: ".print_r($d,true)."\n";
}

// Helper function to create and fill a temp file
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

HTTP\Log::level(99);

$httpd = new HTTP\Server;
$httpd->listen('0.0.0.0', 23480);
$httpd->route('/favicon.ico', 410);

// Streams provide non-blocking, event-driven way of handling incoming and outgoing data.
// see react/stream, https://github.com/reactphp/stream/tree/1.x
//
// This example will show how to use them as request and response bodies.


// Request body
//
// A stream can be set as request body in
//   Handler->onHeaders()
//   and for callbacks when second parameter is true ( function($request,$onHeaders){} )
//
// The stream must implement
//  React\Stream\WritableStreamInterface
//
// After entire request body has been written to the stream,
// the server will end the stream and continue to Handler->onRequest().
//
// On upgraded connections (request header Upgrade, handler responds with 101 response)
// Handler->onRequest() will be called before any data was written to the stream and will not close it.

// Save request body (a POST or PUT request) to a file
//
$httpd->route('/upload1', function($request,$onHeaders) {
  // first call in onHeaders(), if response is returned, connection is closed
  if(!is_dir('./tmp')) {
    return new HTTP\Response(503, 'Upload directory not found');
  }

  if($onHeaders) {
    // save filename in request user storage to be used in onRequest()
    $request->attr->filename = './tmp/upload_'.round(microtime(true),3).'.txt';

    $fh = fopen($request->attr->filename,'wb');
    $stream = new Stream\WritableResourceStream($fh);

    // the key part
    $request->setBody($stream);

    // continue
    // server will save incoming data to the file
    // and call onRequest() when finished
    return null;
  }

  if(!is_file($request->attr->filename)) {
    return new HTTP\Response(500, 'Upload failed');
  }

  // second call in onRequest()
  $size  = filesize($request->attr->filename);

  // sharkydog/logger package provides a nice function for printing sizes
  $sizeHR = HTTP\Log::loggerLoaded() ? \SharkyDog\Log\Logger::bytesHR($size) : $size." bytes";

  $resp  = "File saved: ".$request->attr->filename;
  $resp .= "\nSize: ".$sizeHR;
  $resp .= "\nContents: ".file_get_contents($request->attr->filename);

  return $resp;
});

// This is roughly equivalent to using a Handler with onData()
//
$httpd->route('/upload2', function($request,$onHeaders) {
  if($onHeaders) {
    $request->attr->buffer = '';

    $stream = new Stream\ThroughStream;
    $stream->on('data', function($data) use($request) {
      $request->attr->buffer .= $data;
    });

    $request->setBody($stream);
    return null;
  }

  return 'Uploaded: '.$request->attr->buffer;
});

// FormDataParser
//
// Parse multipart/form-data
//
$httpd->route('/formdata', function($request,$onHeaders) {
  static $error = null;

  if($onHeaders) {
    // if $tmpDir is null, uploaded files will be ignored
    // text fields will still be accepted
    $tmpDir = ($tmpDir = './tmp') && is_dir($tmpDir) ? $tmpDir : null;

    // FormDataParser::request()
    // will set the stream to the request
    // or will return null
    // if request content type is not "multipart/form-data"
    $parser = HTTP\Helpers\FormDataParser::request($request, $tmpDir);

    // if you only want to accept the request if a multipart form is submited
    // return an error
    if($parser === null) {
      return new HTTP\Response(400, 'A multipart/form-data POST is required');
    }

    // if there is an error, parser will close and request will continue to onRequest()
    $parser->on('error', function($msg) use(&$error) {
      $error = $msg;
    });

    // By default FormDataParser will put form fields in $request->POST.
    // Field name as key and field data as value.
    //
    // If the field is an uploaded file, the field data will be an array:
    //  [
    //    'file' => './tmp/tmpfile_1_filename.txt'
    //    'name' => 'filename.txt'
    //    'type' => 'text/plain'
    //  ]
    //
    // This can be changed in the "field" event
    // $name is the field name
    // $type is the mime type
    // $file is the basename of the uploaded file,
    //   not the name with path where it would be saved
    //
    // Data size is unknown, HTTP specs do not define a requirement
    // to put field length in the multipart message.
    // Content-Length request header has the size of the entire body.
    //
    $parser->on('field', function($name,$type,$file) use($parser,$request) {
      // keep the default, default is always fine :)
      return;

      // ignore this field
      $parser->outIgnore();

      // append data to a variable, passed by reference
      // variable must be defined and it must be a string
      $request->POST[$name] = '';
      $parser->outVar($request->POST[$name]);

      // receive data chunks in a callback
      $parser->outCallback(function($data) use($request,$name) {
        $request->POST[$name]  = $request->POST[$name] ?? '';
        $request->POST[$name] .= $data;
      });
    });

    // Other useful events:

    // data-in
    // raw data chunks as received by the server with the multipart encoding
    $parser->on('data-in', function($data) {
      pn('<<< data-in <<<'.$data.'>>>');
    });

    // data-out
    // data chunks without the multipart encoding
    $parser->on('data-out', function($data) {
      pn('<<< data-out <<<'.$data.'>>>');
    });

    return null;
  }

  if($error) {
    return new HTTP\Response(400, $error);
  }

  return print_r($request->POST, true);
});

// FormDataParser uses MultipartBuffer to parse the multipart message.
// It can be used as a generic parser, but that will not be explained here.


// Response body
//
// Send a React\Stream\ReadableStreamInterface as response body.
//
$httpd->route('/tmpfile1', function($request) {
  // temporary file
  if(($file = tmpf('Hello there on /tmpfile1')) === false) {
    return new HTTP\Response(500, 'tmpfile() failed');
  }

  $stream = new Stream\ReadableResourceStream($file->handle);
  $response = new HTTP\Response;
  $response->setBody($stream);
  $response->addHeader('Content-Length', filesize($file->file));

  return $response;
});

// If a static file, better use FileResponse
//
$httpd->route('/tmpfile2', function($request) {
  // temporary file
  if(($file = tmpf('Hello there on /tmpfile2')) === false) {
    return new HTTP\Response(500, 'tmpfile() failed');
  }
  return new HTTP\FileResponse($file->file, $request);
});

// ChunkedStream
//
// useful when content size is unknown
// works only with HTTP/1.1 clients
//
$httpd->route('/chunked', function($request) {
  // check if request is HTTP/1.1
  if($request->getVer() != '1.1') {
    return new HTTP\Response(505, 'Can\'t send this response to HTTP/1.0 client');
  }

  $chunks = ['chunk1','chunk2','chunk3'];
  $stream = new HTTP\Helpers\ChunkedStream;

  // force a 10 bytes minimum chunk length
  // otherwise every write() will be one chunk
  //$stream = new HTTP\Helpers\ChunkedStream(10);

  $response = new HTTP\Response;
  $response->setBody($stream);
  $response->addHeader('Transfer-Encoding', 'chunked');

  // write a chunk every second
  new HTTP\Interval(1, function($timer) use($stream,&$chunks) {
    $chunk = array_shift($chunks);

    if($chunk === null) {
      $stream->end();
      $timer->cancel();
      return;
    }

    $stream->write($chunk);
  });

  // chunk extensions? why not
  // $ext is the current extension for the current chunk if set
  $stream->on('chunk', function($chunk,$ext) use($stream) {
    $stream->extension('len='.strlen($chunk));
  });

  // trailers?
  // should check if request has "TE: trailers" header
  $stream->on('chunk', function($chunk,$ext) use($stream) {
    static $counter = 0;

    // empty chunk = end chunk
    if(strlen($chunk)) {
      $counter++;
      return;
    }

    $stream->trailers([
      'X-Custom-Chunks' => $counter
    ]);
  });
  $response->addHeaderLine('Trailer', 'X-Custom-Chunks');

  // see what is happening on the output
  $stream->on('data', function($data) {
    pn('<<<'.$data.'>>>');
  });

  return $response;
});

// Other "special" streams are
// HTTP\Helpers\RangeResourceStream,
// HTTP\Helpers\ByteRangeStream,
// HTTP\Helpers\MultipartStream
//
// They will not be covered in detail
// as they are auto included by other classes
// and their usefulness is limited outside that.
//
// RangeResourceStream and ByteRangeStream
// are used by FileResponse, File and Dir handlers
// to support ranged requests.
//
// MultipartStream is used by ByteRangeStream
// for requests with multiple ranges.
