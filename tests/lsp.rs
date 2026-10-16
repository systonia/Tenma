//! Starts `tenma lsp` on the fixture project in tests/fixtures/lsp and speaks LSP to it.

use std::io::{BufRead, BufReader, Read, Write};
use std::process::{Child, ChildStdin, ChildStdout, Command, Stdio};

use serde_json::{Value, json};

struct Lsp {
    child: Child,
    stdin: ChildStdin,
    stdout: BufReader<ChildStdout>,
    next: u64,
}

impl Drop for Lsp {
    fn drop(&mut self) {
        let _ = self.child.kill();
    }
}

impl Lsp {
    /// A server for a workspace: the projects are the directories with a composer.json in it.
    fn start(root: &std::path::Path) -> Self {
        let mut child = Command::new(env!("CARGO_BIN_EXE_tenma")).arg("lsp").stdin(Stdio::piped()).stdout(Stdio::piped()).spawn().unwrap();
        let stdin = child.stdin.take().unwrap();
        let stdout = BufReader::new(child.stdout.take().unwrap());
        let mut lsp = Lsp { child, stdin, stdout, next: 0 };
        let initialized = lsp.request("initialize", json!({ "rootUri": uri(root), "capabilities": {} }));
        assert_eq!(initialized["result"]["capabilities"]["definitionProvider"], true, "{initialized}");
        lsp
    }

    fn send(&mut self, message: Value) {
        let body = message.to_string();
        write!(self.stdin, "Content-Length: {}\r\n\r\n{body}", body.len()).unwrap();
        self.stdin.flush().unwrap();
    }

    fn receive(&mut self) -> Value {
        let mut length = 0;
        loop {
            let mut line = String::new();
            assert!(self.stdout.read_line(&mut line).unwrap() > 0, "the server ended");
            let line = line.trim_end();
            if line.is_empty() {
                break;
            }
            if let Some(value) = line.strip_prefix("Content-Length: ") {
                length = value.parse().unwrap();
            }
        }
        let mut body = vec![0; length];
        self.stdout.read_exact(&mut body).unwrap();
        serde_json::from_slice(&body).unwrap()
    }

    fn notify(&mut self, method: &str, params: Value) {
        self.send(json!({ "jsonrpc": "2.0", "method": method, "params": params }));
    }

    /// Sends a request and returns its response; notifications in between are dropped.
    fn request(&mut self, method: &str, params: Value) -> Value {
        self.next += 1;
        let id = self.next;
        self.send(json!({ "jsonrpc": "2.0", "id": id, "method": method, "params": params }));
        loop {
            let message = self.receive();
            if message["id"] == id {
                return message;
            }
        }
    }

    fn diagnostics(&mut self) -> Value {
        loop {
            let message = self.receive();
            if message["method"] == "textDocument/publishDiagnostics" {
                return message["params"]["diagnostics"].clone();
            }
        }
    }

    fn open(&mut self, file: &std::path::Path, text: &str) -> Value {
        self.notify("textDocument/didOpen", json!({ "textDocument": { "uri": uri(file), "languageId": "php", "version": 1, "text": text } }));
        self.diagnostics()
    }

    fn change(&mut self, file: &std::path::Path, text: &str) -> Value {
        self.notify("textDocument/didChange", json!({ "textDocument": { "uri": uri(file), "version": 2 }, "contentChanges": [{ "text": text }] }));
        self.diagnostics()
    }

    fn labels(&mut self, file: &std::path::Path, line: u32, character: u32) -> Vec<String> {
        let response = self.request("textDocument/completion", json!({ "textDocument": { "uri": uri(file) }, "position": { "line": line, "character": character } }));
        response["result"]["items"].as_array().unwrap().iter().map(|item| item["label"].as_str().unwrap().to_owned()).collect()
    }

    fn at(&mut self, method: &str, file: &std::path::Path, line: u32, character: u32) -> Value {
        self.request(method, json!({ "textDocument": { "uri": uri(file) }, "position": { "line": line, "character": character } }))["result"].clone()
    }
}

