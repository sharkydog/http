<?php
namespace SharkyDog\HTTP\AccessControl;
use SharkyDog\HTTP;
use SharkyDog\HTTP\Log;

class Rule {
  const IPV4  = 1;
  const ROUTE = 2;
  const AUTH  = 4;
  const DENY  = 8;

  private $_manifestors = [];
  private $_flags = 0;

  private $_ipv4 = [];
  private $_route = [];
  private $_auth = [];
  private $_denyRes;

  public function __construct() {
  }

  public function manifestor(callable $manifestor): void {
    $this->_manifestors[] = $manifestor;
    $manifestor($this->_flags);
  }

  private function _flags($flags) {
    if($this->_flags == $flags) {
      return;
    }

    $this->_flags = $flags;

    foreach($this->_manifestors as $manifestor) {
      $manifestor($this->_flags);
    }
  }

  public function IPv4(string $addr): self {
    if(!preg_match('/^([\d\.]+)(?:\/(\d+))?$/', $addr, $m)) {
      throw new \Exception('Invalid IP address/cidr');
    }
    if(!($cidr = (int)($m[2] ?? 32)) || $cidr>32) {
      throw new \Exception('Invalid CIDR mask');
    }
    if(($addr = ip2long($m[1])) === false) {
      throw new \Exception('Invalid IP address');
    }

    if($addr && $cidr<32) {
      $shft = 32 - $cidr;
      $addr = $addr >> $shft;
    } else {
      $shft = 0;
    }

    $this->_ipv4[] = ['a'=>$addr,'s'=>$shft];
    $this->_flags($this->_flags | self::IPV4);

    if(Log::loggerLoaded()) {
      $netw = $addr << $shft;
      $bcst = long2ip($netw + ((2 ** $shft) - 1));
      $netw = long2ip($netw).'/'.$cidr;
      Log::debug('ACL: IPv4: '.$netw.', '.$bcst, 'http','filter','acl');
    }

    return $this;
  }

  public function matchIPv4(string $addr): bool {
    if(empty($this->_ipv4)) {
      return true;
    }

    if(($addr = ip2long($addr)) === false) {
      return false;
    }

    foreach($this->_ipv4 as $ip) {
      if(($addr >> $ip['s']) == $ip['a']) {
        Log::debug('ACL: IPv4: Matched '.long2ip($addr).' to '.long2ip($ip['a'] << $ip['s']).'/'.(32-$ip['s']), 'http','filter','acl');
        return true;
      }
    }

    return false;
  }

  public function route(?string $path): self {
    $this->_route[] = $path === null ? null : '/'.trim($path,'/').'/';
    $this->_flags($this->_flags | self::ROUTE);
    Log::debug('ACL: Route: '.($path === null ? 'NOT_FOUND' : '/'.trim($path,'/')), 'http','filter','acl');
    return $this;
  }

  public function matchRoute(?string $path): bool {
    if(empty($this->_route)) {
      return true;
    }

    $path = $path === null ? null : '/'.trim($path,'/').'/';

    foreach($this->_route as $route) {
      if($path === null && $route === null) {
        Log::debug('ACL: Route: Matched NOT_FOUND', 'http','filter','acl');
        return true;
      }
      if($path === null || $route === null) {
        continue;
      }
      if(strpos($path, $route) === 0) {
        Log::debug('ACL: Route: Matched '.$path.' to '.$route, 'http','filter','acl');
        return true;
      }
    }

    return false;
  }

  public function auth(Auth $auth): self {
    $this->_auth[] = $auth;
    $this->_flags($this->_flags | self::AUTH);
    Log::debug('ACL: Auth: '.$auth->getAuthHeader(), 'http','filter','acl');
    return $this;
  }

  public function matchAuth(HTTP\ServerRequest $request, HTTP\Response $response): bool {
    if(empty($this->_auth)) {
      return true;
    }

    if(!($authHdr = $request->getHeader('Authorization'))) {
      foreach($this->_auth as $scheme) {
        $response->addHeaderLine('WWW-Authenticate', $scheme->getAuthHeader());
      }

      Log::debug('ACL: Auth: Require: '.$response->getHeader('WWW-Authenticate'), 'http','filter','acl');
      Log::debug('ACL: Auth: Unauthorized', 'http','filter','acl');

      $response->setStatus(401);
      return false;
    }

    $schemes = [];

    foreach($this->_auth as $scheme) {
      if($scheme->matchAuth($authHdr, $matchedUser)) {
        $request->attr->authUser = $matchedUser;
        Log::debug('ACL: Auth: Authorized \''.(string)$matchedUser.'\' from \''.$scheme->getAuthHeader().'\'', 'http','filter','acl');
        return true;
      }
      $schemes[] = $scheme->getAuthHeader();
    }

    foreach($schemes as $scheme) {
      $response->addHeaderLine('WWW-Authenticate', $scheme);
    }

    Log::debug('ACL: Auth: Require: '.implode(', ', $schemes), 'http','filter','acl');
    Log::debug('ACL: Auth: Unauthorized', 'http','filter','acl');

    $response->setStatus(401);
    return false;
  }

  public function deny($response=null): self {
    $this->_flags($this->_flags | self::DENY);

    if($response) {
      if(is_int($response)) {
        $response = new HTTP\Response($response);
      } else if(!($response instanceOf HTTP\Response)) {
        $response = null;
      }
      $this->_denyRes = $response;
    }

    if(Log::loggerLoaded()) {
      if($this->_denyRes) {
        $denyResDbg = ': '.get_class($this->_denyRes).'('.$this->_denyRes->getStatus().')';
      } else {
        $denyResDbg = '';
      }
      Log::debug('ACL: Deny'.$denyResDbg, 'http','filter','acl');
    }

    return $this;
  }

  public function getDenyResponse(): ?HTTP\Response {
    return $this->_denyRes;
  }
}
