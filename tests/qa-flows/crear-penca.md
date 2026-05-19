---
slug: crear-penca
title: Crear penca + asignar torneo
type: whatsapp
---

# Crear penca

**Goal.** Owner crea una penca en pocos pasos. El bot DEBE ofrecer SOLO los
torneos vigentes en el seed (Libertadores 2026 + Libertadores semanal).

**Steps.**

1. Mandar `crear penca Los del 92` (con nombre inline).
2. Verificar que el bot pregunta el torneo y muestra un picker.
3. Verificar que el picker tiene EXACTAMENTE 2 rows: Libertadores 2026 y
   Libertadores semanal.
4. Tapear "Libertadores semanal" (id: `mantia:newcomp:libertadores-semana`).
5. Verificar la confirmación: "Creaste *Los del 92* para 📆 Libertadores
   semanal".
6. Verificar que el bot también auto-cargó pronósticos default para los 8
   partidos de la window.

**Expected.**

- 5-6 mensajes total (incluye picker tap).
- Una penca persistida en DB con `competition_id=libertadores-semana`.
- 8 prediction posts para el user en esa penca (default scores).
- Reply final tiene buttons "Ver partidos", "Compartir", "Home" (o similar).

**Things to evaluate.**

- ¿El picker tiene UN torneo extra (algún competition huérfano que la
  migración v4 no limpió)?
- ¿Los row.title del picker entran en 24 chars? (Libertadores semanal = 20,
  Libertadores 2026 = 17 — ambos entran).
- ¿La copy del confirm dice el nombre del torneo bien?
- ¿La "✅ Creaste X" tiene timing apropiado o es atropellada?

**Finding examples.**

```json
{
  "severity": "blocker",
  "lens": "ia",
  "where": "picker de Elegir torneo",
  "what": "Aparece un torneo huérfano 'esta-semana' que no debería existir",
  "fix": "Verificar que la migración v4 borró todos los slugs de REMOVED_COMPETITION_SLUGS",
  "evidence": "row 3 del picker: title='⚡ Esta semana'"
}
```
