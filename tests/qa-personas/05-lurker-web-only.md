---
slug: lurker-web-only
phone: null
name: "Visitante anónimo (web only)"
flows: [lurker-web, group-page-public, competition-page]
---

# Visitante anónimo — Sin tocar WhatsApp

**Quién es.** Llegó a mantia3.wpcomstaging.com porque alguien le pasó el
link. Quizás un periodista, quizás un curioso, quizás Riad escaneando el
proyecto. NO va a abrir WhatsApp. Sólo navega.

**Su intención.** Entender qué es Mantia, ver si hay actividad real, juzgar
si vale la pena recomendarlo a su grupo.

**Qué evalúa.**

- ¿El home en mobile y desktop transmite el producto en <5 segundos?
- ¿Hay alguna penca pública que pueda explorar sin autenticarse?
- Visita `/pronostico/libertadores-semana` — ¿el listado de partidos de esta
  semana es claro?
- Visita `/pronostico/g/<token>/` (token público del owner que ya creó penca)
  — ¿se ve el ranking sin pedir login?
- Si tapea un partido, ¿hay info adicional? (Resultado, predicción
  consensus, etc.)
- ¿La sticky stats bar funciona en mobile?
- ¿Funciona la instalación PWA en mobile?

**Cosas a chequear (sin interactuar con bot).**

- Home: 2 CTAs claros (WhatsApp + Ver Libertadores). No clutter.
- Page de Libertadores semanal: 8 partidos visibles, hoist de phase por
  día, CTA "Crear penca" al pie.
- Group page (`/pronostico/g/<token>/`): ranking + próximos partidos + share
  CTA. Sin acceso al edit (eso es del owner).
- Test del share-poster `/pronostico/me/share/<share_token>/`: aparece bien
  formatted, recipient-friendly.
- 404 themed: visitar un slug inexistente bajo `/pronostico/` → debe rendererar
  el themed 404 con CTAs de recuperación, no el Assembler Lorem ipsum.

**Severidades.**

- Blocker: page returns 5xx, broken layout, 404 a Lorem-ipsum.
- Paper-cut: copy stale, link roto interno, mobile overflow.
- Polish: typography size, color contrast, animation choppy.
