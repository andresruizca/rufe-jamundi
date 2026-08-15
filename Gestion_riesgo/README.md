# Sistema de Gestión del Riesgo — Jamundí

Plataforma de gestión del riesgo de desastres de la Alcaldía Municipal de
Jamundí. Integra el tablero en vivo del RUFE (Registro Único de Familias
Evacuadas) con la administración de usuarios y la trazabilidad de las
actualizaciones del sistema.

| | |
|---|---|
| Aplicación | <https://sgr.oticjamundi.com> |
| API | <https://api-sgr.oticjamundi.com> |
| Tablero RUFE original | <https://grj.oticjamundi.com> (sigue publicado aparte) |

---

## Arquitectura

El sistema está separado en dos piezas que se despliegan y evolucionan por
separado:

```
Gestion_riesgo/
├── backend/     API REST en PHP 8 + MySQL   → api-sgr.oticjamundi.com
└── frontend/    SvelteKit 2 (build estático) → sgr.oticjamundi.com
```

El navegador es el único que habla con las dos: descarga la aplicación del
frontend y esta consume la API por HTTPS con un token de sesión.

### Por qué este stack

**El backend no usa Composer ni un framework.** El hosting es cPanel compartido
sin acceso por consola, así que `composer install` no se puede ejecutar en el
servidor y subir el `vendor/` de un framework serían decenas de megabytes por la
API de cPanel en cada despliegue. Un autoloader PSR-4 de diez líneas cubre la
misma necesidad: el despliegue completo pesa 28 KB y arranca sin cargar nada más.

**La autenticación usa un token opaco, no JWT.** Un JWT no se puede revocar sin
mantener igualmente una lista en base de datos. Aquí el token es un valor
aleatorio de 256 bits cuyo SHA-256 se guarda en `sesiones`: desactivar a una
persona o cambiarle el rol cierra sus sesiones en el acto, que es lo que exige un
sistema con datos de familias damnificadas.

**El frontend es una SPA sin pre-renderizado.** Lo que ve cada persona depende de
su sesión, que solo existe en el navegador; generar HTML en el build produciría
la pantalla de un usuario que no existe.

---

## Módulos

| Módulo | Ruta | Quién entra |
|---|---|---|
| Dashboard — tablero RUFE en vivo | `/dashboard` | Todos los roles |
| Acerca de — sistema y actualizaciones | `/acerca` | Todos los roles |
| Administración → Usuarios del sistema | `/admin/usuarios` | Solo Administrador |

### Dashboard

Es el tablero del RUFE reutilizado tal cual, con su identidad visual intacta.
Lee los datos en vivo del CSV público de la hoja de cálculo del censo
(`src/lib/rufe/source.ts`) directamente desde el navegador, con un respaldo
estático en `src/lib/data/rufe-fallback.json` por si la hoja deja de estar
compartida.

### Acerca de

Dos pestañas:

- **Sistema actual** — descripción, módulos, roles y sus permisos, tecnología de
  cada capa y estado real del servicio (conexión a la base, versión de PHP,
  usuarios activos, sesiones abiertas).
- **Actualizaciones del sistema** — historial de commits del repositorio en
  GitHub, atribuido a cada persona del equipo. Cada commit se asigna por el
  usuario de GitHub cuando viene (lo asigna GitHub, es fiable) y si no por el
  nombre del autor de git. Se puede filtrar la línea de tiempo por autor.

  La consulta a GitHub se cachea 5 minutos en la tabla `ajustes`; si GitHub falla,
  se sirve la caché vencida antes que dejar la pantalla vacía. El token de GitHub
  se usa solo desde el servidor y nunca viaja al navegador.

### Gestión de usuarios

Alta, edición, activación, restablecimiento de contraseña y eliminación. Las
salvaguardas que el rol por sí solo no cubre están en el controlador: un
administrador no puede cambiar su propio rol, desactivarse ni eliminarse, y el
sistema nunca se queda sin al menos un administrador activo.

---

## Roles

| Rol | Alcance |
|---|---|
| **Administrador** | Control total: lectura, escritura y gestión de usuarios. |
| **Gestor** | Carga de datos: lectura y escritura, sin acceso a usuarios. |
| **Visualización** | Solo visualizar los indicadores (KPI) y tableros (BI). |

El control de acceso se aplica en tres capas, y las tres derivan del mismo
registro para que no se desincronicen:

1. **Menú** — `frontend/src/lib/navigation.ts` filtra por rol lo que se dibuja.
2. **Rutas del navegador** — el mismo archivo alimenta la guardia del layout.
3. **API** — `backend/src/Core/Router.php` exige el rol en cada ruta. Esta es la
   única capa que cuenta como seguridad: ocultar un botón no protege nada.

---

## API

Base: `https://api-sgr.oticjamundi.com`. Autenticación con
`Authorization: Bearer <token>`.

| Método | Ruta | Rol |
|---|---|---|
| `GET` | `/health` | pública |
| `POST` | `/auth/login` | pública |
| `GET` | `/auth/me` | autenticado |
| `POST` | `/auth/logout` | autenticado |
| `POST` | `/auth/password` | autenticado |
| `GET` | `/acerca/sistema` | autenticado |
| `GET` | `/acerca/actualizaciones` | autenticado |
| `GET` | `/usuarios` | Administrador |
| `POST` | `/usuarios` | Administrador |
| `GET` | `/usuarios/{id}` | Administrador |
| `PUT` | `/usuarios/{id}` | Administrador |
| `DELETE` | `/usuarios/{id}` | Administrador |
| `POST` | `/usuarios/{id}/password` | Administrador |

Respuestas: `{ "ok": true, "data": … }` o
`{ "ok": false, "message": "…", "errors": { "campo": "…" } }`.

---

## Base de datos

Cuatro tablas (`backend/database/schema.sql`):

- `usuarios` — con el rol como ENUM; son tres roles fijos, no un catálogo que se
  administre, así que una tabla aparte solo añadiría un JOIN por petición.
- `sesiones` — token hasheado, expiración, IP y agente.
- `auditoria` — quién hizo qué y sobre qué registro.
- `ajustes` — clave/valor; hoy guarda la caché de GitHub.

---

## Desarrollo local

```bash
# Backend
cd backend
cp config.example.php config.php     # completar credenciales de MySQL
php -S localhost:8000 -t public

# Frontend
cd frontend
npm install
npm run dev                          # http://localhost:5173
```

El cliente de la API resuelve la URL base por dominio
(`frontend/src/lib/api/client.ts`): en `localhost` apunta a `localhost:8000` y en
cualquier otro sitio a producción. Así el mismo build sirve en los dos lados sin
recompilar.

Comprobaciones:

```bash
cd frontend && npm run check && npm test    # 0 errores de tipos, 46 pruebas
find backend -name '*.php' -exec php -l {} \;
```

---

## Despliegue

No hay SSH en el hosting. Todo se hace por la API de cPanel sobre HTTPS con el
header `Authorization: cpanel <usuario>:<token>`.

```bash
cd frontend && npm run build          # genera build/ con index.html y .htaccess
```

Luego, por cada destino:

1. Empaquetar en ZIP el contenido a subir.
2. `POST /execute/Fileman/upload_files` con `dir`, **`overwrite=1`** y el archivo.
   Sin `overwrite=1` cPanel rechaza en silencio los archivos que ya existen.
3. Extraer: `/json-api/cpanel?cpanel_jsonapi_module=Fileman&cpanel_jsonapi_func=fileop&op=extract`
   (la UAPI no expone extracción).
4. Borrar el ZIP con el mismo `fileop` pero `op=unlink`.

Rutas en el servidor:

| Qué | Dónde |
|---|---|
| Frontend | `/home1/gilibert/sgr.oticjamundi.com` |
| Backend | `/home1/gilibert/sgr_backend` (docroot: `public/`) |
| Configuración | `/home1/gilibert/sgr_backend/config.php` — **fuera** del docroot |

El código PHP y `config.php` viven un nivel por encima del directorio público, así
que no son descargables ni aunque Apache dejara de interpretar PHP.

### Instalación inicial

`backend/public/instalar.php` crea las tablas y el primer administrador. Se
protege con `install_key` de `config.php`, se niega a actuar si esa clave está
vacía y solo crea un administrador si todavía no existe ninguno. **Tras instalar
se borra del servidor** — no forma parte del despliegue normal.

---

## Notas de seguridad

- Contraseñas con `password_hash`/bcrypt; mínimo 10 caracteres.
- El login responde igual ante un correo inexistente y una contraseña incorrecta,
  y hace la verificación contra un hash falso cuando el usuario no existe, para
  que el tiempo de respuesta no delate qué correos están registrados.
- Cambiar la contraseña cierra las demás sesiones de esa persona.
- CORS con lista blanca de orígenes; nunca se refleja un origen arbitrario.
- Los errores 500 no exponen el detalle en producción: se registran en el log.
- La bitácora de auditoría nunca interrumpe la operación que la origina.
