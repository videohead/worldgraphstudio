#!/bin/sh
set -eu

if [ ! -x /app/node_modules/.bin/playwright ]; then
	cp -a /opt/worldgraph/node_modules/. /app/node_modules/
fi

exec "$@"
