# Despliegue en un VPS de Hostinger

Guía para publicar **Chat** en un VPS con Ubuntu 24.04, Nginx, PHP 8.3,
MySQL 8, Redis y Supervisor. La SPA y la API comparten el mismo dominio, de
modo que la cookie de sesión es `first party` y la protección CSRF funciona
sin configuración adicional.

## 1. Requisitos del servidor

| Servicio | Versión mínima | Uso |
| --- | --- | --- |
| PHP | 8.3 | Laravel 13 |
| MySQL | 8.0 | Mensajes, usuarios, denuncias |
| Redis | 6.0 | Sesiones, cola, presencia, caché |
| Nginx | 1.24 | TLS, estáticos, proxy de la API y del WebSocket |
| Supervisor | 4.0 | `queue:work` y `reverb:start` |
| Node.js | 20 LTS | Compilación de la SPA (solo en el despliegue) |

## 2. Paquetes del sistema

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx git curl unzip \
    php8.3-fpm php8.3-cli php8.3-mysql php8.3-redis php8.3-mbstring \
    php8.3-xml php8.3-curl php8.3-zip php8.3-gd php8.3-bcmath php8.3-intl \
    mysql-server redis-server supervisor
```

Si compilas PHP desde código, instala además las dependencias de
compilación (`php8.3-dev`, `libxml2-dev`, `libonig-dev`, `libzip-dev`,
`libsqlite3-dev`) y activa las extensiones:

```bash
sudo phpenmod php8.3-mysql php8.3-redis php8.3-mbstring php8.3-xml \
    php8.3-curl php8.3-zip php8.3-gd php8.3-bcmath php8.3-intl
```

## 3. MySQL

```bash
sudo mysql -u root
```

```sql
CREATE DATABASE chat CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'chat'@'localhost' IDENTIFIED BY 'una-contraseña-larga';
GRANT ALL PRIVILEGES ON chat.* TO 'chat'@'localhost';
FLUSH PRIVILEGES;
```

## 4. Redis

```bash
sudo systemctl enable --now redis-server
sudo nano /etc/redis/redis.conf
```

Deja el Redis accesible solo desde localhost:

```
bind 127.0.0.1 ::1
protected-mode yes
```

## 5. El código

```bash
sudo mkdir -p /var/www/chat
sudo chown -R www-data:www-data /var/www/chat
cd /var/www/chat
git clone <url-del-repositorio> .
```

Estructura esperada:

```
/var/www/chat/
├── backend/     # Aplicación Laravel
├── frontend/    # SPA de Vue
└── deploy/      # Configuración de Nginx y Supervisor
```

## 6. El backend

```bash
cd /var/www/chat/backend
cp .env.production.example .env
php artisan key:generate
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan storage:link
chown -R www-data:www-data storage bootstrap/cache
```

Edita `/var/www/chat/backend/.env` y define, como mínimo:

| Variable | Valor |
| --- | --- |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://chat.example.com` |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Credenciales de MySQL |
| `SESSION_SECURE_COOKIE` | `true` (obligatorio detrás de HTTPS) |
| `SANCTUM_STATEFUL_DOMAINS` | `chat.example.com,www.chat.example.com` |
| `CORS_ALLOWED_ORIGINS` | Vacío si la SPA y la API comparten dominio |
| `REVERB_APP_KEY`, `REVERB_APP_SECRET` | Valores generados |
| `REVERB_HOST` | `chat.example.com` |
| `REVERB_PORT` | `443` |
| `REVERB_SCHEME` | `https` |
| `MAIL_*` | Credenciales del SMTP real |

Genera las credenciales de Reverb:

```bash
php artisan reverb:install
```

### Sesión, cookies y HTTPS

La autenticación usa cookies de sesión de Sanctum, así que estos tres valores
deben ser coherentes entre sí:

- `SESSION_SECURE_COOKIE=true` exige que el sitio se sirva por HTTPS. Si se
  activa mientras Nginx solo responde por HTTP, el navegador descarta la cookie
  y el login falla con `401`/`419`. Para volver temporalmente a HTTP hay que
  ponerlo en `false`.
- `SESSION_DOMAIN=null` deja la cookie ligada al host exacto, que es lo correcto
  cuando la SPA y la API comparten dominio.
- `SANCTUM_STATEFUL_DOMAINS` debe contener el host real, sin esquema y con el
  puerto solo si no es el de por defecto (`chat.example.com`, no
  `https://chat.example.com`). Si falta, Sanctum no aplica la autenticación
  stateful y las peticiones autenticadas responden `401`.

