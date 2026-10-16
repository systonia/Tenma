//! `tenma docs`: Markdown reference pages from reflection and docblocks.

use std::collections::BTreeMap;
use std::path::{Path, PathBuf};
use std::process::Command;

fn scratch(name: &str) -> PathBuf {
    let dir = std::env::temp_dir().join(format!("tenma-docs-{name}-{}", std::process::id()));
    let _ = std::fs::remove_dir_all(&dir);
    dir
}

fn docs(args: &[&str], out: &Path) -> String {
    let output = Command::new(env!("CARGO_BIN_EXE_tenma")).arg("docs").args(args).arg("--out").arg(out).output().unwrap();
    assert!(output.status.success(), "{output:?}");
    String::from_utf8_lossy(&output.stdout).into_owned()
}

/// Every file below $dir, relative path → content.
fn files(dir: &Path) -> BTreeMap<String, String> {
    let mut found = BTreeMap::new();
    let mut pending = vec![dir.to_path_buf()];
    while let Some(next) = pending.pop() {
        for entry in std::fs::read_dir(next).unwrap() {
            let path = entry.unwrap().path();
            if path.is_dir() {
                pending.push(path);
            } else {
                let relative = path.strip_prefix(dir).unwrap().to_string_lossy().into_owned();
                found.insert(relative, std::fs::read_to_string(&path).unwrap());
            }
        }
    }
    found
}

#[test]
fn documents_a_package() {
    let out = scratch("package");
    docs(&["tests/fixtures/docs/src"], &out);
    let pages = files(&out);
    let names: Vec<&str> = pages.keys().map(String::as_str).collect();
    assert_eq!(names, [
        "Acme/Shop/Cart.md",
        "Acme/Shop/HasItems.md",
        "Acme/Shop/Item.md",
        "Acme/Shop/Priced.md",
        "Acme/Shop/Status.md",
        "Acme/Shop/index.md",
        "Acme/index.md",
        "index.md",
    ]);

    let cart = &pages["Acme/Shop/Cart.md"];
    for expected in [
        "---\ntitle: \"Cart\"\ndescription: \"A shopping cart.\"\norder: 100\ngenerated: true\n---\n",
        "`Acme\\Shop\\Cart` · final class · namespace [`Acme\\Shop`](index.md)",
        "final class Cart implements \\Acme\\Shop\\Priced, \\Countable",
        "Implements [`Priced`](Priced.md), [`Countable`](https://www.php.net/manual/en/class.countable.php)",
        "Uses [`HasItems`](HasItems.md)",
        "```php\n$cart = new Cart('EUR');",
        "- `TItem` of [`Item`](Item.md)",
        "public const int LIMIT = 100;\n```\n\nMost items a cart may hold.",
        "public string $label { get; }",
        "public readonly string $currency = 'EUR';",
        "public function add(\\Acme\\Shop\\Item $item, int $quantity = 1): static",
        "- `$item` `TItem`: the item to add",
        "**Returns** `$this`",
        "- [`InvalidArgumentException`](https://www.php.net/manual/en/class.invalidargumentexception.php): when the cart is full",
        "### total()\n\n```php\npublic function total(): int\n```\n\nThe total in cents.",
        "**Returns** `list<`[`Item`](Item.md)`>`",
    ] {
        assert!(cart.contains(expected), "missing {expected} in\n{cart}");
    }
    // @internal and private members, and @internal types, stay out
    for hidden in ["SECRET", "ledger", "Ledger", "secret()"] {
        assert!(!cart.contains(hidden), "leaks {hidden}");
    }
    assert!(pages["Acme/Shop/Item.md"].contains("protected function gross(): int"), "protected in a class open to extension");
    assert!(pages["Acme/Shop/HasItems.md"].contains("protected array $items = [];"));
    let status = &pages["Acme/Shop/Status.md"];
    assert!(status.contains("enum Status: string") && status.contains("case Open = 'open';\n```\n\nNot paid yet."), "{status}");
    assert!(!status.contains("$value") && !status.contains("tryFrom"), "{status}");
    assert!(pages["Acme/Shop/index.md"].contains("- [`Cart`](Cart.md) · final class: A shopping cart.\n- [`HasItems`](HasItems.md) · trait"));
    assert!(pages["index.md"].contains("- [`Acme`](Acme/index.md)"));
    assert!(pages["Acme/index.md"].contains("- [`Acme\\Shop`](Shop/index.md)"));
}

#[test]
fn runs_again_without_a_diff() {
    let out = scratch("determinism");
    docs(&["tests/fixtures/docs/src"], &out);
    let first = files(&out);
    let report = docs(&["tests/fixtures/docs/src"], &out);
    assert!(report.contains("(0 written, 0 removed)"), "{report}");
    assert_eq!(files(&out), first);
}

#[test]
fn removes_stale_generated_pages_only() {
    let out = scratch("stale");
    std::fs::create_dir_all(out.join("Old/Gone")).unwrap();
    std::fs::write(out.join("Old/Gone/Type.md"), "---\ntitle: Type\ngenerated: true\n---\n").unwrap();
    std::fs::write(out.join("guide.md"), "---\ntitle: Written by hand\n---\n").unwrap();
    let report = docs(&["tests/fixtures/docs/src"], &out);
    assert!(report.contains("1 removed"), "{report}");
    assert!(!out.join("Old").exists(), "empty folders go too");
    assert!(out.join("guide.md").exists(), "a page without generated: true stays");

    // A hand-written page where a generated one would go is not overwritten
    std::fs::write(out.join("index.md"), "---\ntitle: Mine\n---\n").unwrap();
    let output = Command::new(env!("CARGO_BIN_EXE_tenma")).args(["docs", "tests/fixtures/docs/src", "--out"]).arg(&out).output().unwrap();
    assert!(!output.status.success());
    assert!(String::from_utf8_lossy(&output.stderr).contains("not overwriting"), "{output:?}");
    assert_eq!(std::fs::read_to_string(out.join("index.md")).unwrap(), "---\ntitle: Mine\n---\n");
}

#[test]
fn documents_tenmas_own_api_without_paths() {
    let out = scratch("builtin");
    docs(&[], &out);
    let pages = files(&out);
    let socket = &pages["Tenma/Http/WebSocket.md"];
    assert!(socket.contains("### receive()") && socket.contains("public function send(string $data): void"), "{socket}");
    assert!(pages["Tenma.md"].contains("### serve()"));
    assert!(pages["Tenma.md"].contains("[`Request`](Tenma/Http/Request.md)"), "cross-links");
    assert!(pages.contains_key("Tenma/Lsp/Provider.md") && pages.contains_key("Tenma/Db/index.md"));
    assert!(!pages.keys().any(|name| name.contains("Internal")), "{:?}", pages.keys());
}
