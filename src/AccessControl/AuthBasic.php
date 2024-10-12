<?php
namespace SharkyDog\HTTP\AccessControl;

class AuthBasic extends Auth {
  private $_realm;
  private $_users;

  public function __construct(string $realm, array $users) {
    $this->_realm = preg_replace('/[\'\"]+/', '', $realm);
    $this->_users = $users;
  }

  public function getAuthHeader(): string {
    return 'Basic realm="'.$this->_realm.'"';
  }

  public function matchAuth(string $authHdr, &$matchedUser=null): bool {
    if(!preg_match('/^basic\s+([a-z0-9\/\+\=]+)$/i', $authHdr, $m)) {
      return false;
    }
    if(!($auth = base64_decode($m[1]))) {
      return false;
    }

    if(!preg_match('/^([^\:]+)\:(.+)$/i', $auth, $m)) {
      return false;
    }
    if(!($pass = $this->_users[$m[1]]??'') || $pass != $m[2]) {
      return false;
    }

    $matchedUser = $m[1];
    return true;
  }
}
