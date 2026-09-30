# JEV Rally

Juego web de rally con decisiones estratégicas, simulación del lado servidor y persistencia JSON local. La edición inicial usa Mock Jev y no requiere base de datos.

## Codespaces

1. Publica este repositorio en GitHub y crea un Codespace.
2. El contenedor instala PHP 8.3, Composer y Node, después ejecuta `.devcontainer/setup.sh`.
3. En el terminal de Codespaces inicia el servidor:

   ```bash
   php artisan serve --host=0.0.0.0 --port=8000
   ```

4. Codespaces reenvía y abre el puerto 8000 automáticamente; también puedes acceder desde la pestaña **Ports**.

La preparación ejecuta también `node --check public/js/rally.js` y `php artisan test`.

También puedes ejecutar `bash scripts/start.sh`.

## Instalación manual de dependencias

En un Codespace normal (no Recovery), el setup de primera creación es:

```bash
bash .devcontainer/setup.sh
```

Este script instala Composer si no está disponible, instala dependencias PHP, prepara `.env` sin reemplazarlo, inicializa los JSON faltantes y corre las comprobaciones.

## Desarrollo local con Docker

```bash
docker compose up --build
```

Luego abre `http://localhost:8000`.

## Datos

Los catálogos y las carreras se guardan bajo `storage/app/data/`. Las carreras se escriben en JSON y sus eventos en JSONL. El directorio de partidas está excluido de Git. El contenido vive en el Codespace actual; al eliminar ese Codespace se pierden las partidas y una nueva instancia comienza sin historial.

La inicialización es idempotente. Para recrear catálogos faltantes sin borrar los existentes:

```bash
php artisan rally:setup
```

## Configuración Jev

Por defecto `.env` utiliza `JEV_DRIVER=mock`, sin clave externa. La integración real queda pendiente de implementar tras verificar la documentación oficial vigente del proveedor Jev. Las credenciales, cuando corresponda, permanecen en servidor.

## Continuidad

Consulta `docs/ARQUITECTURA.md` para contratos y diseño y `docs/PROGRESO.md` para retomar el trabajo tras una interrupción.
