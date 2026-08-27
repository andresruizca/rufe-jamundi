# INSTRUCCIÓN PARA CLAUDE OPUS 5 (VS Code + Claude Dev)
## Proyecto: Instalación de WaSphere + Módulo Chatbot/Team Inbox vía SSH

---

## ROL Y CONTEXTO

Actúa como un ingeniero **DevOps + Full Stack Senior** especializado en despliegues Docker sobre Proxmox, Node.js/NestJS, PostgreSQL y APIs de WhatsApp. Vas a asistirme en la instalación completa de **WaSphere** (plataforma open source self-hosted de WhatsApp API, MIT license, stack: WA Server en NestJS+Baileys, Dashboard API en NestJS+Prisma, Dashboard UI en Next.js, PostgreSQL) sobre un **contenedor Proxmox (LXC) "virgen"**, accesible por SSH, para operar un **chatbot con módulo de atención humana multiagente** (3 agentes atendiendo bajo el mismo número de WhatsApp).

Todo el trabajo se hace **vía SSH**, ejecutando comandos reales, verificando cada paso antes de avanzar al siguiente, y deteniéndote a pedirme confirmación antes de cualquier acción destructiva o irreversible (borrar volúmenes, recrear bases de datos, sobrescribir `.env`, etc.).

Datos del contenedor donde deberás instalar todo el sistema
Conexión vía SSH
Usuario: root
Contraseña: Oficinatic2026.$
Ip del server contenedor: 10.214.80.12

---

## ALCANCE EXACTO (léelo con atención, no te salgas de aquí)

1. **NO copies el proyecto completo** `isp-manager` (ubicado localmente en `/Users/andresruizcadavid/Documents/PROYECTOS 13 - Visual Code/isp-manager`). Ese proyecto es solo una **referencia de consulta**.
2. Dentro de `isp-manager`, revisa **única y exclusivamente**:
   - Los archivos y carpetas relacionados con **WaSphere** (configuración, `.env`, `docker-compose.yml`, variables, webhooks, sesiones).
   - El **módulo/complemento de Chatbot** (su lógica funcional: máquina de estados, flujos de conversación, manejo de intents, triggers, handoff a humano, integración con backend del ISP si aplica).
3. **NO repliques el flujo de negocio tal cual.** El chatbot de `isp-manager` es del dominio ISP (facturación, soporte técnico, etc.) y **no aplica** al nuevo caso de uso (Gestión del Riesgo). Tómalo como **modelo de arquitectura y buenas prácticas**: cómo está estructurado el state machine, cómo maneja el webhook, cómo separa lógica de bot vs. handoff a agente humano, cómo maneja errores/reintentos, cómo persiste contexto de conversación. Con esa estructura como plantilla, construye una lógica nueva y propia para el caso de **Gestión del Riesgo** (aún por definir contenido/flujos conmigo en una fase posterior — por ahora deja el esqueleto funcional listo para parametrizar).
4. **El número de teléfono de WhatsApp NO se configura ahora.** Deja ese paso explícitamente pendiente y señalado en el `.env` (`WA_TOKEN`/sesión) para vincularlo después vía escaneo de QR desde el Dashboard.
5. Los **3 agentes deben trabajar directamente desde el Dashboard/Team Inbox de WaSphere** (no desde una herramienta externa). Debes dejar configurado el sistema de **Team & Roles** nativo de WaSphere para que los 3 agentes puedan ver y responder la bandeja compartida bajo el mismo número.

---

## DESTINO DE LA INSTALACIÓN

