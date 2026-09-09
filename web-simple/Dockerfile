# syntax=docker/dockerfile:1.7

FROM node:22-alpine AS dependencies
WORKDIR /app
ENV NEXT_TELEMETRY_DISABLED=1
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts

FROM dependencies AS source
COPY . .
RUN npm run db:generate

FROM source AS builder
ENV NODE_ENV=production \
    NODE_OPTIONS=--max-old-space-size=1024 \
    DATABASE_URL=postgresql://build:build@127.0.0.1:5432/build?schema=public \
    APP_URL=http://localhost:3002 \
    BETTER_AUTH_URL=http://localhost:3002 \
    BETTER_AUTH_SECRET=build-only-secret-with-at-least-32-characters
RUN npm run build

FROM source AS tooling
ENV NODE_ENV=production
USER node
CMD ["npm", "run", "db:deploy"]

FROM node:22-alpine AS runner
WORKDIR /app
ENV NODE_ENV=production \
    NEXT_TELEMETRY_DISABLED=1 \
    NODE_OPTIONS=--max-old-space-size=384 \
    HOSTNAME=0.0.0.0 \
    PORT=3002

RUN addgroup --system --gid 1001 nodejs \
    && adduser --system --uid 1001 nextjs

COPY --from=builder --chown=nextjs:nodejs /app/.next/standalone ./
COPY --from=builder --chown=nextjs:nodejs /app/.next/static ./.next/static
RUN mkdir -p storage/evidence && chown -R nextjs:nodejs storage

USER nextjs
EXPOSE 3002
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
  CMD ["node", "-e", "fetch('http://127.0.0.1:3002/api/health').then(r=>{if(!r.ok)process.exit(1)}).catch(()=>process.exit(1))"]
CMD ["node", "server.js"]
