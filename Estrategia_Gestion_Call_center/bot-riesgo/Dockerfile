FROM node:20-alpine

WORKDIR /app

# Las dependencias se instalan antes de copiar el código: así un cambio en
# src/ no invalida la capa de npm ci.
COPY package.json package-lock.json* ./
COPY prisma ./prisma
RUN npm install --omit=dev && npx prisma generate

COPY src ./src

ENV NODE_ENV=production
EXPOSE 3010

# La migración corre en el arranque: el contenedor puede levantar antes de que
# Postgres termine de inicializar, y `migrate deploy` es idempotente.
CMD ["sh", "-c", "npx prisma migrate deploy && node src/server.js"]
