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
    let output = Command::new(env!("CARGO_BIN_EXE_tenma"))
        .arg("docs")
        .args(args)
        .arg("--out")
        .arg(out)
        .output()
        .unwrap();
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
                let relative = path
                    .strip_prefix(dir)
                    .unwrap()
                    .to_string_lossy()
                    .into_owned();
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
    assert_eq!(
        names,
        [
            "Acme/Shop/Cart.md",
            "Acme/Shop/HasItems.md",
            "Acme/Shop/Item.md",
            "Acme/Shop/Priced.md",
            "Acme/Shop/Status.md",
            "Acme/Shop/index.md",
            "Acme/index.md",
            "index.md",
        ]
    );

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
    assert!(
        pages["Acme/Shop/Item.md"].contains("protected function gross(): int"),
        "protected in a class open to extension"
    );
    assert!(pages["Acme/Shop/HasItems.md"].contains("protected array $items = [];"));
    let status = &pages["Acme/Shop/Status.md"];
    assert!(
        status.contains("enum Status: string")
            && status.contains("case Open = 'open';\n```\n\nNot paid yet."),
        "{status}"
    );
    assert!(
        !status.contains("$value") && !status.contains("tryFrom"),
        "{status}"
    );
    assert!(pages["Acme/Shop/index.md"].contains(
        "- [`Cart`](Cart.md) · final class: A shopping cart.\n- [`HasItems`](HasItems.md) · trait"
    ));
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
    std::fs::write(
        out.join("Old/Gone/Type.md"),
        "---\ntitle: Type\ngenerated: true\n---\n",
    )
    .unwrap();
    std::fs::write(out.join("guide.md"), "---\ntitle: Written by hand\n---\n").unwrap();
    let report = docs(&["tests/fixtures/docs/src"], &out);
    assert!(report.contains("1 removed"), "{report}");
    assert!(!out.join("Old").exists(), "empty folders go too");
    assert!(
        out.join("guide.md").exists(),
        "a page without generated: true stays"
    );

    // A hand-written page where a generated one would go is not overwritten
    std::fs::write(out.join("index.md"), "---\ntitle: Mine\n---\n").unwrap();
    let output = Command::new(env!("CARGO_BIN_EXE_tenma"))
        .args(["docs", "tests/fixtures/docs/src", "--out"])
        .arg(&out)
        .output()
        .unwrap();
    // One line and status 1, not an uncaught exception with its trace
    let stderr = String::from_utf8_lossy(&output.stderr);
    assert_eq!(output.status.code(), Some(1), "{stderr}");
    assert!(
        stderr.starts_with("tenma docs: ") && stderr.contains("not overwriting"),
        "{stderr}"
    );
    assert_eq!(stderr.lines().count(), 1, "{stderr}");
    assert_eq!(
        std::fs::read_to_string(out.join("index.md")).unwrap(),
        "---\ntitle: Mine\n---\n"
    );
}

#[test]
fn documents_tenmas_own_api_without_paths() {
    let out = scratch("builtin");
    docs(&[], &out);
    let pages = files(&out);
    let socket = &pages["Tenma/Http/WebSocket.md"];
    assert!(
        socket.contains("### receive()")
            && socket.contains("public function send(string $data): void"),
        "{socket}"
    );
    assert!(pages["Tenma.md"].contains("### serve()"));
    assert!(
        pages["Tenma.md"].contains("[`Request`](Tenma/Http/Request.md)"),
        "cross-links"
    );
    assert!(pages.contains_key("Tenma/Lsp/Provider.md") && pages.contains_key("Tenma/Db/index.md"));
    assert!(
        !pages.keys().any(|name| name.contains("Internal")),
        "{:?}",
        pages.keys()
    );
}

#[test]
fn prints_defaults_as_php_source() {
    let out = scratch("defaults");
    docs(&["tests/fixtures/docs/defaults"], &out);
    let csv = std::fs::read_to_string(out.join("Acme/Csv.md")).unwrap();
    assert!(
        csv.contains(r#"public string $lineEnding = "\r\n";"#),
        "{csv}"
    );
    assert!(
        csv.contains("public function write(string $separator = self::SEPARATOR): void"),
        "{csv}"
    );
    assert!(
        csv.contains("description: \"Writes rows with `$csv->write('. ')` style calls.\""),
        "{csv}"
    );
}

#[test]
fn skips_a_type_that_does_not_load() {
    let out = scratch("unloadable");
    let output = Command::new(env!("CARGO_BIN_EXE_tenma"))
        .args([
            "docs",
            "tests/fixtures/docs/unloadable",
            "tests/fixtures/docs/defaults",
            "--out",
        ])
        .arg(&out)
        .output()
        .unwrap();
    assert!(output.status.success(), "{output:?}");
    let stderr = String::from_utf8_lossy(&output.stderr);
    assert!(
        stderr.contains("warning: skipping Acme\\Rule") && stderr.contains("Elsewhere\\Missing"),
        "{stderr}"
    );
    assert!(out.join("Acme/Csv.md").exists() && !out.join("Acme/Rule.md").exists());
}

#[test]
fn documents_functions_in_the_index_of_their_namespace() {
    let out = scratch("functions");
    let report = docs(&["tests/fixtures/docs/functions"], &out);
    assert!(report.contains("1 types, 3 functions, 5 pages"), "{report}");
    let pages = files(&out);
    let text = &pages["Acme/Text/index.md"];
    for expected in [
        "description: \"The classes, interfaces, traits, enums and functions in Acme\\\\Text.\"",
        "## Types\n\n- [`Style`](Style.md) · enum\n\n## Functions\n\n### classes()\n\n```php\nfunction classes(array|string ...$parts): string\n```\n\nJoins CSS classes, each once.",
        "- `...$parts` `string|array<string, bool>`: strings and arrays of class => condition",
        "**Returns** `string`: the classes, separated by one space",
        "### styled()\n\n```php\nfunction styled(string $text, \\Acme\\Text\\Style $look = \\Acme\\Text\\Style::Plain): string\n```",
        "- `$look` [`Style`](Style.md)",
    ] {
        assert!(text.contains(expected), "missing {expected} in\n{text}");
    }
    // @internal functions, closures and methods are no functions of the namespace
    for hidden in ["hidden", "apply", "wrap", "inner"] {
        assert!(!text.contains(hidden), "leaks {hidden}");
    }
    // A second namespace in the same file, with functions only, gets an index of its own
    assert!(pages["Acme/Text/Html/index.md"].contains(
        "### escape()\n\n```php\nfunction &escape(string $text): string\n```\n\nEscapes for HTML."
    ));
    assert!(pages["Acme/Text/index.md"].contains("- [`Acme\\Text\\Html`](Html/index.md)"));
    let again = docs(&["tests/fixtures/docs/functions"], &out);
    assert!(again.contains("(0 written, 0 removed)"), "{again}");
}
