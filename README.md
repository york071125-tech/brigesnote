# 🏫 Bridges Note - MINEDU ESCALE Bridge API

Microservicio y API REST en **Laravel 13** (PHP **8.3**) diseñado para sincronizar, estructurar y servir el catálogo oficial de instituciones educativas (colegios) y la división política/geográfica (regiones, provincias y distritos) del Perú, consumiendo directamente la API pública oficial de **MINEDU / ESCALE** (*Estadística de la Calidad Educativa*).

---

## 📋 Tabla de Contenidos

1. [Propósito del Proyecto](#-propósito-del-proyecto)
2. [Arquitectura y Flujo de Datos](#-arquitectura-y-flujo-de-datos)
3. [Requisitos del Sistema](#-requisitos-del-sistema)
4. [Instalación y Puesta en Marcha](#-instalación-y-puesta-en-marcha)
5. [Configuración de Entorno (.env)](#-configuración-de-entorno-env)
6. [Sincronización de Datos (Consola Artisan)](#-sincronización-de-datos-consola-artisan)
7. [Documentación de la API REST](#-documentación-de-la-api-rest)
8. [Estructura del Proyecto](#-estructura-del-proyecto)
9. [Pruebas Automatizadas](#-pruebas-automatizadas)
10. [Migración a PHP 8.3 / Laravel 13](#-migración-a-php-83--laravel-13)
11. [Solución de Problemas Frecuentes](#-solución-de-problemas-frecuentes)

---

## 🎯 Propósito del Proyecto

Servir como un **puente de datos optimizado** para aplicaciones cliente (sistemas de admisión universitaria, plataformas de matrícula escolar, intranets, etc.), permitiéndoles autocompletar ubigeos y consultar colegios mediante consultas locales ultrarrápidas, sin depender de la latencia o disponibilidad de los servidores estatales.

---

## 🔄 Arquitectura y Flujo de Datos

```
[ API MINEDU / ESCALE ] 
 (escale.minedu.gob.pe)
          │
          ▼  (Comando: php artisan sync:locations)
[ Base de Datos Local MySQL (escalebridge) ]
  ├── regions
  ├── provinces
  ├── districts
  └── schools (con ubigeo / idDistrito)
          │
          ▼  (LocationController)
[ Endpoints API REST Local / Producción ]
  ├── GET /api/regiones
  ├── GET /api/provincias/{idRegion}
  ├── GET /api/distritos/{idProvince}
  └── GET /api/colegios/{ubigeo}
          │
          ▼
[ Clientes / Aplicaciones Externas ] (ej. admisionweb)
```

---

## 💻 Requisitos del Sistema

- **PHP:** `8.3` o superior (Laravel 13 soporta PHP 8.3, 8.4 y 8.5). Probado con PHP `8.3.36`.
  > ⚠️ **Importante:** PHP 7.4, 8.0, 8.1 y 8.2 **ya no funcionan**: el proyecto requiere `php ^8.3`.
- **Extensiones de PHP requeridas por Laravel 13:** `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `mbstring`, `openssl`, `pcre`, `pdo`, `pdo_mysql`, `session`, `tokenizer`, `xml`.
  - Recomendada (opcional): `intl`, que usan algunos comandos de diagnóstico como `php artisan db:show`.
- **Laravel Framework:** `13.x` (bloqueado en `13.42.0` en `composer.lock`).
- **Gestor de Paquetes:** Composer 2.x
- **Base de Datos:** MySQL 5.7+ / 8.0+ o MariaDB 10.3+ (probado con MySQL 8.0.46).
- **Servidor Web Local:** Laragon (con PHP 8.3), XAMPP con PHP 8.3 o `php artisan serve`.

---

## 🚀 Instalación y Puesta en Marcha

### 1. Clonar el repositorio
```bash
git clone https://github.com/york071125-tech/brigesnote.git
cd brigesnote
```

### 2. Instalar dependencias de Composer
Comprueba antes que la consola use PHP 8.3 o superior (`php -v`):
```bash
composer install
```

### 3. Configurar variables de entorno
Copia el archivo de ejemplo y genera la clave de cifrado:
```bash
cp .env.example .env
php artisan key:generate
```

### 4. Crear la Base de Datos
Crea una base de datos vacía en tu gestor MySQL (ej. HeidiSQL o phpMyAdmin):
- **Nombre:** `escalebridge`
- **Cotejamiento:** `utf8mb4_unicode_ci`

### 5. Ejecutar las Migraciones
Crea las tablas en la base de datos:
```bash
php artisan migrate
```

### 6. Sincronizar el catálogo escolar
Descarga la información oficial desde MINEDU:
```bash
php artisan sync:locations
```

### 7. Iniciar el servidor
```bash
php artisan serve
```
La aplicación quedará disponible en `http://127.0.0.1:8000`.

> ℹ️ Este proyecto no tiene carpeta `public/`: `index.php`, `.htaccess` y `web.config` están en la raíz. `bootstrap/app.php` define la raíz como *public path* y `server.php` dirige todo a `index.php`, por lo que `php artisan serve` funciona sin cambios en el despliegue de Apache/IIS.

---

## ⚙️ Configuración de Entorno (.env)

Asegúrate de tener estos valores configurados en tu archivo `.env`:

```env
APP_NAME="Bridges Note"
APP_ENV=local
APP_KEY=base64:...
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

# Conexión MySQL
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=escalebridge
DB_USERNAME=root
DB_PASSWORD=

# Controlador de sesión en archivo (no requiere tabla 'sessions' en BD)
SESSION_DRIVER=file
CACHE_DRIVER=file
QUEUE_CONNECTION=sync
```

---

## 📥 Sincronización de Datos (Consola Artisan)

El comando central del sistema es:

```bash
php artisan sync:locations
```

### ¿Cómo opera la sincronización?
1. **Regiones (`/padron/rest/regiones`):** Descarga los 25 departamentos del país con sus nombres, coordenadas (`pointX`, `pointY`) y nivel de `zoom`.
2. **Provincias (`/padron/rest/provincias?idRegion={id}`):** Por cada región, descarga sus provincias.
3. **Distritos (`/padron/rest/distritos?idProvincia={id}`):** Por cada provincia, descarga los distritos correspondientes.
4. **Colegios (`/padron/rest/instituciones?estados=1&ubigeo={idDistrito}`):** Por cada distrito, descarga las instituciones educativas activas y las almacena en la tabla `schools` vinculando el código modular (`codMod`) como llave primaria y asociando el `idDistrito`.

> ⏱️ **Nota:** La sincronización total recorre más de 1,800 distritos y 80,000 colegios, por lo que puede tomar varios minutos según la velocidad de conexión y respuesta del servidor del MINEDU.

> 🔁 El comando usa `updateOrCreate`, así que puede ejecutarse varias veces sin duplicar registros. Si se corta la conexión con ESCALE (por ejemplo `cURL error 6: Could not resolve host`), el comando se detiene; basta con volver a ejecutarlo.

> 📄 El comando guarda la primera página de resultados que devuelve ESCALE por distrito (hasta 50 colegios por distrito). Es el comportamiento original del proyecto y se mantiene sin cambios.

---

## 📡 Documentación de la API REST

Todos los endpoints retornan respuestas en formato JSON bajo el prefijo `/api/`.

Tipos de datos (verificados en PHP 7.4/Laravel 7 y en PHP 8.3/Laravel 13, idénticos):
- Los códigos (`idRegion`, `idProvincia`, `idDistrito`, `codMod`) son **cadenas** y conservan los ceros a la izquierda.
- `pointX` y `pointY` son **cadenas** con 7 decimales (columnas `DECIMAL(10,7)`); `zoom` es **entero**.
- Cada registro incluye `created_at` y `updated_at` en formato ISO‑8601 UTC.
- Las respuestas incluyen CORS (`Access-Control-Allow-Origin: *`) y un límite de 60 peticiones por minuto (`X-RateLimit-Limit: 60`).

Si no hay resultados, los endpoints con parámetro responden **404** con:
```json
{ "status": "error", "message": "No se encontraron provincias para esta región." }
```
(o `distritos para esta provincia.` / `escuelas para este distrito.`).

### 1. Listar Regiones / Departamentos
Obtiene todas las regiones del país.

- **URL:** `/api/regiones`
- **Método:** `GET`
- **Respuesta Exitosa (200 OK):**
```json
{
  "status": "success",
  "data": [
    {
      "idRegion": "01",
      "nombreRegion": "Amazonas",
      "pointX": "-78.0536004",
      "pointY": "-5.0720733",
      "zoom": 9,
      "created_at": "2026-11-16T16:47:17.000000Z",
      "updated_at": "2026-11-16T16:47:17.000000Z"
    }
  ]
}
```

---

### 2. Listar Provincias por Región
Obtiene las provincias pertenecientes a una región (mediante los 2 primeros dígitos del código de departamento).

- **URL:** `/api/provincias/{idRegion}`
- **Método:** `GET`
- **Ejemplo:** `/api/provincias/01` (Provincias de Amazonas)
- **Respuesta Exitosa (200 OK):**
```json
{
  "status": "success",
  "data": [
    {
      "idProvincia": "0101",
      "nombreProvincia": "Chachapoyas",
      "pointX": "-77.7752878",
      "pointY": "-6.4437346",
      "created_at": "2026-11-16T16:43:21.000000Z",
      "updated_at": "2026-11-16T16:43:21.000000Z"
    }
  ]
}
```

---

### 3. Listar Distritos por Provincia
Obtiene los distritos de una provincia (mediante los 4 dígitos de ubigeo provincial).

- **URL:** `/api/distritos/{idProvince}`
- **Método:** `GET`
- **Ejemplo:** `/api/distritos/0101` (Distritos de la provincia de Chachapoyas)
- **Respuesta Exitosa (200 OK):**
```json
{
  "status": "success",
  "data": [
    {
      "idDistrito": "010101",
      "nombreDistrito": "Chachapoyas",
      "created_at": "2026-11-16T16:43:22.000000Z",
      "updated_at": "2026-11-16T16:43:22.000000Z"
    },
    {
      "idDistrito": "010102",
      "nombreDistrito": "Asunción",
      "created_at": "2026-11-16T16:43:31.000000Z",
      "updated_at": "2026-11-16T16:43:31.000000Z"
    }
  ]
}
```

---

### 4. Listar Colegios por Distrito (Ubigeo)
Obtiene todas las instituciones educativas registradas en un distrito según su ubigeo de 6 dígitos.

- **URL:** `/api/colegios/{ubigeo}`
- **Método:** `GET`
- **Ejemplo:** `/api/colegios/070101`
- **Respuesta Exitosa (200 OK):**
```json
{
  "status": "success",
  "data": [
    {
      "codMod": "0207894",
      "idDistrito": "070101",
      "nombreCenEdu": "5019 AUGUSTO CAZORLA",
      "cenPob": "CALLAO",
      "dirCen": "AVENIDA REPUBLICA DE PANAMA 753",
      "director": "OLIVAS SANTOME ALBERTO JESUS",
      "dis_crecer": "Primera etapa",
      "dis_juntos": "Segunda etapa",
      "created_at": "2026-11-16T16:46:33.000000Z",
      "updated_at": "2026-11-16T16:46:33.000000Z"
    }
  ]
}
```
`director`, `dis_crecer` y `dis_juntos` pueden ser `null` cuando ESCALE no los informa.

---

## 📂 Estructura del Proyecto

```
brigesnote/
├── app/
│   ├── Console/Commands/
│   │   └── SyncLocations.php       # Comando 'sync:locations' (consumo de API ESCALE)
│   ├── Http/Controllers/
│   │   └── LocationController.php  # Controlador de endpoints /api/
│   └── Models/
│       ├── District.php            # Modelo Distritos
│       ├── Province.php            # Modelo Provincias
│       ├── Region.php              # Modelo Regiones
│       └── School.php              # Modelo Colegios / Escuelas
├── database/
│   ├── migrations/
│   │   ├── ..._create_regions_table.php
│   │   ├── ..._create_provinces_table.php
│   │   ├── ..._create_districts_table.php
│   │   └── ..._create_schools_table.php
│   ├── factories/                  # Factories (Database\Factories)
│   └── seeders/                    # Seeders (Database\Seeders)
├── routes/
│   └── api.php                     # Definición de rutas REST
├── tests/Feature/
│   ├── LocationApiTest.php         # Pruebas de contrato de los 4 endpoints
│   └── SyncLocationsCommandTest.php# Pruebas de 'sync:locations' (ESCALE simulado)
├── index.php / .htaccess / web.config  # Front controller en la raíz (sin carpeta public/)
├── .env.example                    # Plantilla de variables de entorno
└── .gitignore                      # Reglas de exclusión para Git
```

---

## 🧪 Pruebas Automatizadas

Las pruebas usan **MySQL** (la API usa la función `LEFT()`, que SQLite no tiene) y una base de datos **exclusiva para pruebas** llamada `escalebridge_test`, que se vacía en cada ejecución. Nunca uses la base real para las pruebas.

1. Crea la base de pruebas (mismo usuario/contraseña que en `.env`):
   ```sql
   CREATE DATABASE escalebridge_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
2. Ejecuta:
   ```bash
   php artisan test
   ```

Las pruebas del comando `sync:locations` simulan las respuestas de ESCALE con `Http::fake()`, así que no necesitan internet.

---

## 🔼 Migración a PHP 8.3 / Laravel 13

El proyecto se migró de **PHP 7.4 / Laravel 7.30.7** a **PHP 8.3 / Laravel 13** sin cambiar los endpoints, sus parámetros ni la estructura de las respuestas JSON. Cambios principales:

- `composer.json`: `php ^8.3`, `laravel/framework ^13.0`, `laravel/tinker ^3.0`, PHPUnit 12, Collision 8. Se retiraron `fideloper/proxy` y `fruitcake/laravel-cors` (ahora vienen dentro del framework) y `facade/ignition` (Laravel 13 trae su propia página de errores).
- Middleware: CORS, proxies, mantenimiento y CSRF pasan a las clases del framework (`HandleCors`, `TrustProxies`, `PreventRequestsDuringMaintenance`, `PreventRequestForgery`).
- `database/seeds` → `database/seeders` y factories en formato de clase (Laravel 8+).
- `phpunit.xml` en formato PHPUnit 12 y nuevas pruebas de la API y del comando.
- `php artisan serve` funciona con `index.php` en la raíz.

Se mantuvo la estructura clásica (Kernels y Service Providers), tal como recomienda la guía oficial de actualización de Laravel 11.

---

## 🛠️ Solución de Problemas Frecuentes

### 1. Error: `Your Composer dependencies require a PHP version ">= 8.3.0"` o `composer install` rechaza tu versión de PHP
- **Causa:** Estás ejecutando el proyecto con una versión de PHP anterior a 8.3.
- **Solución:** Cambia la versión de PHP en Laragon (o en tu servidor) a **PHP 8.3** o superior y comprueba con `php -v`.

### 2. Error: `Service Apache can not start ... fcgid.conf` en Laragon
- **Causa:** Descargaste la versión `nts` (Non-Thread Safe) de PHP en Windows.
- **Solución:** Descarga e instala en Laragon la versión **Thread Safe (TS)** desde [windows.php.net](https://windows.php.net/downloads/releases/archives/).

### 3. Error: `Base table or view not found: 1146 Table '...sessions' doesn't exist`
- **Causa:** En el archivo `.env` está configurado `SESSION_DRIVER=database` sin haber migrado la tabla de sesiones.
- **Solución:** Cambia en `.env` a `SESSION_DRIVER=file`.

### 4. Error: `The "intl" PHP extension is required to use the [format] method.`
- **Causa:** Algunos comandos de diagnóstico de Laravel 13 (por ejemplo `php artisan db:show`) usan la extensión `intl`. La API y `sync:locations` no la necesitan.
- **Solución:** Activa la extensión `intl` en tu `php.ini` si quieres usar esos comandos.

### 5. Error: `cURL error 6: Could not resolve host: escale.minedu.gob.pe` durante `sync:locations`
- **Causa:** Corte de red o de DNS al consultar ESCALE.
- **Solución:** Vuelve a ejecutar `php artisan sync:locations`; los registros existentes se actualizan sin duplicarse.

---

## 📄 Licencia

Este proyecto es de código abierto bajo la licencia [MIT](LICENSE).
