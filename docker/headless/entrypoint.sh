#!/bin/sh
set -eu

if [ ! -x /app/headless/node_modules/.bin/next ]; then
	cp -a /opt/worldgraph-headless/node_modules/. /app/headless/node_modules/
fi

exec "$@"
