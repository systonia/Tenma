#!/bin/sh
# Builds PHP as a static library with the embed SAPI and ZTS into php-build/out.
# tenma links libphp.a in, the system PHP stays untouched.
#
# Libraries for extensions come from the macOS SDK (libxml2, zlib, sqlite, curl) and from Homebrew:
#   brew install pkgconf openssl@3 libpq icu4c libsodium libzip gmp oniguruma libpng jpeg-turbo webp freetype
# ponytail: Homebrew libraries are linked dynamically, the binary only runs on Macs with these
# packages; Linux and Docker get their own build
set -eu
VERSION=8.5.9
SHA256=0db7855f25bcd0ab1d592cdb35e284d6f6a5d2ae0f6f621122e364cc39b708f4
cd "$(dirname "$0")"

if [ ! -f "php-$VERSION.tar.xz" ]; then
  curl -sSfLO "https://www.php.net/distributions/php-$VERSION.tar.xz"
fi
echo "$SHA256  php-$VERSION.tar.xz" | shasum -a 256 -c -
rm -rf "php-$VERSION" && tar xf "php-$VERSION.tar.xz"

cd "php-$VERSION"
SDK="$(xcrun --show-sdk-path)"
BREW="$(brew --prefix)"
export LIBXML_CFLAGS="-I$SDK/usr/include/libxml2" LIBXML_LIBS="-lxml2"
export ZLIB_CFLAGS="" ZLIB_LIBS="-lz"
export SQLITE_CFLAGS="" SQLITE_LIBS="-lsqlite3"
export CURL_CFLAGS="" CURL_LIBS="-lcurl"
# Homebrew does not put some packages in the default path (keg-only)
for package in openssl@3 libpq icu4c libsodium libzip gmp oniguruma libpng jpeg-turbo webp freetype; do
  PKG_CONFIG_PATH="$BREW/opt/$package/lib/pkgconfig:${PKG_CONFIG_PATH:-}"
done
export PKG_CONFIG_PATH

./configure \
  --prefix="$(pwd)/../out" \
  --enable-embed=static \
  --enable-zts \
  --disable-all \
  --disable-cli --disable-cgi --disable-phpdbg \
  `# JIT: PHP does not build it with ZTS on Apple Silicon ("JIT not supported on Apple Silicon with ZTS"),` \
  `# on Linux it does; enable with -d opcache.jit=tracing` \
  --enable-opcache-jit \
  `# Basics` \
  --enable-ctype --enable-tokenizer --enable-filter --enable-fileinfo --enable-session \
  --enable-bcmath --enable-calendar --enable-exif --enable-posix --enable-sockets \
  --with-zlib --enable-mbstring --without-iconv \
  `# XML` \
  --with-libxml --enable-simplexml --enable-dom --enable-xml --enable-xmlreader --enable-xmlwriter \
  `# Databases` \
  --enable-pdo --with-pdo-mysql=mysqlnd --with-mysqli=mysqlnd --enable-mysqlnd \
  --with-pdo-sqlite --with-sqlite3 \
  --with-pdo-pgsql="$BREW/opt/libpq" --with-pgsql="$BREW/opt/libpq" \
  `# Network and cryptography` \
  --with-openssl --with-curl --with-sodium \
  `# Other` \
  --enable-intl --with-gmp="$BREW/opt/gmp" --with-zip \
  --enable-gd --with-jpeg --with-webp --with-freetype
make -j"$(sysctl -n hw.ncpu)"
make install
