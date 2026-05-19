---
slug: member-invited
phone: "999900003"
name: "Pablo (invited member)"
flows: [share-link-recipient, onboarding-from-invite, pronostico-tap, group-page]
prereq: organizer-cold
---

# Pablo — Recibió un link de invitación

**Quién es.** 28 años, su amigo Lucas le pasó un link de Mantia por
WhatsApp ("Lucas creó una penca, sumate"). Nunca abrió Mantia. Curioso pero
escéptico — si la primera pantalla no le explica qué tiene que hacer,
abandona en 10 segundos.

**Su intención.** Entender qué es esto, unirse, cargar su primer pronóstico.

**Qué evalúa.**

- ¿El link que recibió abre la página del grupo (`/penca/g/<token>/sumate/`)
  o un onboarding del bot?
- ¿La página de invite tiene un CTA claro "Sumate por WhatsApp"?
- Cuando le habla al bot por primera vez, ¿el bot sabe que viene de una
  invitación o pregunta desde cero?
- ¿La join flow es ≤ 3 mensajes? Si pide nombre, código, torneo y group
  ID separados es mucho.
- ¿Puede ver el ranking del grupo SIN registrarse primero?

**Cosas a chequear.**

- Visitar el link de share del owner (`/penca/g/<token>/sumate/`) en
  Chrome MCP — la página debe rendererearse bien, dar OG image preview-
  friendly, y tener CTA "Sumate por WhatsApp" linkeando a wa.me con un
  texto pre-armado.
- Si Pablo manda el código de invitación al bot ("código TURISMODEL92ABC")
  ¿el bot lo une al grupo sin pedir más cosas?
- Después de unirse, ¿se ve en el ranking del grupo como nuevo miembro?
- En su `/me/` debe aparecer la penca a la que se acaba de unir.

**Severidades.**

- Blocker: el link no abre, el código no une, el onboarding desde invite
  pide info que ya tiene (nombre/teléfono).
- Paper-cut: la página de share no tiene OG meta tags decentes (los
  recipients ven un preview feo en WhatsApp).
- Polish: animation de "te uniste".
