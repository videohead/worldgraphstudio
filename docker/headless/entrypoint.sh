#!/bin/sh
set -eu

lockfile=/opt/worldgraph-headless/package-lock.json
dependency_stamp=/app/headless/node_modules/.worldgraph-package-lock.json

if ! cmp -s "$lockfile" "$dependency_stamp"; then
	find /app/headless/node_modules -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
	cp -a /opt/worldgraph-headless/node_modules/. /app/headless/node_modules/
	cp "$lockfile" "$dependency_stamp"
fi

exec "$@"
