# JEV Rally

**Versión actual: 0.3.0 — competencia local robusta de dos participantes con Mock Jev.**

Juego web de rally con decisiones estratégicas, simulación del lado servidor y persistencia JSON local. La edición inicial usa Mock Jev y no requiere base de datos.

Puedes seleccionar dos pilotos distintos, asignar un auto a cada uno y competir en la misma pista. La edad se representa mediante atributos explícitos del perfil (experiencia, reacción, agresividad, conservación y tolerancia al riesgo); no decide por sí sola quién gana. Mock considera el perfil y el vehículo, mientras el motor calcula las consecuencias y la clasificación.

## Estado de integración Jev

Estamos **a la espera del acceso y de la documentación oficial vigente del servicio Jev** para estudiar cómo funciona e integrar su toma de decisiones estratégicas. La integración externa aún no está implementada ni configurada; por ahora el juego utiliza `MockJevService`, una serie de reglas internas de prueba. El mock puede tomar decisiones arriesgadas y no garantiza victorias.

Cuando esté disponible el servicio Jev, se revisarán sus capacidades, autenticación, formato de entrada/salida, errores y límites antes de implementar el proveedor real. No se deben inventar endpoints ni poner API keys en el frontend o en GitHub.

## Codespaces

1. Publica este repositorio en GitHub y crea un Codespace.
2. Codespaces instala dependencias con `bash install.sh` y arranca el servidor automáticamente al crear o reabrir el Codespace.
3. Abre el puerto 8000 desde la pestaña **Ports**.

La preparación ejecuta las pruebas PHP. El frontend usa JavaScript del navegador y no necesita instalar Node ni paquetes npm.

Para iniciar manualmente el servidor: `bash scripts/start.sh`.

## Instalación manual de dependencias

Desde la raíz del repositorio, en un Codespace normal (no Recovery), ejecuta el instalador único:

```bash
bash install.sh
```

Este script instala Composer si no está disponible, instala dependencias PHP, prepara `.env` sin reemplazarlo, inicializa los JSON faltantes y corre las comprobaciones. Si el Codespace está en Recovery Mode, primero reconstruye el contenedor desde la paleta de comandos de VS Code: `Codespaces: Rebuild Container`.

## Datos

La interfaz permite seleccionar dos pilotos distintos y combinar cada uno con cualquiera de los autos del catálogo. Ambos compiten en la misma etapa con estados, RNG, decisiones y consecuencias independientes; la clasificación final se deriva de finalizaciones, tiempos y abandonos registrados.

Los catálogos y las carreras se guardan bajo `storage/app/data/`. Las carreras se escriben en JSON y sus eventos en JSONL. El directorio de partidas está excluido de Git. El contenido vive en el Codespace actual; al eliminar ese Codespace se pierden las partidas y una nueva instancia comienza sin historial.

La inicialización es idempotente. Para recrear catálogos faltantes sin borrar los existentes:

```bash
php artisan rally:setup
```

## Configuración Jev

Por defecto `.env` utiliza `JEV_DRIVER=mock`, sin clave externa. La integración real queda pendiente de implementar tras verificar la documentación oficial vigente del proveedor Jev. Las credenciales, cuando corresponda, permanecen en servidor.

## Continuidad

Consulta `docs/ARQUITECTURA.md` para contratos y diseño y `docs/PROGRESO.md` para retomar el trabajo tras una interrupción.
