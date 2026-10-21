---
title: "Http"
description: "The classes, interfaces, traits and enums in Http."
order: 200
generated: true
---

## Types

- [`ClassicServer`](ClassicServer.md) · final class: An HTTP server for classic PHP code, for example a framework with its own front controller.
- [`Client`](Client.md) · final class: Outgoing HTTP requests, without blocking the server: only the Fiber waits.
- [`ClientResponse`](ClientResponse.md) · final class: The response of an Http\Client request: status, header lines and body.
- [`ConnectionClosed`](ConnectionClosed.md) · final class: The client is gone: streams and WebSockets throw this on the next write.
- [`EventStream`](EventStream.md) · final class: Server-Sent Events on a stream.
- [`Request`](Request.md) · final class: An HTTP request in fiber mode, the argument of the handler of an Http\Server.
- [`Response`](Response.md) · final class: A complete response with status, headers and body.
- [`Server`](Server.md) · final class: An HTTP server in fiber mode: every request runs in its own Fiber, and waiting (a query, a fetch, a sleep) suspends only that request.
- [`Stream`](Stream.md) · final class: Body of a streaming response.
- [`StreamedResponse`](StreamedResponse.md) · final class: A response whose body a writer produces while it is sent, from Response::stream() and Response::events().
- [`UploadedFile`](UploadedFile.md) · final class: An uploaded file, entirely in memory (up to --max-body).
- [`WebSocket`](WebSocket.md) · final class: An accepted WebSocket connection, passed to the handler of Response::websocket().
- [`WebSocketResponse`](WebSocketResponse.md) · final class: Accepts the request as a WebSocket and runs the handler with the connection, from Response::websocket().
