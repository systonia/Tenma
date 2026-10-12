#!/bin/sh
# Prints the Homebrew formula for a macOS release: packaging/homebrew.sh 0.1.2 tenma-macos-arm64.tar.gz target/release/tenma
# The dependencies are the Homebrew libraries the binary links, so the formula keeps up with what CI built against.
set -eu
version=$1 tarball=$2 binary=$3
sha256=$(shasum -a 256 "$tarball" | cut -d' ' -f1)
cat <<RUBY
class Tenma < Formula
  desc "PHP with Node's execution model: one process, one event loop"
  homepage "https://tenma.dev"
  url "https://github.com/systonia/Tenma/releases/download/v$version/$(basename "$tarball")"
  version "$version"
  sha256 "$sha256"
  license any_of: ["MIT", "Apache-2.0"]

  depends_on arch: :arm64
  depends_on :macos
RUBY
otool -L "$binary" | sed -n 's#^[[:space:]]*/opt/homebrew/opt/\([^/]*\)/.*#  depends_on "\1"#p' | sort -u
cat <<'RUBY'

  def install
    bin.install "tenma"
    prefix.install "NOTICE", "LICENSE-MIT", "LICENSE-APACHE"
  end

  test do
    (testpath/"hello.php").write "<?php echo 'hello';"
    assert_equal "hello", shell_output("#{bin}/tenma #{testpath}/hello.php")
  end
end
RUBY
