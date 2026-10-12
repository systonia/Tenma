# tenma for Linux: builds PHP and tenma on Debian, the image holds only the binary and the libraries it links.
#   docker build -t tenma .
#   docker run -p 8080:8080 -v "$PWD:/app" tenma /app/index.php
# The binary alone (for a Debian 13 host with the same libraries): docker cp $(docker create tenma):/usr/local/bin/tenma .
FROM rust:1-trixie AS build
RUN apt-get update && apt-get install -y --no-install-recommends \
    pkg-config xz-utils libxml2-dev zlib1g-dev libsqlite3-dev libcurl4-openssl-dev libssl-dev libpq-dev \
    libicu-dev libsodium-dev libzip-dev libgmp-dev libonig-dev libpng-dev libjpeg-dev libwebp-dev libfreetype-dev \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /src
COPY php-build/build.sh php-build/
RUN php-build/build.sh
COPY . .
RUN cargo build --release
# The Debian packages of the shared libraries tenma links, for the runtime image
RUN ldd target/release/tenma | awk '/=> \// { print $3 }' | xargs realpath \
    | while read -r lib; do dpkg -S "$lib" 2>/dev/null || dpkg -S "${lib#/usr}"; done \
    | cut -d: -f1 | sort -u > /packages

FROM debian:trixie-slim
COPY --from=build /packages /packages
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates $(cat /packages) \
    && rm -rf /var/lib/apt/lists/* /packages
COPY --from=build /src/target/release/tenma /usr/local/bin/tenma
COPY NOTICE LICENSE-MIT LICENSE-APACHE /usr/share/doc/tenma/
ENV PORT=8080
EXPOSE 8080
STOPSIGNAL SIGTERM
ENTRYPOINT ["tenma"]
