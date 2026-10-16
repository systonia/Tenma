---
title: "Tenma\\Lsp"
description: "The classes, interfaces, traits and enums in Tenma\\Lsp."
order: 200
generated: true
---

## Types

- [`Imports`](Imports.md) · interface: A provider that also names the imports of a file, such as a template that sees the names its class file imports: tenma resolves class names there with them (completion after `Name::`, hover, F12, `@var` types, the types of loop and assigned variables).
- [`Provider`](Provider.md) · interface: What a package adds to `tenma lsp`, the language server that runs next to the editor's own PHP server.
- [`Tokens`](Tokens.md) · interface: A provider that also colours what the editor's own grammar does not know, such as template syntax: tenma answers the editor's semantic tokens with these.