`bootstrap/app.php` ya confía en todos los proxies (`trustProxies(at: '*')`), de
modo que Laravel detecta el esquema `https` que anuncia Nginx. Tras cualquier
cambio en `.env` hay que regenerar la caché de configuración:

```bash
php artisan config:clear
php artisan config:cache
```

Solo si la SPA se publica en un origen distinto de la API hay que rellenar
`CORS_ALLOWED_ORIGINS` con ese origen exacto. En el despliegue descrito aquí
Nginx sirve ambos bajo el mismo dominio y la variable queda vacía.

## 7. La SPA

```bash
cd /var/www/chat/frontend
cp .env.example .env
npm ci
npm run build
```

El `.env` de la SPA solo lleva valores públicos:

```
VITE_APP_URL=https://chat.example.com
VITE_REVERB_APP_KEY=<la clave pública de Reverb>
VITE_REVERB_HOST=chat.example.com
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

> La clave secreta de Reverb nunca sale del backend. Solo la clave pública
> viaja al navegador.

## 8. Nginx y el certificado TLS

```bash
sudo cp /var/www/chat/deploy/nginx.conf /etc/nginx/sites-available/chat
sudo ln -s /etc/nginx/sites-available/chat /etc/nginx/sites-enabled/chat
sudo nginx -t && sudo systemctl reload nginx
```

Certificado con Certbot:

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d chat.example.com -d www.chat.example.com
```

Certbot reescribe el bloque de HTTP para redirigir a HTTPS.

## 9. Supervisor

```bash
sudo cp /var/www/chat/deploy/supervisor.conf /etc/supervisor/conf.d/chat.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

Deben aparecer `chat-queue` y `chat-reverb` en estado `RUNNING`.

## 10. Comprobaciones

```bash
curl -I https://chat.example.com            # 200 y la cabecera Strict-Transport-Security
curl https://chat.example.com/up             # devuelve healthy
sudo supervisorctl status                   # los dos procesos en RUNNING
php artisan reverb:start --help             # confirma la instalación
```

En el navegador, abre las herramientas de red y comprueba que:

- la respuesta de `POST /api/auth/login` lleva la cabecera `Set-Cookie` con
  `Secure`, `HttpOnly` y `SameSite=Lax`;
- la conexión del WebSocket a `wss://chat.example.com/app` se establece;
- `GET /api/auth/csrf` devuelve el token que viaja en `X-XSRF-TOKEN`.

## 11. Actualizaciones

```bash
cd /var/www/chat
sudo ./deploy/deploy.sh
```

El script instala las dependencias, recompila la SPA, cachea la
configuración, ejecuta las migraciones y reinicia los trabajadores.

## 12. Copias de seguridad

```bash
# Copia diaria de MySQL a las 3:07 de la madrugada
sudo crontab -e
```

```
7 3 * * * mysqldump --single-transaction --quick chat | gzip > /var/backups/chat-$(date +\%F).sql.gz
```

```bash
# Redis guarda las sesiones y la presencia, no datos críticos.
sudo systemctl is-enabled redis-server
```

## 13. Límites y ajustes recomendados

- `APP_DEBUG=false` en producción: nunca expongas la traza de excepciones.
- `SESSION_SECURE_COOKIE=true` es obligatorio detrás de HTTPS.
- Los límites de peticiones (`RATE_LIMIT_*`) protegen el registro, el
  inicio de sesión y la subida de imágenes.
- `php artisan queue:work` necesita el proceso en ejecución para entregar
  los mensajes en tiempo real: sin él, Reverb no recibe nada.
- `trustProxies` está activo, así que el IP real del cliente se usa para
  la presencia y los límites de peticiones detrás de Nginx.

## 14. Solución de problemas

| Síntoma | Causa probable | Comprobación |
| --- | --- | --- |
| El chat no se actualiza en tiempo real | El trabajador de la cola está parado | `sudo supervisorctl status` |
| Error 419 al enviar mensajes | La cookie `XSRF-TOKEN` no llega | Revisa `SESSION_DOMAIN` y `SESSION_SECURE_COOKIE` |
| Error 401 constante | La sesión no se guarda | Comprueba `SESSION_DRIVER=redis` y que Redis está en marcha |
| Las imágenes no cargan | Enlace simbólico ausente | `php artisan storage:link` |
| El PWA no actualiza | El `sw.js` está cacheado | Nginx debe servirlo con `Cache-Control: no-cache` |
| El WebSocket se desconecta | Nginx no reenvía el `Upgrade` | Revisa el bloque `location /app` |
