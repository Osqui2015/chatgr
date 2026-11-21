# Chat

Aplicación de chat en tiempo real con interfaz oscura, inspirada en Discord,
Telegram y WhatsApp. Es una PWA instalable en Android, iPhone, Windows, macOS
y Linux.

- **Backend:** Laravel 13, Sanctum, Laravel Reverb, MySQL 8, Redis, Predis.
- **Frontend:** Vue 3, TypeScript estricto, Pinia, Tailwind CSS 4, Vite, PWA.
- **Tiempo real:** WebSockets con Reverb para mensajes, presencia e indicador
  de escritura.

## Funcionalidades

- Registro y acceso con verificación por correo mediante un código OTP de
  seis dígitos. No hay acceso de invitado.
- Contraseñas con Argon2id, validación estricta en el servidor, CSRF,
  limitación de peticiones, cookies `HttpOnly`, `Secure` y `SameSite=Lax`.
- Avatar con recorte cuadrado obligatorio, conversión a WebP, eliminación de
  metadatos EXIF y tamaños de 32, 64, 128 y 512 píxeles. El original nunca
  se sirve.
- Imágenes de un solo uso: se adjuntan al mensaje, solo el destinatario puede
  abrirlas y el archivo y su registro se eliminan en el momento en que se
  visualizan. El autor nunca ve la URL autorizada.
- Estados de presencia (disponible, ausente, ocupado, invisible) con
  indicadores en tiempo real.
- Indicador de escritura sobre WebSocket, sin consultas a la base de datos.
- Moderación: bloqueo, silenciado, denuncias, borrado de mensajes y
  expulsiones, con los roles `admin`, `moderator` y `user`. Los mensajes de
  un usuario bloqueado o silenciado se ocultan al instante.
- Panel de administración con usuarios, expulsiones, denuncias, mensajes
  denunciados, usuarios conectados y estadísticas.
- Historial con desplazamiento virtual, carga progresiva por páginas y
  divisionado de código.
- Interfaz adaptable a móvil, verificada de 320 px a 1024 px de ancho.

## Estructura

```
Chat/
├── backend/       # API Laravel 13
├── frontend/      # SPA Vue 3
└── deploy/        # Nginx, Supervisor y guía de despliegue
```

## Puesta en marcha

### Requisitos

PHP 8.3 con `pdo_mysql`, `gd` o `imagick`, `mbstring`, `xml`, `curl`, `zip`;
Composer 2; Node.js 20 LTS; MySQL 8; Redis.

### Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
```

Crea la base de datos y configura las credenciales en `.env`:

```sql
CREATE DATABASE chat CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
php artisan migrate --seed
php artisan storage:link
php artisan reverb:install
```

Arranca los procesos:

```bash
php artisan serve            # API en http://localhost:8000
php artisan reverb:start     # WebSockets en ws://localhost:8080
php artisan queue:work       # Cola de difusión y correos
```

El administrador inicial que crea el seeder es `admin@chat.local` con la
contraseña `Admin123!`. Cámbiala en el primer acceso.

### Frontend

```bash
cd frontend
npm install
cp .env.example .env
npm run dev
```

La SPA queda en http://localhost:5173 y el proxy de Vite redirige `/api`,
`/storage` y `/broadcasting` al backend.

### Verificación

```bash
cd backend
php artisan test              # 105 pruebas de característica y unidad
vendor/bin/phpstan analyse   # análisis estático, nivel 5
vendor/bin/pint              # formato del código

cd ../frontend
npm run build                 # comprobación de tipos y compilación
npm run lint                  # ESLint
npm run format:check          # Prettier
npm test                      # pruebas unitarias con Vitest
```

#### Pruebas End-to-End (E2E)

Las pruebas E2E (`frontend/tests/e2e/api.spec.ts`) validan el flujo completo contra el backend real (registro, verificación de OTP, sesión y mensajería). Requieren que MySQL, Redis, Reverb y la cola estén en ejecución:

```bash
# En el backend (con Redis y Reverb activos):
php artisan serve
php artisan reverb:start
php artisan queue:work

