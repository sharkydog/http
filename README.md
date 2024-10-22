# HTTP/Websocket server and client
A small HTTP server and client with websockets, intended for services in a local network, based on [ReactPHP](https://reactphp.org/) and a fork of [ratchet/rfc6455](https://github.com/ratchetphp/RFC6455).

> ## :warning: Do NOT expose this to the Wild Wild Web!!!
> ### It is NOT and probably never will be secure enough.
> ### A simple DoS attack will bring it down, maybe the host machine too.
> Some level of protection may be created through filters. A "contrack" filter is in "an idea" stage, to provide facility for other filters and handlers to track connections, set timeouts and such.

#
Documentation is not yet written, there is a lot to write and will land in the [Wiki](https://github.com/sharkydog/http/wiki) eventually.

Until then, there is a [quick start example](https://github.com/sharkydog/http/blob/main/examples/01-server-quickstart.php) for the server.
* new (13.10.24): [server-handlers and promise](https://github.com/sharkydog/http/blob/main/examples/02-server-handlers.php)
* new (16.10.24): [streams](https://github.com/sharkydog/http/blob/main/examples/03-server-streams.php)
* new (17.10.24): [filters](https://github.com/sharkydog/http/blob/main/examples/04-server-filters.php)
* new (18.10.24): [access control](https://github.com/sharkydog/http/blob/main/examples/05-server-access-control.php)
* new (20.10.24): [client](https://github.com/sharkydog/http/blob/main/examples/06-client.php)
* new (22.10.24): websocket [server](https://github.com/sharkydog/http/blob/main/examples/07-websocket-server.php), [client](https://github.com/sharkydog/http/blob/main/examples/08-websocket-client.php)

Examples should be looked in the `main` branch, tagged releases may not have them up to date - [main/examples](https://github.com/sharkydog/http/tree/main/examples).

And there is always the option to go for a treasure hunt through the source.
And I will be happy to answer questions and provide assistance.

In short, some key features:
- HTTP/1.1, Keep-Alive, Chunked transfer encoding
- simple routes - error code, text response, callback, static files or custom handler
- Streams for request and response bodies
- Multipart stream and parser
- Byte range stream
- Promised response
- Connection, request, response filters
- Access control, based on routes, IPv4 address/cidr and basic authorization
- Websocket protocol handler (server)
- HTTP client
- Websocket client

Most of these however are not enabled by default and will need to be "used" in handlers. See examples.
