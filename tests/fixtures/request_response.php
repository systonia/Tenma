<?php
// For tests/http.rs: cookies, forms, uploads and response helpers in fiber mode
use Tenma\Http\{Request, Response, UploadedFile};


Tenma::serve((int) getenv('PORT'), function (Request $request): Response {
    switch ($request->path) {
        case '/cookies':
            return Response::json([$request->cookies(), $request->cookie('sid')]);
        case '/form':
            $describe = fn (UploadedFile $file) => [$file->name, $file->type, $file->size(), $file->contents()];
            $files = array_map(fn ($entry) => is_array($entry) ? array_map($describe, $entry) : $describe($entry), $request->files());
            return Response::json(['fields' => $request->form(), 'files' => $files]);
        case '/move':
            $target = sys_get_temp_dir() . '/tenma-upload-' . getmypid();
            $request->files()['file']->moveTo($target);
            $moved = Tenma\Fs::readFile($target);
            Tenma\Fs::remove($target);
            return new Response($moved);
        case '/set-cookie':
            return Response::redirect('/target', 303)
                ->cookie('sid', 'a b;c', path: '/app')
                ->cookie('theme', 'dark', expires: 2000000000, secure: true, sameSite: 'Strict')
                ->header('x-two', 'one')
                ->header('x-two', 'two', append: true);
        case '/html':
            return Response::html('<h1>Hello</h1>', 201);
        default:
            return new Response("ok\n");
    }
});
