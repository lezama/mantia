---
slug: organizer-returning
phone: "999900002"
name: "Florencia (returning owner)"
flows: [listar-pencas, editar-pronostico, me-page, share-link, fallback-llm]
prereq: organizer-cold
---

# Florencia — Vuelve después de unos días

**Quién es.** 41 años, ya tiene una penca en Mantia ("Los del 92") creada
hace ~5 días. Volvió porque escuchó que se viene Boca-River la semana que
viene y quiere revisar sus pronósticos. Es ordenada, no quiere repetir
pasos.

**Su intención.** Ver qué pronósticos cargó, cambiar el de Boca-River si lo
puso mal, ver el ranking de su penca.

**Qué evalúa.**

- ¿El bot la "reconoce" — saluda por nombre o reconoce que vuelve?
- Cuando pide "mis pencas", ¿el listado es claro?
- Editar un pronóstico existente: ¿el bot muestra el actual ANTES de pedir
  el nuevo? ¿La copy "Pronóstico actual: 3-4 — mandame uno nuevo" es OK?
- ¿La sección "Tus pronósticos" en `/me/` muestra los partidos que ya
  jugaron con resultado real vs su predicción?
- Si el bot no entiende lo que escribe ("qué hago acá"), el LLM fallback
  responde de forma útil — no "no entendí".

**Cosas a chequear.**

- En su segundo mensaje (>24h después del primero), si el bot intenta
  responder y no es un template aprobado, va a fallar. Verificar si Mantia
  maneja el 24h-window correctamente — el primer mensaje del USER reabre
  la ventana, así que el bot puede responder libremente. Esto es lógica
  crítica que tiene que andar.
- El stats bar sticky de `/me/` debe aparecer al scrollear.
- Al tapear un partido ya pronosticado, el detail panel slimmed (el de mi
  refactor reciente) debe mostrar "Pronóstico actual: X-Y" en una línea.

**Severidades.**

- Blocker: bot no responde, edits no se persisten, ranking aparece vacío
  cuando hay datos.
- Paper-cut: copy verbosa al editar, sticky stats bar no aparece.
- Polish: timing del sticky, animaciones de feedback.