- Infraestructura: **contenedor LXC en Proxmox**, actualmente "virgen" (recién creado, sin Docker ni dependencias).
- Acceso: **SSH** ya disponible.
- Antes de instalar nada, verifica y — si falta — configura lo siguiente **a nivel de Proxmox host** (esto normalmente requiere acceso al host Proxmox, no solo al contenedor; si no tienes ese acceso, dime qué comandos debo correr yo en el host):
  - El contenedor debe tener **`nesting=1`** habilitado en sus features — sin esto, Docker no puede crear namespaces propios y el daemon no arranca.
  - Se recomienda también **`keyctl=1`** — necesario en contenedores no privilegiados para operaciones de keyring que usan `containerd`/`runc` durante el arranque; sin él aparecen errores de permisos.
  - Verifica esto desde el host con `pct config <CTID>` y, si falta, aplícalo con `pct set <CTID> -features nesting=1,keyctl=1` seguido de `pct restart <CTID>`.
  - Si tras esto el storage backend da problemas con `overlay2`, evalúa `fuse-overlayfs` como driver de almacenamiento para Docker dentro del LXC.
- Sistema operativo esperado dentro del contenedor: Debian 12 o Ubuntu 22.04/24.04 LTS.

---

## FASES DE EJECUCIÓN (sigue este orden, valida cada fase antes de continuar)

### Fase 0 — Diagnóstico del contenedor
Conéctate por SSH y verifica: distro/versión, RAM/CPU/disco disponibles, si `nesting`/`keyctl` están activos (puedes probarlo intentando iniciar el servicio Docker tras instalarlo), conectividad a internet saliente, y si hay puertos ya en uso (necesitarás liberar/mapear 3000, 3001, 3004, y 5432 para Postgres si no usas red interna de Docker exclusivamente).

### Fase 1 — Instalación de dependencias base
Instala Docker Engine (versión reciente, 24+) y Docker Compose plugin (v2.20+) siguiendo el método oficial de `get.docker.com` o el repositorio oficial de Docker para la distro detectada. Verifica con `docker --version` y `docker compose version`. Instala también `git`, `curl`, `openssl` (necesario para generar secretos) si no están presentes.

### Fase 2 — Obtención del código de WaSphere
Clona el repositorio oficial de WaSphere (`github.com/wasphere/wasphere`) en una ruta de tu elección dentro del contenedor (sugerido: `/opt/wasphere`). Confirma que existan `docker-compose.yml` y `.env.example` en la raíz.

### Fase 3 — Revisión del proyecto de referencia (`isp-manager`)
Antes de configurar el `.env` de producción, revisa **solo** los archivos relacionados con WaSphere y con el módulo de Chatbot dentro de `isp-manager` (ruta local ya indicada). Extrae de ahí — como **inspiración estructural, no como copia literal**:
- Cómo está organizado el manejo de webhooks entrantes (firma HMAC, verificación de `WEBHOOK_SIGNING_SECRET`).
- Cómo se separa la lógica del bot automático del handoff a agente humano.
- Cómo se persiste el estado/contexto de la conversación (base de datos, tabla de sesiones, TTL, etc.).
- Buenas prácticas de logging, manejo de errores y reintentos que quieras conservar.

Documenta brevemente (en un `NOTES.md` dentro del nuevo proyecto) qué patrones tomaste como referencia y por qué, sin pegar código textual del proyecto ISP salvo utilidades genéricas realmente reutilizables (helpers de firma de webhook, cliente HTTP, etc.).

### Fase 4 — Configuración del `.env` de producción
Copia `.env.example` a `.env` y complétalo así:
- `POSTGRES_USER`, `POSTGRES_DB`: usa `wasphere` o un nombre acorde al proyecto (ej. `wasphere_riesgo`).
- `POSTGRES_PASSWORD`: genera una contraseña fuerte con `openssl rand -hex 16`. Nunca dejes `changeme`.
- `DATABASE_URL`: ármalo como `postgresql://<user>:<password>@postgres:5432/<db>` (dentro de Docker, el host es el nombre del servicio, `postgres`).
- `JWT_SECRET`, `ENCRYPTION_KEY` (debe ser de exactamente 64 caracteres hex), `WA_TOKEN`, `WEBHOOK_SIGNING_SECRET`, `INTERNAL_WEBHOOK_SECRET`: genera cada uno por separado con `openssl rand -hex 32`. Nunca reutilices el mismo valor para dos variables distintas.
- `CORS_ORIGIN` / `DASHBOARD_UI_URL`: usa la IP o dominio interno con el que accederás al Dashboard (ej. `http://<IP-del-LXC>:3004` o el dominio si hay reverse proxy).
- `SESSIONS_DIR`: confirma que quede mapeado a un volumen Docker persistente para no perder la sesión de WhatsApp ante reinicios.
- Deja explícitamente **sin vincular** la sesión de WhatsApp (no escanees el QR todavía) — eso se hace en una fase posterior cuando yo indique el número definitivo.