fn uri(path: &std::path::Path) -> String {
    format!("file://{}", path.display())
}

/// A copy of the fixture project, for tests that change its files.
fn project(name: &str) -> std::path::PathBuf {
    let target = std::env::temp_dir().join(format!("tenma-lsp-{name}-{}", std::process::id()));
    let _ = std::fs::remove_dir_all(&target);
    copy(&std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures/lsp"), &target);
    target.canonicalize().unwrap()
}

fn copy(source: &std::path::Path, target: &std::path::Path) {
    std::fs::create_dir_all(target).unwrap();
    for entry in std::fs::read_dir(source).unwrap() {
        let entry = entry.unwrap();
        if entry.file_type().unwrap().is_dir() {
            copy(&entry.path(), &target.join(entry.file_name()));
        } else {
            std::fs::copy(entry.path(), target.join(entry.file_name())).unwrap();
        }
    }
}

#[test]
fn completes_this_in_a_file_a_provider_binds() {
    let root = project("this");
    let template = root.join("page.tpl.php");
    let mut lsp = Lsp::start(&root);
    lsp.open(&template, "<h1><?= $this-> ?></h1>\n<?= $this->author-> ?>\n<b data-on-click=\"");
    // Inside the class: private members too, no statics, no magic methods
    assert_eq!(lsp.labels(&template, 0, 15), ["title", "author", "authors", "pens", "tags", "increment", "secret", "team"]);
    // Through a typed property into another class: only its public members
    assert_eq!(lsp.labels(&template, 1, 19), ["name"]);
    // The provider's own completions
    assert_eq!(lsp.labels(&template, 2, 18), ["increment"]);
    // A value JSON cannot hold costs no other answer
    lsp.change(&template, "<?= $this-> NAN ?>");
    assert_eq!(lsp.labels(&template, 0, 11), ["not-a-number", "title", "author", "authors", "pens", "tags", "increment", "secret", "team"]);
    // A file no provider binds: nothing
    let other = root.join("other.php");
    lsp.open(&other, "<?php $this->");
    assert!(lsp.labels(&other, 0, 13).is_empty());
}

#[test]
fn loop_variables_get_the_element_type_of_what_they_loop_over() {
    let root = project("loops");
    let template = root.join("page.tpl.php");
    let mut lsp = Lsp::start(&root);
    let text = [
        "@foreach ($this->authors as $author) {{ $author-> }} @endforeach",
        "<?php foreach ($this->team() as $i => $member): ?><?= $member-> ?>",
        "<?php foreach ($this->pens as $pen) { echo $pen->",
        "<?php foreach ($this->tags as $tag) { echo $tag->",
        "{{ $unknown-> }}",
    ]
    .join("\n");
    lsp.open(&template, &text);
    // list<Author>, Author[] and array<int, Pen> through a use statement with an alias
    assert_eq!(lsp.labels(&template, 0, 49), ["name"]);
    assert_eq!(lsp.labels(&template, 1, 63), ["name"]);
    assert_eq!(lsp.labels(&template, 2, 49), ["pen"]);
    // Strings have no members, an unknown variable no type
    assert!(lsp.labels(&template, 3, 49).is_empty());
    assert!(lsp.labels(&template, 4, 13).is_empty());
    // Hover and definition work on loop variables too
    lsp.change(&template, "@foreach ($this->authors as $author) {{ $author->name }} @endforeach");
    let definition = lsp.at("textDocument/definition", &template, 0, 51);
    assert_eq!(definition["uri"], uri(&root.join("src/Author.php")), "{definition}");
    // A loop variable itself, value or key, leads to the loop that introduces it
    lsp.change(&template, "<p>\n@foreach ($this->tags as $i => $tag)\n  {{ $tag }} {{ $i }} {{ $nope }}\n@endforeach");
    for column in [6, 17] {
        let definition = lsp.at("textDocument/definition", &template, 2, column);
        assert_eq!(definition["uri"], uri(&template), "{definition}");
        assert_eq!(definition["range"]["start"]["line"], 1, "{definition}");
    }
    assert!(lsp.at("textDocument/definition", &template, 2, 25).is_null());
    // Not a loop in a comment
    lsp.change(&template, "<?php // foreach ($list as $x) ?>\n{{ $x }}");
    assert!(lsp.at("textDocument/definition", &template, 1, 4).is_null());
    // A class file is the PHP server's: no guess from a loop in another method
    let class = root.join("src/Rows.php");
    lsp.open(&class, "<?php\nclass Rows {\n    function a($rows) { foreach ($rows as $row) {} }\n    function b($row) { return $row; }\n}");
    assert!(lsp.at("textDocument/definition", &class, 3, 31).is_null());
}

#[test]
fn what_the_running_app_publishes_shows_next_to_the_providers_problems() {
    // An @ the client leaves as it is (as Neovim does), the server's own URIs would encode
    let root = project("published@app");
    let template = root.join("page.tpl.php");
    let mut lsp = Lsp::start(&root);
    let before = lsp.open(&template, "ä<?= $this->nope ?> TODO");
    let published = root.join(".tenma/diagnostics");
    std::fs::create_dir_all(&published).unwrap();
    // As Tenma::diagnostics() writes it under --watch
    // Column 7 in bytes is the "$" after "ä<?= ", the sixth UTF-16 unit
    let list = json!([{ "file": template.to_str().unwrap(), "line": 1, "column": 7, "severity": "warning", "message": "slow render" }]);
    std::fs::write(published.join("app.my-app.json"), list.to_string()).unwrap();
    let diagnostics = lsp.diagnostics();
    assert_eq!(diagnostics.as_array().unwrap().len(), before.as_array().unwrap().len() + 1, "{diagnostics}");
    let mine = diagnostics.as_array().unwrap().iter().find(|d| d["source"] == "my-app").unwrap();
    assert_eq!((mine["message"].as_str(), mine["severity"].as_u64(), mine["range"]["start"]["character"].as_u64()), (Some("slow render"), Some(2), Some(5)));
    // The same problem from a provider and from the app shows once
    let own = &before[0];
    let twice = json!([{ "file": template.to_str().unwrap(), "line": own["range"]["start"]["line"].as_u64().unwrap() + 1, "message": own["message"] }]);
    std::fs::write(published.join("app.checks.json"), twice.to_string()).unwrap();
    assert_eq!(lsp.diagnostics().as_array().unwrap().len(), before.as_array().unwrap().len() + 1);
    std::fs::remove_file(published.join("app.checks.json")).unwrap();
    // Taken back: only the providers' problems are left
    assert_eq!(lsp.diagnostics().as_array().unwrap().len(), before.as_array().unwrap().len() + 1);
    std::fs::remove_file(published.join("app.my-app.json")).unwrap();
    assert_eq!(lsp.diagnostics(), before);
}

#[test]
fn published_problems_arrive_while_the_editor_keeps_asking() {
    let root = project("busy");
    let template = root.join("page.tpl.php");
    let mut lsp = Lsp::start(&root);
    lsp.open(&template, "<?= $this->title ?>");
    let published = root.join(".tenma/diagnostics");
    std::fs::create_dir_all(&published).unwrap();
    let list = json!([{ "file": template.to_str().unwrap(), "line": 1, "message": "slow render" }]);
    std::fs::write(published.join("app.my-app.json"), list.to_string()).unwrap();
    // A request every 0.3 s, as while typing: the input is never quiet for a whole second
    let started = std::time::Instant::now();
    'asking: loop {
        assert!(started.elapsed() < std::time::Duration::from_secs(4), "nothing published while the editor asks");
        lsp.next += 1;
        let id = lsp.next;
        lsp.send(json!({ "jsonrpc": "2.0", "id": id, "method": "textDocument/hover", "params": { "textDocument": { "uri": uri(&template) }, "position": { "line": 0, "character": 0 } } }));
        loop {
            let message = lsp.receive();
            if message["method"] == "textDocument/publishDiagnostics" && message["params"]["diagnostics"].to_string().contains("slow render") {
                break 'asking;
            }
            if message["id"] == id {
                break;
            }
        }
        std::thread::sleep(std::time::Duration::from_millis(300));
    }
}

