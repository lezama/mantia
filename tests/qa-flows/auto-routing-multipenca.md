---
slug: auto-routing-multipenca
title: Auto-routing de pronóstico a múltiples pencas
type: whatsapp
---

# Auto-routing entre pencas del mismo torneo

**Goal.** User con N pencas en el mismo torneo (Libertadores semanal),
manda un marcador y el bot debe escribir la prediction en LAS N pencas.

**Steps.**

1. Asegurar que el user tiene 3 pencas creadas en libertadores-semana.
2. Mandar `2-1` para el próximo partido pendiente.
3. Verificar que el reply menciona TODAS las pencas:
   `"Anotado en *X*, *Y*, *Z*: Home 2 - 1 Away"`.
4. State query: 3 prediction posts creados/actualizados, uno por penca.
5. Mandar `0-0` para el mismo partido — debe sobreescribir las 3.

**Expected.**

- 3 prediction posts existen, todos con score 2-1, después del primer mensaje.
- Después del segundo (0-0), los 3 actualizados a 0-0.
- Reply lista las 3 pencas con asteriscos (formato WhatsApp).

**Things to evaluate.**

- ¿El bot dice "anotado en 3 pencas" o detalla los nombres?
- ¿El orden de las pencas en el reply es estable?
- Si el user tiene 1 sola penca, el "Anotado en *X*" debe seguir
  funcionando (sin lista degenerada).

**Critical:** este es el feature de auto-routing que define el producto.
Si rompe, blocker absoluto.
