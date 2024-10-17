<?php
namespace SharkyDog\HTTP;

abstract class Filter {
  public function onConnOpen(ServerConnection $conn): ?Response {
    return null;
  }
  public function onConnData(ServerConnection $conn, string $data): ?Response {
    return null;
  }
  public function onReqHeaders(ServerConnection $conn, ServerRequest $request): ?Response {
    return null;
  }
  public function onReqRoute(ServerConnection $conn, ServerRequest $request, $response): ?Response {
    return null;
  }
  public function afterReqHeaders(ServerConnection $conn, ServerRequest $request, $response): ?Response {
    return null;
  }
  public function onRequest(ServerConnection $conn, ServerRequest $request): ?Response {
    return null;
  }
  public function onReqData(ServerConnection $conn, ServerRequest $request, string $data): ?Response {
    return null;
  }
  public function onResPromise(ServerConnection $conn, ?ServerRequest $request, Promise $promise): ?Response {
    return null;
  }
  public function onResponse(ServerConnection $conn, ?ServerRequest $request, Response $response, $body, bool $ftRes=false): void {
  }
  public function afterResHeaders(ServerConnection $conn, ?ServerRequest $request, Response $response, $body, bool $ftRes=false): void {
  }
  public function onResEnd(ServerConnection $conn, ?ServerRequest $request, Response $response, bool $close, bool $ftRes=false): void {
  }
  public function onConnClose(ServerConnection $conn, ?ServerRequest $request, ?Response $response): void {
  }
}