#[test]
fn semantic_tokens_come_from_providers_relative_and_in_utf16() {
    let root = project("tokens");
    let template = root.join("page.tpl.php");
    let mut lsp = Lsp::start(&root);
    let initialized = lsp.request("initialize", json!({ "capabilities": {} }));
    assert_eq!(initialized["result"]["capabilities"]["semanticTokensProvider"]["legend"]["tokenTypes"][0], "keyword");
    // "ä" is two bytes but one UTF-16 unit
    lsp.open(&template, "@if x @end\nä @foreach");
    let tokens = lsp.request("textDocument/semanticTokens/full", json!({ "textDocument": { "uri": uri(&template) } }));
    // line, start relative to the token before on the same line, length, type 0 = keyword
    assert_eq!(tokens["result"]["data"], json!([0, 0, 3, 0, 0, 0, 6, 4, 0, 0, 1, 2, 8, 0, 0]), "{tokens}");
}

#[test]
fn hover_and_definition_follow_this_into_the_class() {
    let root = project("definition");
    let template = root.join("page.tpl.php");
    let mut lsp = Lsp::start(&root);
    lsp.open(&template, "<?= $this->increment(1) ?><?= $this->author->name ?>");
    let hover = lsp.at("textDocument/hover", &template, 0, 13);
    assert_eq!(hover["contents"]["value"], "```php\npublic function increment(int $by): void\n```\n\nCounts up by one.", "{hover}");
    let method = lsp.at("textDocument/definition", &template, 0, 13);
    assert_eq!(method["uri"], uri(&root.join("src/Page.php")), "{method}");
    assert_eq!(method["range"]["start"]["line"], 22, "{method}");
    // A property: the line of its declaration, in the class it belongs to
    let property = lsp.at("textDocument/definition", &template, 0, 47);
    assert_eq!(property["uri"], uri(&root.join("src/Author.php")), "{property}");
    assert_eq!(property["range"]["start"]["line"], 8, "{property}");
    // A property from a trait: in the trait
    lsp.change(&template, "<?= $this->author->phone ?>");
    let property = lsp.at("textDocument/definition", &template, 0, 21);
    assert_eq!(property["uri"], uri(&root.join("src/Contact.php")), "{property}");
    assert_eq!(property["range"]["start"]["line"], 6, "{property}");
}

