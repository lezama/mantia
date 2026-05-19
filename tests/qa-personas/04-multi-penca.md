---
slug: multi-penca
phone: "999900004"
name: "Diego (multi-penca)"
flows: [crear-penca, crear-penca, crear-penca, auto-routing-multipenca, listar-pencas]
---

# Diego — Está en varias pencas a la vez

**Quién es.** 36 años, organiza la penca de la oficina, la del barrio y la
de los pibes del fútbol 5. Se anota en todas. Cuando carga un pronóstico
quiere que se anote en TODAS las pencas que aplican para ese partido
(auto-routing), no tener que repetir el score 3 veces.

**Su intención.** Crear 3 pencas (todas en Libertadores 2026), cargar un
score para un partido, verificar que se anotó en las 3.

**Qué evalúa.**

- ¿Crear 3 pencas seguidas funciona? ¿O hay un rate-limit que lo bloquea?
- Cuando manda un score por texto suelto ("2-1"), sin tapear un partido
  específico, ¿el bot pregunta cuál partido (porque hay ambigüedad) o
  asume el más próximo?
- ¿La copy del "Anotado" lista las N pencas o dice "en X, Y, Z"?
- En el listar-pencas, ¿cada penca muestra cuántos pronósticos tiene
  cargados / cuántos van? Útil para saber dónde le falta.

**Cosas a chequear.**

- Tres pencas creadas, todas con el mismo competition_id (libertadores-2026).
- Tap un partido → mandar "2-1" → el bot debe responder algo como
  "Anotado en *Los del 92*, *Penca del barrio*, *Pibes del 5*: Boca 2 - 1
  River" — con las 3 pencas listadas.
- En la DB: 3 prediction posts para ese partido (uno por penca).
- En `/me/`, el partido aparece UNA SOLA VEZ (no triplicado), con el
  pronóstico común.

**Severidades.**

- Blocker: auto-routing no escribe a todas las pencas, listar pencas
  rompe con N>2, rate-limit bloquea al usuario legítimo.
- Paper-cut: el "Anotado" sólo nombra una penca aunque escribió en 3,
  el resumen de partidos pronosticados aparece duplicado por cada penca.
- Polish: el orden de las pencas en el listado no es estable.
