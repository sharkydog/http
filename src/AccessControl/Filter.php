<?php
namespace SharkyDog\HTTP\AccessControl;
use SharkyDog\HTTP;
use SharkyDog\HTTP\Log;

class Filter extends HTTP\Filter {
  private $_rules = [];
  private $_ruleFlags = [];

  public function rule(?Rule $rule=null): Rule {
    if(!$rule) $rule = new Rule;

    $this->_rules[] = $rule;
    $key = array_key_last($this->_rules);

    $rule->manifestor(function($flags) use($key) {
      $this->_onRuleFlags($key, $flags);
    });

    return $rule;
  }

  public function allow(string ...$args): Rule {
    $rule = $this->rule();
    foreach($args as $arg) $this->_addStrArg($rule, $arg);
    return $rule;
  }

  public function deny(?HTTP\Response $response, string ...$args): Rule {
    $rule = $this->rule()->deny($response);
    foreach($args as $arg) $this->_addStrArg($rule, $arg);
    return $rule;
  }

  public function auth(Auth $auth, string ...$args): Rule {
    $rule = $this->rule()->auth($auth);
    foreach($args as $arg) $this->_addStrArg($rule, $arg);
    return $rule;
  }

  private function _addStrArg($rule, $arg) {
    if(preg_match('/^([\d\.]+)(?:\/(\d+))?$/', $arg)) {
      $rule->IPv4($arg);
    }
    else if($arg[0] == '/') {
      $rule->route($arg);
    }
  }

  private function _onRuleFlags($key, $flags) {
    $this->_ruleFlags[$key] = $flags;
    Log::debug('ACL: Rule#'.$key.': flags: '.$flags, 'http','filter','acl');
  }

  public function onConnOpen(HTTP\ServerConnection $conn): ?HTTP\Response {
    foreach($this->_rules as $key => $rule) {
      $flags = $this->_ruleFlags[$key];

      if(($flags & (Rule::IPV4 | Rule::DENY)) != $flags) {
        return null;
      }

      if(!$rule->matchIPv4($conn->remoteAddr)) {
        continue;
      }

      Log::debug('ACL: Rule#'.$key.': Matched in onConnOpen', 'http','filter','acl');

      if($flags & Rule::DENY) {
        if($resp = $rule->getDenyResponse()) {
          Log::debug('ACL: Rule#'.$key.': Deny: Response', 'http','filter','acl');
          return clone $resp;
        } else {
          Log::debug('ACL: Rule#'.$key.': Deny: Close', 'http','filter','acl');
          $conn->close();
          return null;
        }
      }

      Log::debug('ACL: Rule#'.$key.': Allow', 'http','filter','acl');

      $conn->attr->aclPass = true;
      return null;
    }

    return null;
  }

  public function onReqRoute(HTTP\ServerConnection $conn, HTTP\ServerRequest $request, $response): ?HTTP\Response {
    if($conn->attr->aclPass ?? false) {
      return null;
    }

    $connRulesPassed = false;

    foreach($this->_rules as $key => $rule) {
      $flags = $this->_ruleFlags[$key];

      if(!$connRulesPassed && ($flags & (Rule::IPV4 | Rule::DENY)) == $flags) {
        continue;
      }
      $connRulesPassed = true;

      if(($flags & Rule::IPV4) && !$rule->matchIPv4($conn->remoteAddr)) {
        continue;
      }
      if(($flags & Rule::ROUTE) && !$rule->matchRoute($request->routePath)) {
        continue;
      }

      Log::debug('ACL: Rule#'.$key.': Matched in onReqRoute', 'http','filter','acl');

      if(!($flags & Rule::AUTH)) {
        if(!($flags & Rule::DENY)) {
          Log::debug('ACL: Rule#'.$key.': Allow', 'http','filter','acl');
          return null;
        }
        if($resp = $rule->getDenyResponse()) {
          Log::debug('ACL: Rule#'.$key.': Deny: Response', 'http','filter','acl');
          return clone $resp;
        } else {
          Log::debug('ACL: Rule#'.$key.': Deny: Close', 'http','filter','acl');
          $conn->close();
          return null;
        }
      }

      if($rule->matchAuth($request, ($resp = new HTTP\Response))) {
        Log::debug('ACL: Rule#'.$key.': Auth: Authorized', 'http','filter','acl','auth');
        return null;
      }

      if(!$request->getHeader('Authorization') || !($flags & Rule::DENY)) {
        Log::debug('ACL: Rule#'.$key.': Auth: Response 401 Unauthorized', 'http','filter','acl','auth');
        return $resp;
      }

      if($resp = $rule->getDenyResponse()) {
        Log::debug('ACL: Rule#'.$key.': Deny: Response', 'http','filter','acl');
        return clone $resp;
      } else {
        Log::debug('ACL: Rule#'.$key.': Deny: Close', 'http','filter','acl');
        $conn->close();
        return null;
      }
    }

    return null;
  }
}
