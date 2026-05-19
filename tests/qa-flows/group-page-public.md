---
slug: group-page-public
title: Página pública del grupo (/penca/g/<view_token>)
type: web
---

# /penca/g/<token>/

**Goal.** Cualquiera con el view_token de la penca puede ver el ranking.
NO se ve info privada (no view tokens de otros users, no scores ajenos).

**Steps.**

1. Navegar a `/penca/g/<group_token>/` (mobile + desktop).
2. Verificar render:
   - Nombre del grupo, # de jugadores.
   - Ranking del grupo (pedestal #1 + board de los demás).
   - "Próximos partidos" de la window del torneo.
   - CTA "Sumate · código XXX".
   - Footer.
3. Misma URL con `?as=<share_token>` (share_token, no view_token) —
   debe highlightear la row del user dueño del share_token con
   "row-me" styling.
4. Misma URL con `?as=<view_token_de_otro>` — NO debe aceptar (view tokens
   no son válidos como ?as=).

**Expected.**

- Ranking renderea aún con 0 jugadores ("Todavía no hay puntos cargados").
- Avatares con colores distintos para users diferentes (incluso si tienen
  el mismo nombre — hash por user_id, no por initial).
- Numeros right-aligned, fonts variable-tabular.
- ⚠️ Privacidad: no aparece el phone de ningún jugador, no se filtran
  view_tokens.

**Things to evaluate.**

- Performance: el page render debe ser <500ms (sticky stuff doesn't lag).
- Mobile responsiveness en 390px y 320px (más estrecho de iPhone SE).