#[test]
fn diagnostics_follow_the_unsaved_text() {
    let root = project("diagnostics");
    let template = root.join("page.tpl.php");
    let mut lsp = Lsp::start(&root);
    let found = lsp.open(&template, "ok\nTODO\n");
    assert_eq!(found.as_array().unwrap().len(), 1, "{found}");
    assert_eq!(found[0]["range"]["start"]["line"], 1, "{found}");
    assert_eq!(found[0]["severity"], 2, "{found}");
    assert_eq!(lsp.change(&template, "ok\n"), json!([]));
    // A provider that throws is logged and left out; the server keeps answering
    assert_eq!(lsp.change(&template, "THROW\n"), json!([]));
    assert_eq!(lsp.labels(&template, 0, 0), Vec::<String>::new());
    lsp.notify("textDocument/didClose", json!({ "textDocument": { "uri": uri(&template) } }));
    assert_eq!(lsp.diagnostics(), json!([]));
}

#[test]
fn a_saved_class_is_seen_after_the_save() {
    let root = project("save");
    let template = root.join("page.tpl.php");
    let page = root.join("src/Page.php");
    let mut lsp = Lsp::start(&root);
    lsp.open(&template, "<?= $this->");
    assert!(!lsp.labels(&template, 0, 11).contains(&"subtitle".to_owned()));
    let source = std::fs::read_to_string(&page).unwrap().replace("public string $title = '';", "public string $title = '';\n\n    public string $subtitle = '';");
    std::fs::write(&page, source).unwrap();
    lsp.notify("textDocument/didSave", json!({ "textDocument": { "uri": uri(&page) } }));
    lsp.diagnostics();
    assert!(lsp.labels(&template, 0, 11).contains(&"subtitle".to_owned()));
}

