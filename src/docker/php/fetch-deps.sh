#!/usr/bin/env bash
# Downloads PECL tarballs on the HOST (build container's https to pecl is unreliable) into docker/php/deps/
set -euo pipefail
cd "$(dirname "$0")"
REDIS_V=6.2.0; XDEBUG_V=3.5.0
mkdir -p deps
[ -f deps/redis-$REDIS_V.tgz ]  || curl -fsSL -o deps/redis-$REDIS_V.tgz  https://pecl.php.net/get/redis-$REDIS_V.tgz
[ -f deps/xdebug-$XDEBUG_V.tgz ] || curl -fsSL -o deps/xdebug-$XDEBUG_V.tgz https://pecl.php.net/get/xdebug-$XDEBUG_V.tgz