Recuérdame guardar estos secretos en un gestor seguro (no solo en el `.env` del servidor) y confirma que `.gitignore` excluya el `.env` antes de cualquier `git add`.

### Fase 5 — Levantamiento del stack
Ejecuta `docker compose up -d`, verifica que los 4 servicios (postgres, wa-server, dashboard-api, dashboard-ui) estén healthy con `docker compose ps` y revisa logs de cada uno (`docker compose logs -f <servicio>`) buscando errores de conexión a base de datos o variables faltantes.

### Fase 6 — Registro del primer usuario y configuración del equipo (3 agentes)
- Accede al Dashboard UI (puerto 3004) y registra la **primera cuenta**, que automáticamente queda como **Owner** (el registro se cierra después de esta primera cuenta).
- Ve a **Dashboard → Team** y crea un **rol personalizado** para los agentes, por ejemplo `"Atención Riesgo"` con las capabilities `inbox` y `contacts` (esto les permite ver y responder la bandeja compartida y gestionar contactos, sin darles acceso a `settings`, `api_keys`, `webhooks` ni `sessions`, que deben quedar reservados al Owner/Admin).
- Genera las **3 invitaciones** (una por agente) con tier `MEMBER` (Agent) y el rol personalizado creado. Cada invitación expira en 7 días; comparte el link manualmente si no hay SMTP configurado.
- Verifica que los 3 agentes, una vez aceptada la invitación, vean la misma bandeja (Inbox) del número que se vinculará después.

### Fase 7 — Estructura del módulo de Chatbot (sin lógica de negocio final)
Con base en lo revisado en la Fase 3, crea el **esqueleto funcional** del chatbot para "Gestión del Riesgo": receptor de webhook firmado (HMAC con `WEBHOOK_SIGNING_SECRET`), state machine base (estado inicial, estado "esperando respuesta", estado "handoff a agente"), tabla/modelo de persistencia de conversación en PostgreSQL (puede ser la misma instancia o un esquema separado), y el punto de integración donde después conectaremos los flujos reales de contenido (aún pendientes de definir conmigo). No implementes reglas de negocio de gestión del riesgo todavía — solo la arquitectura reutilizable.

### Fase 8 — Verificación final y checklist de entrega
Al cerrar, entrégame un resumen con: URLs de acceso (Dashboard UI, Dashboard API), estado de los 4 contenedores, ubicación del `.env` y confirmación de que los secretos son únicos y fuertes, rol y estado de las 3 invitaciones de agentes, y una lista explícita de **lo que falta** para producción (vincular número real vía QR, definir flujos de contenido del chatbot de Gestión del Riesgo, configurar dominio/HTTPS si aplica, backups de PostgreSQL).

---

## REGLAS DE TRABAJO

- Antes de cualquier comando que borre datos, sobrescriba `.env`, o reinicie contenedores en producción, **pregúntame primero**.
- Muestra siempre el comando exacto que vas a ejecutar antes de ejecutarlo.
- Si algo falla (por ejemplo, Docker no arranca por falta de `nesting`), diagnostica la causa raíz antes de aplicar soluciones agresivas (contenedores privilegiados, deshabilitar AppArmor) — prefiere siempre la opción menos invasiva primero.
- Si detectas que el contenedor Proxmox no tiene suficientes recursos (mínimo recomendado: 2 vCPU, 2–4 GB RAM, 20+ GB disco) para correr los 4 servicios, avísame antes de continuar.
- No asumas datos que no te he dado (dominio público, si habrá reverse proxy/HTTPS, nombres exactos de los 3 agentes) — pregúntamelos cuando los necesites.
