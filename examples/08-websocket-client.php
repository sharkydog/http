<?php

// include this file or add your autoloader


use SharkyDog\HTTP;
use SharkyDog\HTTP\WebSocket;
use React\Stream;
use React\EventLoop\Loop;

function pn($d) {
  print "*** Ex.08: ".print_r($d,true)."\n";
}

//HTTP\Log::level(99);

// The websocket client class (SharkyDog\HTTP\WebSocket\Client)
// is a ready to use client that handles websocket protocol messaging and handshake
// and emits several events using sharkydog/private-emitter (Evenement\EventEmitter compatible).

// Simple client
//
$echo = new WebSocket\Client('ws://127.0.0.1:23480/ws/echo');
$echo->connect();
$echo->reconnect(5);

$echo->on('open', function() use($echo) {
  pn('Echo: open');
  pn('Echo: sending hello world');
  $echo->send('hello world');
});
$echo->on('message', function($msg) use($echo) {
  print "Echo: ".$msg."\n";
  $echo->end();
});

//
// Constructor
// wss:// and https:// schemes will set TLS on
// $headers is and array like ['name'=>'value'] to set additional headers to http request
//   public function __construct(string $url, array $headers=[])
//
// Setup pings, same as for server, see 07-websocket-server.php example
// default here however is to send pings every 60s, $pingForced = false
// set $pingInterval to 0 to disable
//   public function pingInterval(int $pingInterval, bool $pingForced=false)
//
// Reconnect when connection is closed
// using end() and close() with default arguments will not trigger reconnect
// $interval is the time in seconds to wait before a connect attempt
//   public function reconnect(int $interval)
//
// Connect
// timeout is remembered, when called with timeout 0,
// last used timeout is applied, default 5s
//   public function connect(int $timeout=0)
//
// Is connected
//   public function connected(): bool
//
// Is connected, connecting or reconnecting
//   public function running(): bool
//
// Send a message
// Can be a string or Ratchet\RFC6455\Messaging\Frame
//   public function send($data)
//
// End connection, allow write buffers to flush and then close connection
//   end(bool $reconnect=false, ?int $code = Frame::CLOSE_NORMAL)
//
// Close connection immediately
//   public function close(bool $reconnect=false)
//
//
// Events
//
// Connected
//   open
//
// New message
//   message
//     args: string $msg
//
// Close connection
// if $reconnect == true, client will try to reconnect
// if false, a stop event will follow
//   close
//     args: bool $reconnect
//
// Stop, connection closed and no reconnect
//   stop
//
// Reconnect in $interval seconds
// reconnecting can be stopped by calling reconnect(0)
//   reconnect
//     args: int $interval
//
// Handshake request
// use this event to add dynamic headers to the upgrade request
//   request
//     args: SharkyDog\HTTP\Request $request
//
// Handshake response
// this is the http 101 (switching protocols) response from the server
//   response
//     args: SharkyDog\HTTP\Response $response, SharkyDog\HTTP\Request $request
//
// Connect error
// will be followed by stop or reconnect event
// can also happen on connection close before handshake is completed
//   error-connect
//     args: \Exception $error
//
// Error response
// any response other than http 101
// followed by error-connect ( 'Connection rejected' )
// reconnect will not be triggered
//   error-response
//     args: SharkyDog\HTTP\Response $response, SharkyDog\HTTP\Request $request
//

//
// SharkyDog\HTTP\WebSocket\Client class can not be extended,
// a specialized client around some specific service
// will have to be implemented with event listeners.
//
// The SharkyDog\HTTP\Helpers\WsClientDecorator class comes to aid.
// It is an abstract class that creates a new websocket client
// and offers the ability to capture events and change them (using sharkydog/private-emitter).
//
// The constructor is the same as in the websocket client
//   public function __construct(string $url, array $headers=[])
// It creates a client and makes it available to child class through
//   protected $ws property
//
// All method calls will be forwarded to the client.
// All events from the client will be forwarded to the child class.
//
// Events can be captured with a protected or public method
//   function _event_eventname(...$args)
// A captured event will not execute listeners unless emitted again
// in the capturing method with _emit() from PrivateEmitterTrait
//   protected function _emit($event, $args=[])
// The event can have different arguments and new events can be emitted.
//

//
// The chat client demonstrates the use of WsClientDecorator
//
// It overrides constructor and sets a hardcoded url,
// implements max reconnect attempts
// and adds the current attempt counter
// as second argument to the reconnect event.
//
class Chat extends HTTP\Helpers\WsClientDecorator {
  private $_reconnectAttempts;
  private $_reconnectAttempt = 0;

  public function __construct(int $reconnectAttempts=2) {
    parent::__construct('ws://127.0.0.1:23480/ws/chat');
    // clamp $reconnectAttempts as positive or disabled
    $this->_reconnectAttempts = max(0,$reconnectAttempts);
    // attempt after 5 seconds
    $this->reconnect(5);
  }

  // capture and re-emit open event
  // reset reconnect attempts and interval on successful connection
  protected function _event_open() {
    $this->_reconnectAttempt = 0;
    $this->reconnect(5);
    $this->_emit('open');
  }

  // capture and silence reconnect event on max attempts reached
  // disable reconnect, client will emit stop event after this
  // or change event and re-emit
  protected function _event_reconnect($interval) {
    if(++$this->_reconnectAttempt > $this->_reconnectAttempts) {
      pn('Chat: max reconnect attempts ('.$this->_reconnectAttempts.') reached');
      $this->reconnect(0);
      return;
    }
    $this->_emit('reconnect', [$interval,$this->_reconnectAttempt]);
  }
}

$chat = new Chat;
// remeber client will not auto-connect initially
$chat->connect();

$chat->on('message', function($msg) {
  print "Chat: ".$msg."\n";
});
$chat->on('open', function() {
  pn('Chat: open');
  pn('Chat: type /exit to close client');
});
$chat->on('close', function($reconnect) {
  pn('Chat: close, reconnect: '.($reconnect ? 'yes': 'no'));
});
$chat->on('reconnect', function($interval,$attempt) {
  pn('Chat: reconnect ('.$attempt.') after '.$interval.'s');
});

// make this example interactive
// who needs readline when we have streams
$stdin = new Stream\ReadableResourceStream(STDIN);
$stdin->on('data', function($data) use($chat) {
  $data = trim($data);

  if($data == '/exit') {
    $chat->end();
    return;
  }
  if($data == '/stop') {
    Loop::stop();
    return;
  }

  $chat->send($data);
});

pn('type /stop to stop Loop');
