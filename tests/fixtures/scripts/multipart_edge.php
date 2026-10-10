<?php
// For tests/http.rs: edge cases of the multipart parser, without a server
use Tenma\Http\{Request, Response, UploadedFile};

$form = function (string $body, string $boundary = 'B'): Request {
    return new Request(['method' => 'POST', 'uri' => '/', 'headers' => ['content-type' => "multipart/form-data; boundary=$boundary"], 'body' => $body,
        'remote_addr' => '', 'remote_port' => 0, 'server_addr' => '', 'server_port' => 0]);
};
$names = fn (array $files) => array_map(fn ($file) => $file instanceof UploadedFile ? $file->name : array_map(fn ($inner) => $inner->name, $file), $files);

// Only LF instead of CRLF, as PHP accepts it too
echo json_encode($form("--B\nContent-Disposition: form-data; name=\"f\"\n\nX\n--B--\n")->form()), "\n";
// A ; and name= in the file name does not start a parameter; backslash paths become the name
$request = $form("--B\r\nContent-Disposition: form-data; filename=\"a;name=evil.txt\"; name=\"real\"\r\n\r\n1\r\n"
    . "--B\r\nContent-Disposition: form-data; name=\"path\"; filename=\"C:\\temp\\new\\x41.txt\"\r\n\r\n2\r\n"
    . "--B\r\nContent-Disposition: form-data; name=\"doc[a]\"; filename=\"d.txt\"\r\n\r\n3\r\n"
    . "--B\r\nContent-Disposition: form-data; name=\"x\"; filename=\"x1\"\r\n\r\n4\r\n"
    . "--B\r\nContent-Disposition: form-data; name=\"x[]\"; filename=\"x2\"\r\n\r\n5\r\n--B--\r\n");
echo json_encode($names($request->files())), "\n";
// A hostile body with very many parts fails early
try {
    $form(str_repeat("\r\n--B", 2_000_000))->form();
} catch (RuntimeException $error) {
    echo $error->getMessage(), ' ', memory_get_peak_usage() < 64 << 20 ? 'frugal' : 'wasteful', "\n";
}
// Headers and cookies: null bytes and attributes cannot be smuggled in
foreach ([fn () => Response::redirect("/x\0set-cookie: a=b"), fn () => (new Response())->cookie('a', 'b', path: '/; Domain=evil'), fn () => (new Response())->cookie('a', 'b', sameSite: 'Lax; Secure')] as $bad) {
    try {
        $bad();
        echo "let through\n";
    } catch (ValueError) {
        echo "rejected\n";
    }
}
echo json_encode((new Response())->cookie('s', 'v', expires: 0)->headers['set-cookie']), "\n";
