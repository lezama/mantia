---
slug: competition-page
title: Página de competition (/pronostico/libertadores-semana)
type: web
---

# Competition page

**Goal.** Página pública de un torneo — muestra ranking global, próximos
partidos, y CTA para crear una penca privada.

**Steps.**

1. Navegar a `/pronostico/libertadores-semana/`.
2. Verificar elements:
   - Eyebrow "PENCA · GLOBAL".
   - H1 "Libertadores semanal".
   - Subtitle "mar 19 may → jue 21 may · 8 partidos".
   - Pills nav (Libertadores 2026 + semanal). Active highlighted.
   - "RANKING GLOBAL" + "de N jugadores" (con N=número real, no 0).
   - "PRÓXIMOS PARTIDOS" agrupados por día con phase en el header.
   - CTA pink "Crear penca de Libertadores semanal".
3. Mismo flow en `/pronostico/libertadores-2026/` (parent).

**Expected.**

- N en "de N jugadores" pluralizado correcto (1 jugador / 2 jugadores).
- Cuando N < 50, eyebrow dice "ranking global" no "top 50".
- Phase "Fase de grupos · Fecha 5" (no "Group stage Matchday 5").
- Country names en español (Brasil, México…).
- 2 pills, no más, no menos.

**Things to evaluate.**

- ¿Hoist de phase funcionando? Cada día → un solo "MAR 19 MAY · FASE…"
  en el header.
- Sticky elements no rompen al scroll.
