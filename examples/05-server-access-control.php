<?php

// include this file or add your autoloader


use SharkyDog\HTTP;

function pn($d) {
  print "*** Ex.05: ".print_r($d,true)."\n";
}

HTTP\Log::level(99);

$httpd = new HTTP\Server;
$httpd->listen('0.0.0.0', 23480);
$httpd->route('/favicon.ico', 410);

$httpd->route('/hellos/world', 'Hello world');
$httpd->route('/hellos/there', 'Hello there');
$httpd->route('/stuff/stuff1', 'Stuff1');
$httpd->route('/stuff/stuff2', 'Stuff2');
$httpd->route('/yar', 'Yet Another Route');


// The Access Control filter allows or denies requests
// based on IPv4, route and authorization rules.
//
// If no rules are added, all requests wil be allowed.
// Rules are matched from first to last,
// if a rule matches the request, following rules are not checked.
// Request denied if the matched rule is a deny rule (see bellow),
// otherwise request is allowed.

use SharkyDog\HTTP\AccessControl as ACL;

$acl = new ACL\Filter;

// Add the filter
// or add it at the end so we can replace it
//$httpd->filter($acl);

// Create a new rule
$rule = $acl->rule();

// or
//$rule = new Rule;
//$acl->rule($rule);

// IPv4 address/cidr, default CIDR /32
// match if remote ip is 192.168.1.1 or in 192.168.2.0/24 network
$rule->IPv4('192.168.1.1');
$rule->IPv4('192.168.2.0/24');

// Route
// these will be matched to the routes added with Server->route()
// not to the request path
// the following will match if request is for
// /stuff/stuff1, /stuff/stuff2 or /yar
$rule->route('/stuff');
$rule->route('/yar');

// Deny
// make this a deny rule, connection will be closed
// if deny() is not called, it will be an allow rule
$rule->deny();
// Deny with response, instead of rude "connection reset"
// only http status code and HTTP\Response object are allowed
$rule->deny(403);

// Summary for Rule#1:
// Deny requests with 403 response
// from 192.168.1.1 and 192.168.2.0/24 network
// on /stuff/stuff1, /stuff/stuff2 and /yar routes

// Methods can be chained
// Drop connection from 192.168.1.2 on /hellos/world
// Rule#2
$acl->rule()->IPv4('192.168.1.2')->route('/hellos/world')->deny();

// Allow anyone on /hellos/there
// Rule#3
$acl->rule()->route('/hellos/there');

// Also allow favicon and not found route (no route matched by server)
// Rule#4
$acl->rule()->route('/favicon.ico')->route(null);

// Deny everything not matched by previous rules
// Rule#5
$acl->rule()->deny(403);

// After Rule#5 no other rules will be checked

// Shorthand for Rule#1
$acl->deny(403, '192.168.1.1', '192.168.2.0/24', '/stuff', '/yar');

// Shorthand for Rule#2
// deny with connection close
$acl->deny(null, '192.168.1.2', '/hellos/world');

// Shorthand for Rule#3
$acl->allow('/hellos/there');

// Shorthand for Rule#4
// not found route has no shorthand
$acl->allow('/favicon.ico')->route(null);

// Shorthand for Rule#5
$acl->deny(403);

// Rules that only match IP address
// will be executed in Filter->onConnOpen()
// others in Filter->onReqRoute()
// This is important to other filters

// Do not add the filter yet
//$httpd->filter($acl);

// Start over
$acl = new ACL\Filter;

// Can also be written like this
//$acl = $httpd->filter(new ACL\Filter);
// or if you only have one rule
//$httpd->filter(new ACL\Filter)->deny(null,'192.168.10.0/24');

$rule = $acl->rule();

// Basic authorization
$users = [
  'user1' => 'pass',
  'user2' => 'pass'
];
$auth = new ACL\AuthBasic('My Site realm', $users);

// Ask for user and password only
// on /hellos/there, coming from 192.168.2.0/24 network
$rule->auth($auth);
$rule->route('/hellos/there');
$rule->IPv4('192.168.2.0/24');

// This will have effect if wrong user or password were entered
// without it, 401 response is returned
// so, a rule with auth will always ask for user/pass if route and ip match
// adding deny will only change how wrong credentials are handled
//$rule->deny(403);

// Shorthand rule
$acl->auth($auth, '/hellos/there', '192.168.2.0/24');

// Matched user name will be in
// $request->attr->authUser

// Add the filter to server
//$httpd->filter($acl);


// Start over
$acl = new ACL\Filter;

// Mark this point for the debug log
pn('Line: '.__LINE__);

// allow 192.168.1.1 - 192.168.1.62 without auth
// deny 192.168.1.129 - 192.168.1.254 everything
// allow favicon
// authorize 192.168.1.1 - 192.168.1.254
//   on /stuff/stuff1 and /stuff/stuff2
//   effective ip range: 192.168.1.65 - 192.168.1.126 (192.168.1.64/26)
// drop connection on everything else

$acl->allow('192.168.1.0/26');
$acl->deny(403, '192.168.1.128/25');
$acl->allow('/favicon.ico');
$acl->auth($auth, '192.168.1.0/24', '/stuff');
$acl->deny();

// Add the filter to server
$httpd->filter($acl);
