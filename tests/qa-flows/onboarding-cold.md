---
slug: onboarding-cold
title: Cold WhatsApp onboarding
type: whatsapp
---

# Cold onboarding (primer mensaje al bot)

**Goal.** Después de "hola" un usuario nuevo debe entender qué es Mantia,
qué torneos puede pronosticar y cómo arrancar.

**Steps.**

1. Mandar `hola` como persona con phone `999900001`.
2. Verificar reply del bot.
3. Verificar interactive buttons (debe ofrecer al menos "Crear penca" o
   equivalente — _no_ "Predicir Mundial" porque ese torneo se eliminó).

**Expected.**

- `reply` no-vacío, en rioplatense voseo, sin enthusiasm ("¡Hola!" con
  signo de exclamación inicial sería un olor).
- Menciona Libertadores o "torneos" — no menciona Mundial / Sudamericana /
  LigaUY (ya borrados del seed).
- 2-3 botones de continuación.

**Things to evaluate (qualitative).**

- ¿La copy se siente humana o robótica?
- ¿Le aclara que es por WhatsApp (no descargar app)?
- ¿Hay una acción default obvia para alguien que llegó frío?

**Finding examples.**

```json
{
  "severity": "paper-cut",
  "lens": "copy",
  "where": "primer reply al hola",
  "what": "Dice 'pronósticos mundialistas' pero Mundial fue eliminado",
  "fix": "Cambiar a 'pronósticos de Libertadores' o algo más genérico",
  "evidence": "reply text: 'Soy Mantia, la app de pronósticos mundialistas...'"
}
```