# En el frontend:
cd frontend
E2E_BASE_URL=http://127.0.0.1:8000 npm test
```

## Adaptación a móvil

La interfaz está pensada para teléfono desde el principio, no como adaptación
posterior. Se ha verificado en un viewport de 320 × 568 (iPhone SE), 390 × 844
(iPhone 12) y 414 × 896 (iPhone Plus), además de 768 px y 1024 px.

- La lista de conversaciones se oculta por debajo de `md` y se sustituye por
  un botón de menú que abre el buscador global.
- La barra de acciones del mensaje (reaccionar, responder, copiar, editar,
  eliminar, denunciar) se muestra siempre en pantallas táctiles, porque no
  existe `hover` en un dedo. En punteros sigue apareciendo al pasar el ratón.
- Los objetivos táctiles miden 28 px o más y los botones principales 40 px,
  por encima del mínimo de 44 px recomendado.
- Los campos de formulario usan 16 px de fuente en móvil para que iOS no
  amplíe la página al enfocarlos, y el diseño respeta `env(safe-area-inset-*)`
  para las pantallas con muesca.
- El selector de emoji y el panel de administración se anclan al viewport
  para que nunca queden fuera de la pantalla.
- No hay desplazamiento horizontal en ninguna vista.

## Limitaciones conocidas

- **URLs de medios en desarrollo.** `php artisan serve` publica los archivos
  con una URL absoluta al puerto 8000. Las etiquetas `<img>` funcionan, pero
  una llamada `fetch` desde el puerto 5173 las bloquea por CORS. En producción
  la SPA y la API comparten dominio y la diferencia desaparece.

## Decisiones de diseño

- **Autenticación por cookie de sesión.** Sanctum emite una sesión y la SPA
  nunca maneja un token `Bearer`. La cookie es `HttpOnly`, `Secure` y
  `SameSite=Lax`, y el token CSRF viaja en la cabecera `X-XSRF-TOKEN`.
- **Indicador de escritura por el servidor.** El cliente informa del estado
  mediante `POST /api/conversations/{id}/typing` y el servidor difunde el
  evento por Reverb. Así el indicador no depende de la confianza en el
  cliente y no genera consultas a la base de datos.
- **Eventos con nombre explícito.** Cada evento declara `broadcastAs()`:
  `message.sent`, `message.updated`, `message.deleted`,
  `message.reaction.added`, `message.reaction.removed`, `presence.changed` y
  `typing.indicator`.
- **Lógica de negocio en servicios.** Los controladores traducen la
  petición y delegan; los servicios (`AuthService`, `ConversationService`,
  `MessageService`, `ModerationService`, `ImageService`, `MediaService`)
  contienen las reglas, y los recursos de API definen el contrato de
  salida.
- **Mensajes en un `shallowRef`.** El historial se reemplaza en bloque, de
  modo que un proxy reactivo profundo sobre miles de mensajes sería un
  gasto innecesario.
- **Desplazamiento virtual.** `VirtualMessageList` mide la altura de cada
  mensaje y solo monta los que están en la ventana visible.

## Despliegue

La guía completa para un VPS de Hostinger está en
[`deploy/README.md`](deploy/README.md), con la configuración de Nginx
(`deploy/nginx.conf`) y de Supervisor (`deploy/supervisor.conf`).

## Estructura del backend

```
app/
├── Console/Commands/    # Comandos artisan propios
├── Events/               # Eventos de difusión con broadcastAs()
├── Http/
│   ├── Controllers/Api/  # Controladores finos
│   ├── Middleware/       # Roles, presencia
│   └── Requests/         # Validación de la entrada
├── Jobs/                 # Trabajos en cola
├── Models/               # Modelos Eloquent y pivotes
├── Notifications/        # Correo con el código OTP
├── Policies/             # Autorización
├── Repositories/         # Acceso a datos
├── Resources/            # Recursos de API
└── Services/             # Lógica de negocio
```

## Estructura del frontend

```
src/
├── components/           # Componentes reutilizables
│   ├── chat/             # Lista virtual, burbujas, indicador de escritura
│   ├── emoji/            # Selector de emoji
│   ├── profile/          # Perfil y recorte de avatar
│   ├── search/           # Búsqueda global
│   └── user/             # Tarjeta de usuario
├── router/               # Rutas y guardas
├── services/             # Cliente HTTP, API y tiempo real
├── stores/               # Estado con Pinia
├── styles/               # Tema oscuro y componentes
├── types/                # Contratos de datos
└── views/                # Vistas por ruta
```