#[test]
fn each_file_belongs_to_the_nearest_project_with_its_packages() {
    // A workspace above the project, as with several folders open: the first one is no project
    let workspace = project("workspace");
    let root = workspace.join("app");
    copy(&std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures/lsp"), &root);
    let template = root.join("page.tpl.php");
    let mut lsp = Lsp::start(&workspace.join("src"));
    lsp.open(&template, "<?= $this-> /* hints */");
    let labels = lsp.labels(&template, 0, 11);
    assert!(labels.contains(&"title".to_owned()), "{labels:?}");
    // The package's own composer.json names its provider, though installed.json is older
    assert!(labels.contains(&"from-package".to_owned()), "{labels:?}");
    // Outside any project: nothing, and no error
    let outside = std::env::temp_dir().join("tenma-lsp-no-project.php");
    lsp.open(&outside, "<?php $this->");
    assert!(lsp.labels(&outside, 0, 13).is_empty());
}

#[test]
fn unknown_requests_get_an_error_and_exit_ends_the_server() {
    let root = project("exit");
    let mut lsp = Lsp::start(&root);
    assert_eq!(lsp.request("textDocument/rename", json!({}))["error"]["code"], -32601);
    assert_eq!(lsp.request("shutdown", Value::Null)["result"], Value::Null);
    lsp.notify("exit", Value::Null);
    assert!(lsp.child.wait().unwrap().success());
}

#[test]
fn assigned_variables_get_the_type_of_what_is_assigned() {
    let root = project("assignments");
    let template = root.join("page.tpl.php");
    let mut lsp = Lsp::start(&root);
    let text = [
        "<?php $writer = $this->author;",
        "$made = $this->make();",
        "$fresh = new \\Fixture\\Author(); // $fresh = 'x';",
        "?>",
        "{{ $writer-> }}",
        "{{ $made-> }}",
        "@code $fresh = 'a;b'; $fresh = $this->author; @endcode {{ $fresh-> }}",
        "<?php $writer = 'now a string'; ?>{{ $writer-> }}",
    ]
    .join("\n");
    lsp.open(&template, &text);
    // A property, a method's return type (self), new; a comment and a string are no assignment
    assert_eq!(lsp.labels(&template, 4, 12), ["name"]);
    assert_eq!(lsp.labels(&template, 5, 10), ["title", "author", "authors", "pens", "tags", "increment", "secret", "team"]);
    assert_eq!(lsp.labels(&template, 6, 66), ["name"]);
    // The last assignment counts: a string has no members
    assert!(lsp.labels(&template, 7, 46).is_empty());
    // Hover and definition of a member, and F12 on the variable leads to the last assignment above
    lsp.change(&template, "<?php $writer = 'x';\n$writer = $this->author; ?>\n{{ $writer->name }}");
    let hover = lsp.at("textDocument/hover", &template, 2, 13);
    assert_eq!(hover["contents"]["value"], "```php\npublic string $name\n```", "{hover}");
    let property = lsp.at("textDocument/definition", &template, 2, 13);
    assert_eq!(property["uri"], uri(&root.join("src/Author.php")), "{property}");
    let definition = lsp.at("textDocument/definition", &template, 2, 5);
    assert_eq!(definition["uri"], uri(&template), "{definition}");
    assert_eq!(definition["range"]["start"]["line"], 1, "{definition}");
    // Not an assignment in a comment, nor a comparison
    lsp.change(&template, "<?php // $x = 1\nif ($x == 2) {} ?>\n{{ $x }}");
    assert!(lsp.at("textDocument/definition", &template, 2, 4).is_null());
}
