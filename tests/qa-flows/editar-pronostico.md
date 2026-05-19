---
slug: editar-pronostico
title: Cambiar pronóstico ya cargado
type: whatsapp
---

# Editar pronóstico

**Goal.** User ya tiene un pronóstico para un partido y lo quiere cambiar.

**Steps.**

1. State query: confirmar que hay un prediction existente para match X (de
   default seed o de un step anterior).
2. Tapear el partido en `Ver partidos`.
3. Verificar detail dice "Pronóstico actual: *X-Y*".
4. Mandar nuevo score `3-2`.
5. Verificar confirm "Anotado en *penca*: home 3 - 2 away".
6. Tapear el mismo partido otra vez.
7. Verificar detail ahora dice "Pronóstico actual: *3-2*" (actualizado).

**Expected.**

- Sin double-post: el bot actualiza el existing prediction, no crea uno
  nuevo (mismo prediction post_id).
- El score viejo se reemplaza enteramente (no se suma).
- Si el match ya empezó (kickoff < now), el bot DEBE rechazar la edición:
  "El partido ya arrancó, no podés cambiar el pronóstico".

**Things to evaluate.**

- ¿La transición visual del detail (antes "3-4", después "3-2") es clara?
- ¿Hay race condition si dos personas editan al mismo tiempo? (multi-penca)
