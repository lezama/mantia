---
slug: pronostico-text
title: Cargar pronóstico por texto suelto
type: whatsapp
---

# Pronosticar mandando "2-1" suelto

**Goal.** El user manda un marcador sin haber tapeado un partido — el bot
debe deducir el partido pendiente (próximo más cercano) y guardarlo.

**Steps.**

1. Asegurar que el user tiene una penca creada (prereq: crear-penca).
2. Mandar `2-1` como mensaje suelto.
3. Verificar que el bot responde con "Anotado en *X*: Home 2 - 1 Away".
4. Mandar `Boca 3 River 1` (formato alternativo, nombres + scores).
5. Verificar que el bot infiere el partido por nombres y actualiza.

**Expected.**

- Reply incluye "Anotado" + nombre de la penca + scores.
- 3 buttons de continuación.
- El partido pronosticado es el más próximo en kickoff (no uno random).
- Si hay múltiples partidos próximos sin pronóstico, el bot debe pedir
  cuál (no asumir).

**Things to evaluate.**

- ¿La parser del marcador acepta variantes: "2-1", "2 a 1", "2 1", "2:1"?
- ¿Reconoce "boca 3 river 1" sin ambigüedad? ¿Y si hay 2 partidos en la
  semana con Boca?
- ¿Qué pasa si manda un score fuera de rango ("20-19")? ¿O negativo?

**Finding examples.**

```json
{
  "severity": "paper-cut",
  "lens": "copy",
  "where": "respuesta a 2 a 1",
  "what": "El bot anota correctamente pero la copy no menciona el equipo",
  "fix": "Incluir 'Boca 2 - 1 River' en lugar de sólo '2-1'",
  "evidence": "reply: 'Anotado en *Los del 92*: 2 - 1'"
}
```
