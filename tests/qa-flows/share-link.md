---
slug: share-link
title: Compartir penca (link + share poster)
type: hybrid
---

# Compartir penca

**Goal.** Owner pide compartir su penca y obtiene un link que puede mandar
por WhatsApp. El recipient ve algo razonable.

**Steps.**

1. Mandar `compartir` o tapear botón de share.
2. Verificar el bot responde con link `/penca/g/<token>/sumate/` + texto
   pre-armado para forward.
3. Navegar al link via Chrome MCP (mobile 390px).
4. Verificar la page de invite render bien:
   - Nombre de la penca grande.
   - "Sumate por WhatsApp" CTA prominente.
   - OG tags presentes (meta property=og:title, og:image).
5. Tapear "Sumate por WhatsApp" — verifica que abre wa.me con texto
   pre-armado tipo "código TURISMODEL92PRGR6SB" o equivalente.

**Expected.**

- El token en el link es DIFERENTE del view_token de la group page
  privada (security: share-only token).
- La page tiene un OG image generated (`/penca/g/<token>/og/`).
- En mobile, el CTA está sobre el fold.
- El texto pre-armado del wa.me tiene el código de invitación.

**Things to evaluate.**

- ¿Cuál es la conversion rate implícita? El visitante de mobile entiende
  qué tiene que hacer?
- ¿Si tapeo "Sumate" en desktop, el wa.me abre WhatsApp Web?
- ¿El share-poster (`/penca/me/share/<share_token>/`) muestra el ranking
  + stats de forma "screenshoteable"?
