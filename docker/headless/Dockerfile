FROM node:20-bookworm

ENV NEXT_TELEMETRY_DISABLED=1

WORKDIR /opt/worldgraph-headless
COPY headless/package.json headless/package-lock.json ./
RUN npm ci

COPY docker/headless/entrypoint.sh /usr/local/bin/worldgraph-headless-entrypoint
RUN chmod 0755 /usr/local/bin/worldgraph-headless-entrypoint \
    && mkdir -p /app/headless/node_modules /app/headless/.next \
    && chown -R node:node /app/headless
ENTRYPOINT ["worldgraph-headless-entrypoint"]

WORKDIR /app/headless
USER node
CMD ["npm", "run", "dev", "--", "--hostname", "0.0.0.0", "--port", "3000"]
