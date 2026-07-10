# TicketFesna Backend (Laravel)

API REST de TicketFesna para autenticacion, gestion de tickets, chat, bandejas por area y funcionalidades administrativas.

## Resumen

Este proyecto implementa el backend principal del sistema de tickets. Expone endpoints consumidos por el frontend React y persiste informacion de usuarios, areas, tickets, mensajes, historial y reportes de reasignacion.

## Tecnologias

- PHP 8.2+
- Laravel 12
- MariaDB / MySQL
- Eloquent ORM

## Requisitos

- PHP 8.2 o superior
- Composer 2+
- MariaDB o MySQL
- (Opcional) Node.js para assets internos de Laravel

## Instalacion

```bash
composer install
copy .env.example .env
php artisan key:generate
```

## Configuracion de base de datos

Configura en .env:

- DB_CONNECTION
- DB_HOST
- DB_PORT
- DB_DATABASE
- DB_USERNAME
- DB_PASSWORD

### Opcion A: cargar esquema SQL existente

Importar el archivo ticket_ia.sql en la base de datos configurada.

### Opcion B: usar migraciones del proyecto

```bash
php artisan migrate
```

Si trabajas localmente sin tablas de sesion/cache en DB, se recomienda mantener en .env:

- SESSION_DRIVER=file
- CACHE_STORE=file
- QUEUE_CONNECTION=sync

## Ejecucion local

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Healthcheck:

- GET /api/health

## Endpoints principales

### Autenticacion

- POST /api/login
- POST /api/change-password

### Tickets

- GET /api/tickets
- POST /api/tickets
- GET /api/tickets/mine/{userId}
- GET /api/tickets/area/{userId}
- PUT /api/tickets/{ticketId}/area-update
- POST /api/tickets/{ticketId}/report
- POST /api/tickets/{ticketId}/requester-decision
- GET /api/tickets/{ticketId}/messages
- POST /api/tickets/{ticketId}/messages

### Reportes

- GET /api/reports/misrouted
- DELETE /api/reports/misrouted/{reportId}

### Usuarios y areas

- GET /api/users
- POST /api/users
- PUT /api/users/{id}
- POST /api/users/{id}/reset-password
- GET /api/areas

## Reglas funcionales implementadas

- Control de permisos por rol y area para gestionar tickets.
- Solo usuarios activos pueden figurar como responsables elegibles en la bandeja de area.
- Tickets resueltos pueden cerrarse automaticamente tras la ventana definida por historial.
- El flujo de reporte de ticket registra evento, mueve area, limpia responsable y actualiza estado.
- El descarte de reportes elimina el registro en base de datos.

## Estructura base

```text
ticketfesna-back-end-laravel/
	app/
		Http/Controllers/Api/
	routes/
		api.php
	config/
	database/
	public/
	.env
	artisan
	composer.json
```

## Comandos utiles

```bash
# Limpiar cache de configuracion/rutas
php artisan optimize:clear

# Ejecutar pruebas
php artisan test

# Revisar sintaxis de un archivo concreto
php -l app/Http/Controllers/Api/TicketController.php
```

## Integracion con frontend

- Frontend esperado: http://localhost:5173
- API esperada: http://127.0.0.1:8000
- El frontend usa proxy de Vite para redirigir /api al backend local.

## Estado actual

Backend en desarrollo activo, operativo para autenticacion, tickets, mensajes, bandejas, reportes y administracion basica.

## Licencia

Uso academico/institucional interno.
