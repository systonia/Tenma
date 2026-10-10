// Builds the C layer against the self-built PHP (php-build/build.sh) and links libphp.a statically.
use std::path::PathBuf;
use std::process::Command;

fn main() {
    let root = PathBuf::from(std::env::var("CARGO_MANIFEST_DIR").unwrap());
    let php = root.join("php-build/out");
    let config = php.join("bin/php-config");
    assert!(config.exists(), "PHP missing: run php-build/build.sh first");

    let run = |option: &str| -> String {
        let output = Command::new(&config).arg(option).output().expect("php-config does not run");
        String::from_utf8(output.stdout).unwrap()
    };
    // php-config reports the absolute path from when PHP was built: after the repo moves it points
    // nowhere, so redirect it to the current php-build/out
    let built_at = run("--prefix").trim().to_owned();
    let flags = |option: &str| -> Vec<String> {
        run(option).split_whitespace().map(|flag| flag.replace(&built_at, &php.display().to_string())).collect()
    };

    let mut build = cc::Build::new();
    build.file("shim/tenma.c").warnings(false);
    for include in flags("--includes") {
        build.include(include.trim_start_matches("-I"));
    }
    build.compile("tenma_shim");

    println!("cargo:rustc-link-search=native={}", php.join("lib").display());
    println!("cargo:rustc-link-lib=static=php");
    // What libphp.a itself needs: search paths, libraries and macOS frameworks of the extensions
    let mut linker = [flags("--ldflags"), flags("--libs")].concat().into_iter();
    while let Some(flag) = linker.next() {
        if let Some(path) = flag.strip_prefix("-L") {
            println!("cargo:rustc-link-search=native={path}");
        } else if let Some(name) = flag.strip_prefix("-l") {
            // intl is C++; PHP calls the runtime stdc++, on macOS it is called c++
            let name = if name == "stdc++" && cfg!(target_os = "macos") { "c++" } else { name };
            println!("cargo:rustc-link-lib={name}");
        } else if flag == "-framework" {
            if let Some(name) = linker.next() {
                println!("cargo:rustc-link-lib=framework={name}");
            }
        } else if flag.starts_with("-Wl,") {
            println!("cargo:rustc-link-arg={flag}");
        }
    }
    println!("cargo:rerun-if-changed=shim/tenma.c");
    println!("cargo:rerun-if-changed=php-build/out/lib/libphp.a");
}
