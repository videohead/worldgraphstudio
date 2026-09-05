FROM node:20-bookworm

ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright
ENV PATH="/app/node_modules/.bin:${PATH}"

RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg ripgrep \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /opt/worldgraph
COPY package.json package-lock.json ./
RUN npm ci \
    && npx playwright install --with-deps chromium

RUN ln -s /opt/worldgraph/node_modules/.bin/jsonlint /usr/local/bin/jsonlint \
    && ln -s /opt/worldgraph/node_modules/.bin/minify /usr/local/bin/minify \
    && ln -s /opt/worldgraph/node_modules/.bin/playwright /usr/local/bin/playwright \
    && ln -s /opt/worldgraph/node_modules/.bin/sass /usr/local/bin/sass

COPY docker/node/entrypoint.sh /usr/local/bin/worldgraph-node-entrypoint
RUN chmod 0755 /usr/local/bin/worldgraph-node-entrypoint \
    && mkdir -p /app/node_modules \
    && chown node:node /app/node_modules
ENTRYPOINT ["worldgraph-node-entrypoint"]

WORKDIR /app
USER node
CMD ["sleep", "infinity"]
