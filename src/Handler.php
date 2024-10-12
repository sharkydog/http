<?php
namespace SharkyDog\HTTP;

abstract class Handler {
  public function onHeaders(ServerRequest $request) {
    return null;
  }

  public function onRequest(ServerRequest $request) {
    return 500;
  }

  public function onData(ServerRequest $request, string $data) {
  }

  public function onResponse(ServerRequest $request, Response $response) {
  }

  public function onResponseHeaders(ServerRequest $request, Response $response) {
  }

  public function onEnd(ServerRequest $request, ?Response $response) {
  }
}
