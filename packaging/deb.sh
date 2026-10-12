#!/bin/sh
# Builds tenma_<version>_<arch>.deb inside the Dockerfile's build stage, into the directory $2:
#   docker run --rm -v "$PWD:/out" tenma-build packaging/deb.sh 0.1.2 /out
# Depends lists the Debian packages of the libraries the binary links (/packages, written by the Dockerfile).
set -eu
version=$1 out=$2
arch=$(dpkg --print-architecture)
root=$(mktemp -d)
install -Dm755 target/release/tenma "$root/usr/bin/tenma"
install -Dm644 NOTICE "$root/usr/share/doc/tenma/NOTICE"
install -Dm644 LICENSE-MIT "$root/usr/share/doc/tenma/LICENSE-MIT"
install -Dm644 LICENSE-APACHE "$root/usr/share/doc/tenma/LICENSE-APACHE"
mkdir -p "$root/DEBIAN"
cat > "$root/DEBIAN/control" <<CONTROL
Package: tenma
Version: $version
Architecture: $arch
Maintainer: Lento Leisenfeld <lento@leisenfeld.com>
Depends: ca-certificates, $(paste -sd, /packages | sed 's/,/, /g')
Section: web
Priority: optional
Homepage: https://tenma.dev
Description: PHP with Node's execution model: one process, one event loop
 One process runs the PHP script once and then serves requests continuously,
 every request in its own fiber, with async Postgres, Redis, files and WebSockets.
CONTROL
dpkg-deb --root-owner-group --build "$root" "$out/tenma_${version}_$arch.deb"
