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
    let source = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures/lsp");
    let target = std::env::temp_dir().join(format!("tenma-lsp-{name}-{}", std::process::id()));
    let _ = std::fs::remove_dir_all(&target);
    for directory in ["", "src", "vendor"] {
        std::fs::create_dir_all(target.join(directory)).unwrap();
        for entry in std::fs::read_dir(source.join(directory)).unwrap() {
            let entry = entry.unwrap();
            if entry.file_type().unwrap().is_file() {
                std::fs::copy(entry.path(), target.join(directory).join(entry.file_name())).unwrap();
            }
        }
    }
    target.canonicalize().unwrap()
}

#[test]
fn completes_this_in_a_file_a_provider_binds() {
    let root = project("this");
    let template = root.join("page.tpl.php");
    let mut lsp = Lsp::start(&root);
    lsp.open(&template, "<h1><?= $this-> ?></h1>\n<?= $this->author-> ?>\n<b data-on-click=\"");
    // Inside the class: private members too, no statics, no magic methods
    assert_eq!(lsp.labels(&template, 0, 15), ["title", "author", "increment", "secret"]);
    // Through a typed property into another class: only its public members
    assert_eq!(lsp.labels(&template, 1, 19), ["name"]);
    // The provider's own completions
    assert_eq!(lsp.labels(&template, 2, 18), ["increment"]);
    // A file no provider binds: nothing
    let other = root.join("other.php");
    lsp.open(&other, "<?php $this->");
    assert!(lsp.labels(&other, 0, 13).is_empty());
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
    assert_eq!(method["range"]["start"]["line"], 11, "{method}");
    // A property: the line of its declaration, in the class it belongs to
    let property = lsp.at("textDocument/definition", &template, 0, 47);
    assert_eq!(property["uri"], uri(&root.join("src/Author.php")), "{property}");
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
fn unknown_requests_get_an_error_and_exit_ends_the_server() {
    let root = project("exit");
    let mut lsp = Lsp::start(&root);
    assert_eq!(lsp.request("textDocument/rename", json!({}))["error"]["code"], -32601);
    assert_eq!(lsp.request("shutdown", Value::Null)["result"], Value::Null);
    lsp.notify("exit", Value::Null);
    assert!(lsp.child.wait().unwrap().success());
}
