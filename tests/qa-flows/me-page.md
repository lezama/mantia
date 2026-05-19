---
slug: me-page
title: Página privada del jugador (/me/<view_token>)
type: web
---

# /me/<view_token>/

**Goal.** User abre su link privado en mobile y puede ver/editar pronósticos.

**Steps.**

1. State query → tomar el `view_token` del user.
2. Navegar a `/penca/me/<token>/` (mobile 390px).
3. Verificar render:
   - Hero con avatar + nombre + 3 stats (puntos / exactos / pronósticos).
   - Sticky stats bar aparece al scroll.
   - Sección "Próximos · editá tu pronóstico" con form per match.
   - Sección "Tus pronósticos" con history (vs Resultado real).
4. Scroll down hasta sticky aparece.
5. Cambiar score de un partido vía form, submit "Guardar".
6. Verificar respuesta (toast / inline confirm).
7. Re-cargar página, verificar persist.

**Expected.**

- Pluralizaciones correctas (1 punto / 2 puntos, 1 exacto / 2 exactos).
- Nombres de equipos largos wrappan a 2 líneas, no se truncan mid-word.
- Si el user no tiene nombre, dice "Jugador ·1234" (últimos 4 del phone),
  no "sin nombre".
- Sin "Group stage Matchday 5" en inglés.
- PWA install banner sólo en mobile (gated por media query).

**Things to evaluate.**

- ¿Si abro el view_token de otro user (typo), el themed 404 sale o el
  Assembler Lorem?
- ¿La edit-form da feedback inmediato o requiere reload?
- ¿Funciona en iOS Safari? (Si el flow assumes Chrome features…)
