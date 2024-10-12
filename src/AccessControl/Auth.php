<?php
namespace SharkyDog\HTTP\AccessControl;

abstract class Auth {
  abstract public function getAuthHeader(): string;
  abstract public function matchAuth(string $authHdr, &$matchedUser=null): bool;
}
