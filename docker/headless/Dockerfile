FROM node:20-bookworm

WORKDIR /opt/worldgraph-headless
COPY headless/package.json headless/package-lock.json ./
RUN npm ci

COPY docker/headless/entrypoint.sh /usr/local/bin/worldgraph-headless-entrypoint
RUN chmod 0755 /usr/local/bin/worldgraph-headless-entrypoint
ENTRYPOINT ["worldgraph-headless-entrypoint"]

WORKDIR /app/headless
CMD ["npm", "run", "dev", "--", "--hostname", "0.0.0.0", "--port", "3000"]
