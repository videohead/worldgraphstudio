#!/bin/sh
set -eu

lockfile=/opt/worldgraph/package-lock.json
dependency_stamp=/app/node_modules/.worldgraph-package-lock.json

if ! cmp -s "$lockfile" "$dependency_stamp"; then
	find /app/node_modules -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
	cp -a /opt/worldgraph/node_modules/. /app/node_modules/
	cp "$lockfile" "$dependency_stamp"
fi

exec "$@"
