//! The standard library in PHP (stdlib/), embedded in the binary: one file per class, the functions of
//! a namespace in its functions.php. build.rs lists the files. The commands written in PHP (`tenma lsp`,
//! `tenma docs`, `tenma --stubs`) live in their own directories and load only for their command.

use crate::php;

/// Every file of stdlib/, by path relative to it, sorted.
const FILES: &[(&str, &str)] = include!(concat!(env!("OUT_DIR"), "/stdlib.rs"));

/// A command written in PHP, by the directory of its files.
#[derive(Clone, Copy)]
pub enum Tool {
    Lsp,
    Docs,
    Stubs,
}

impl Tool {
    fn directory(self) -> &'static str {
        match self {
            Tool::Lsp => "Tenma/Internal/Lsp/",
            Tool::Docs => "Tenma/Internal/Docs/",
            Tool::Stubs => "Tenma/Internal/Stubs/",
        }
    }
}

const TOOLS: [Tool; 3] = [Tool::Lsp, Tool::Docs, Tool::Stubs];

/// Loads the standard library into this thread's interpreter, and the files of `tool` with it.
/// No file runs code when it is loaded, so the order does not matter.
pub fn load(tool: Option<Tool>) -> Result<(), String> {
    for (path, source) in FILES {
        let owner = TOOLS
            .iter()
            .find(|candidate| path.starts_with(candidate.directory()));
        let wanted = match (owner, tool) {
            (None, _) => true,
            (Some(owner), Some(tool)) => owner.directory() == tool.directory(),
            (Some(_), None) => false,
        };
        if wanted {
            let code = source.trim_start_matches("<?php");
            php::eval(code, &format!("tenma:stdlib/{path}"))?;
        }
    }
    Ok(())
}
