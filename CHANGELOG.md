# Registro de cambios

## [0.3.0] — Clasificación de competencia estabilizada

- El instante de cruce de meta se calcula por participante dentro del tick para ordenar correctamente llegadas simultáneas.
- Un competidor finalizado queda congelado mientras su rival continúa; la clasificación se actualiza antes de registrar los eventos terminales.
- Dos abandonos se ordenan por progreso, pero no generan un ganador ficticio.
- El endpoint de inicio solo avanza una carrera recién creada, por lo que reintentos HTTP no agregan ticks.

## [0.2.0] — Competencia de dos participantes

- Tres perfiles de piloto (joven/intermedio/mayor) y tres clases de vehículo, cada uno con estadísticas propias.
- Selección de dos pilotos distintos y auto independiente para competir en la misma etapa.
- Simulación por tick con estado, generador aleatorio, decisiones, desgaste e incidentes independientes por participante.
- Clasificación en vivo/final y resultado comparativo; el mock consulta contexto de piloto y vehículo.
- Integración con servicio Jev real sigue pendiente de acceso y documentación oficial.

## [0.1.0] — Prototipo funcional

- Primera versión jugable de JEV Rally con etapa Sierra de la Mina, piloto JEV-01 y vehículo RALLY-X1.
- Simulación de carrera en Laravel con estado persistido en archivos JSON/JSONL del Codespace.
- Interfaz de telemetría con pista esquemática Canvas, decisiones, eventos, controles e historial.
- Proveedor de decisiones `MockJevService` para desarrollo sin depender de un servicio externo.
- **Integración Jev real pendiente:** esperamos acceso y documentación oficial para analizar el servicio e integrarlo correctamente.
