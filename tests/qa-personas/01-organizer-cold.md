---
slug: organizer-cold
phone: "999900001"
name: "Lucas (cold owner)"
flows: [onboarding-cold, crear-penca, pronostico-text, listar-pencas, share-link, lurker-web, me-page]
---

# Lucas — Owner desde cero

**Quién es.** 34 años, fanático de Boca, escuchó de Mantia por un amigo
("mandale hola a este número y te crea una penca por WhatsApp"). Nunca usó
el bot. No tiene cuenta. Le gusta más que tenga el menor fricción posible
— si tiene que descargar algo, abandona.

**Su intención.** Crear una penca con sus amigos para Libertadores semanal.
Cargar un primer pronóstico él mismo. Compartir el link al grupo.

**Qué evalúa.**

- ¿La primera respuesta del bot le aclara qué es Mantia y qué puede hacer?
- ¿La creación de penca es <90 segundos de fricción percibida?
- El bot le ofrece torneos — ¿hay alguno que NO debería estar ahí (Mundial,
  Sudamericana, etc., que ya borramos)?
- Cuando manda un score ("2-1"), ¿el bot anota correctamente y le confirma
  en qué penca quedó?
- ¿El link de share es claro? ¿Lo puede leer un humano? ¿Funciona en mobile?
- ¿La página `/me/` se ve bien en mobile? ¿Los nombres largos se truncan?

**Cosas a chequear en su sesión.**

- El primer reply del bot debe nombrar Libertadores como torneo disponible,
  no Mundial (ya borrado).
- "Crear penca" sin más context → bot debe ofrecer SOLO los 2 torneos
  vigentes (Libertadores 2026 + Libertadores semanal).
- Si manda "Crear penca Los Pibes" sin torneo, el bot debe stashear el
  name y pedir el torneo en un picker.
- Después de cargar un score, el bot debe responder "Anotado en …" + dar
  botones de continuación.
- `/me/` debe mostrar 8 partidos pronosticados (los default que arma el bot
  al crear la penca).

**Severidades que asigna.**

- Blocker: bot no responde, error 500, crear penca falla, link `/me/` 404.
- Paper-cut: copy stale (menciona competitions borrados), nombres truncados
  en mobile, botón sin label.
- Polish: emoji inconsistente, padding apretado, falta confirmación visual.
