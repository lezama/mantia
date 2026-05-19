---
slug: pronostico-tap
title: Pronosticar tapeando partido de la lista
type: whatsapp
---

# Pronosticar por tap

**Goal.** User tapea un partido del list (Ver partidos), el bot envía
detail panel + acepta el siguiente marcador como prediction para ese match.

**Steps.**

1. Asegurar que el user tiene penca + 8 default predictions.
2. Mandar `partidos` o tapear botón "Ver partidos".
3. Verificar list de 8 partidos. Verificar que **ninguno** tiene
   `row.title` truncado (≤24 chars con la abbreviation map).
4. Tapear `mantia:match:<id>` del primer partido.
5. Verificar detail panel SLIM (4 líneas max):
   - Title + matchup
   - kickoff · phase
   - "Pronóstico actual: X-Y — mandame uno nuevo (ej *2-1*)"
   - 3 buttons.
6. Mandar `0-0` como marcador.
7. Verificar confirm "Anotado en *X*: home 0 - 0 away".

**Expected.**

- Detail panel ≤4 líneas, sin "En: X penca" (eliminado en refactor reciente).
- Confirm message es nuevo (no se reusan los del detail).
- Después del confirm, el user puede tapear "Más pendientes" para seguir.

**Things to evaluate.**

- ¿Los nombres de equipos largos están abreviados correctamente?
  ("U. Chile vs Defensa y J." cabe en 24 chars).
- ¿La phase está en español? ("Fase de grupos · Fecha 5", no "Group stage
  Matchday 5").
- ¿El detail panel se siente apretado o respirable?
