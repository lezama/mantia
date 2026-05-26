---
slug: lurker-web
title: Visitante anónimo navega el sitio
type: web
---

# Lurker (sin tocar bot)

**Goal.** Alguien que llegó al sitio sin contexto entiende qué es Mantia,
explora 2-3 páginas, y sale con una opinión clara.

**Steps.**

1. Navegar a `/` (home) en mobile 390px + desktop 1280px.
2. Verificar:
   - Wordmark "mantia" visible.
   - Tagline ("Pronosticá, picanteá el grupo…").
   - 2 CTAs: WhatsApp button + "Ver Libertadores".
   - QR code útil en desktop (escaneable desde phone).
   - Footer.
3. Tapear "Ver Libertadores".
4. Verificar `/pronostico/libertadores-semana` renderea:
   - Header con nombre + count "8 partidos".
   - Pills nav con Libertadores 2026 + Libertadores semanal (sólo).
   - Listado de próximos partidos por día.
   - Empty state "Todavía no hay puntos cargados" (ya que no hay pencas).
   - CTA pink "Crear penca de Libertadores semanal".
5. Tapear pill "Libertadores 2026" → renderea ese page.
6. Visitar un slug inexistente como `/pronostico/no-existe` → themed 404, no
   Lorem-ipsum del Assembler.

**Expected.**

- Home: ear-pills no clipean en 390px.
- Competition pages: chips nav scroll suave, active chip in view.
- 404 themed siempre que la URL sea bajo `/pronostico/`.

**Things to evaluate.**

- ¿Algún competition huérfano en los pills? (Esta semana, Sudamericana,
  etc.) — debería ser sólo 2 (Libertadores 2026 + semanal).
- ¿Hay un "estado vacío" elegante o un dead-end visual?
