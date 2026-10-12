#!/bin/sh
# Installs the latest tenma: curl -fsSL https://raw.githubusercontent.com/systonia/Tenma/main/packaging/install.sh | sh
# macOS through Homebrew (the binary links Homebrew's libraries), Debian and Ubuntu through the .deb,
# any other Linux as the binary in ~/.local/bin (TENMA_DIR), with a list of the libraries it still misses.
# TENMA_VERSION=0.1.2 installs that version on Linux.
set -eu
repo=systonia/Tenma
fail() { echo "tenma: $*" >&2; exit 1; }

case "$(uname -s)" in
  Darwin)
    [ "$(uname -m)" = arm64 ] || fail "only Apple Silicon Macs are supported"
    command -v brew >/dev/null || fail "needs Homebrew (https://brew.sh), then: brew install systonia/tap/tenma"
    exec brew install systonia/tap/tenma
    ;;
  Linux) ;;
  *) fail "$(uname -s) is not supported" ;;
esac

case "$(uname -m)" in
  x86_64 | amd64) arch=x64 debarch=amd64 ;;
  aarch64 | arm64) arch=arm64 debarch=arm64 ;;
  *) fail "$(uname -m) is not supported" ;;
esac
version=${TENMA_VERSION:-}
[ -n "$version" ] || version=$(curl -fsSL "https://api.github.com/repos/$repo/releases/latest" | sed -n 's/.*"tag_name": *"v\([^"]*\)".*/\1/p')
[ -n "$version" ] || fail "cannot find the latest release"
download="https://github.com/$repo/releases/download/v$version"
temp=$(mktemp -d)
trap 'rm -rf "$temp"' EXIT

if command -v apt-get >/dev/null; then
  deb="tenma_${version}_$debarch.deb"
  curl -fsSL -o "$temp/$deb" "$download/$deb"
  sudo=""; [ "$(id -u)" = 0 ] || sudo=sudo
  # apt resolves the libraries; on a release without them (older Debian or Ubuntu) it says which are missing
  $sudo apt-get install -y "$temp/$deb"
else
  dir=${TENMA_DIR:-$HOME/.local/bin}
  curl -fsSL "$download/tenma-linux-$arch.tar.gz" | tar xz -C "$temp"
  mkdir -p "$dir"
  # rm first: overwriting a running binary in place fails or breaks it
  rm -f "$dir/tenma" && cp "$temp/tenma" "$dir/tenma"
  missing=$(ldd "$dir/tenma" 2>/dev/null | sed -n 's/^[[:space:]]*\([^ ]*\) => not found/\1/p')
  case ":$PATH:" in *":$dir:"*) ;; *) echo "tenma: add $dir to your PATH" >&2 ;; esac
  [ -z "$missing" ] || fail "installed in $dir, but these libraries are missing (or use the Docker image ghcr.io/systonia/tenma):" $missing
  "$dir/tenma" --version
  exit
fi
tenma --version
