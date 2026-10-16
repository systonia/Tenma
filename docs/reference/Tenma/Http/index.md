---
title: "Tenma\\Http"
description: "The classes, interfaces, traits and enums in Tenma\\Http."
order: 200
generated: true
---

## Types

- [`ConnectionClosed`](ConnectionClosed.md) · final class: The client is gone: streams and WebSockets throw this on the next write.
- [`EventStream`](EventStream.md) · final class: Server-Sent Events on a stream.
- [`FetchResponse`](FetchResponse.md) · final class: Response from fetch().
- [`Request`](Request.md) · final class: An HTTP request in fiber mode, the argument of the handler of Tenma::serve().
- [`Response`](Response.md) · final class: A complete response with status, headers and body.
- [`Stream`](Stream.md) · final class: Body of a streaming response.
- [`StreamedResponse`](StreamedResponse.md) · final class: A response whose body a writer produces while it is sent, from Response::stream() and Response::events().
- [`UploadedFile`](UploadedFile.md) · final class: An uploaded file, entirely in memory (up to --max-body).
- [`WebSocket`](WebSocket.md) · final class: An accepted WebSocket connection, passed to the handler of Response::websocket().
- [`WebSocketResponse`](WebSocketResponse.md) · final class: Accepts the request as a WebSocket and runs the handler with the connection, from Response::websocket().
