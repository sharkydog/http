<?php

// include this file or add your autoloader


use SharkyDog\HTTP;
use SharkyDog\HTTP\WebSocket;

function pn($d) {
  print "*** Ex.07: ".print_r($d,true)."\n";
}

//HTTP\Log::level(99);
$httpd = new HTTP\Server;
$httpd->listen('0.0.0.0', 23480);
$httpd->route('/favicon.ico', 410);


// A websocket handler must extend SharkyDog\HTTP\WebSocket\Handler
// This abstract class handles all websocket messaging and handshake (RFC6455)
// and defines three protected methods the child class should override
// to handle open, close and message events.
//
// A simple echo server, sends back what was received.
// Implements only wsMsg method
// as it doesn't need to know when a connection was opened or closed.
//
class WsEcho extends WebSocket\Handler {
  protected function wsMsg(WebSocket\Connection $conn, string $msg) {
    $conn->send('you said: '.$msg);
  }
}

$httpd->route('/ws/echo', new WsEcho);


//
// On new connection
//   protected function wsOpen(Connection $conn)
//
// On message
//   protected function wsMsg(Connection $conn, string $msg)
//
// On closed connection
//   protected function wsClose(Connection $conn)
//
// The Connection object (SharkyDog\HTTP\WebSocket\Connection)
// has a user storage object in $conn->attr (stdClass) just like
// ServerRequest and ServerConnection from the http server.
//
// Connection methods:
//  Send a message to the client
//  Can be a string or Ratchet\RFC6455\Messaging\Frame
//   public function send($data)
//
//  Close connection after sending all data
//   public function end(?int $code = Frame::CLOSE_NORMAL)
//
//  Close connection immediately,
//  use this when you don't care if the last message is delivered
//   public function close()
//
// The Connection object also decorates SharkyDog\HTTP\ServerRequest
// Properties from the http request and connection can be used,
// like GET, routePath, localHost, remoteAddr, etc.
//
//
// SharkyDog\HTTP\WebSocket\Handler has a constructor and one more method
//
//  Ping clients (default disabled)
//  Send ping frames to clients every $pingInterval seconds.
//  If a pong frame is not received within $pingInterval connection is closed.
//  $pingForced sets if pings should always be send,
//  if false and client sends a ping, the server responds with pong,
//  but also treats the client ping as if client pong was received and skips the next ping.
//  Can be set with constructor or later using pingInterval()
//   public function __construct(int $pingInterval=0, bool $pingForced=false)
//   public function pingInterval(int $pingInterval, bool $pingForced=false)
//


// Whenever there is a tutorial about sockets,
// examples usually have an echo app or a chat.
// So, a chat handler.
//
// Clients
// 08-websocket-client.php example
// html/js: https://gist.github.com/sharkydog/b63934dd990b0c80013764bbd19a14df
//
class WsChat extends WebSocket\Handler {
  //  peers (connections) are tracked only when they set a nickname
  private $_peers = [];

  // send some wellcome messages and init user storage
  protected function wsOpen(WebSocket\Connection $conn) {
    $conn->attr->name = '';

    // properties from SharkyDog\HTTP\ServerConnection
    $conn->send('Hello '.$conn->remoteAddr.':'.$conn->remotePort);

    // from ServerRequest and ServerConnection
    $url = ($conn->TLS ? 'wss://': 'ws://').$conn->localHost.':'.$conn->localPort.$conn->routePath;
    $conn->send('Connected to '.$url);

    $conn->send('/name your_name to set name and chat with others');
    $conn->send('/quit to leave');
  }

  protected function wsMsg(WebSocket\Connection $conn, string $msg) {
    // messages starting with forward slash are commands
    if($msg[0] == '/') {
      if($msg == '/quit') {
        $conn->end();
        return;
      }

      if(strpos($msg,'/name') === 0) {
        $name = trim(substr($msg,5));

        if(!$name) {
          $conn->send('name is empty');
          return;
        }

        $namelc = strtolower($name);

        if(strpos($namelc,'server') !== false) {
          $conn->send('You are NOT the server!!!');
          $conn->end();
          return;
        }

        if(isset($this->_peers[$namelc])) {
          if($this->_peers[$namelc]->ID != $conn->ID) {
            $conn->send('name "'.$name.'" is taken');
            return;
          }

          if($name != $conn->attr->name) {
            $this->_sendToAll($conn->attr->name.' changed name to '.$name);
            $conn->attr->name = $name;
            return;
          }

          return;
        }

        $cname = $conn->attr->name;
        $cnamelc = strtolower($cname);

        $conn->attr->name = $name;
        $this->_peers[$namelc] = $conn;

        if(isset($this->_peers[$cnamelc])) {
          unset($this->_peers[$cnamelc]);
          $this->_sendToAll($cname.' changed name to '.$name);
          pn('peers: '.implode(',',array_keys($this->_peers)));
          return;
        }

        $this->_sendToAll($name.' joined the chat');

        $peers = array_map(fn($p)=>$p->attr->name, $this->_peers);
        unset($peers[$namelc]);

        if(!empty($peers)) {
          $conn->send('peers: '.implode(', ',$peers));
        }

        pn('peers: '.implode(',',array_keys($this->_peers)));
        return;
      }

      $conn->send('unknown command');
      return;
    }

    if(!$conn->attr->name) {
      return;
    }

    // not a command, send to all tracked connections (with nickname)
    $this->_sendToAll('['.$conn->attr->name.'] '.$msg, $conn);
  }

  // remove a tracked connection and notify others
  protected function wsClose(WebSocket\Connection $conn) {
    if(!($cname = $conn->attr->name)) {
      return;
    }

    unset($this->_peers[strtolower($cname)]);
    $this->_sendToAll($cname.' left the chat');
    pn('peers: '.implode(',',array_keys($this->_peers)));
  }

  private function _sendToAll($msg, $conn=null) {
    foreach($this->_peers as $peer) {
      if($conn && $conn->ID == $peer->ID) {
        continue;
      }
      $peer->send($msg);
    }
  }
}

$httpd->route('/ws/chat', new WsChat);
