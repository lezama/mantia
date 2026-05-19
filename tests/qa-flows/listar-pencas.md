---
slug: listar-pencas
title: Listar pencas del usuario
type: whatsapp
---

# Listar pencas

**Goal.** User pide ver todas sus pencas y poder saltar a cualquiera.

**Steps.**

1. Mandar `mis pencas` o `pencas`.
2. Verificar reply es un Interactive List con todas las pencas del user.
3. Verificar cada row tiene:
   - title con nombre de penca (≤24 chars).
   - description con torneo + estado ("4 jugadores · 8 pronósticos").
4. Tapear una penca → debe llevar a la detail de esa penca con ranking.

**Expected.**

- Si el user tiene 0 pencas, reply: "Todavía no tenés pencas. Creá una con
  *crear penca*" + button "+ Crear penca".
- Si tiene N pencas, cada row es tapeable.
- Una penca "activa" (con `is_active=true` en meta) tiene un checkmark `✓`
  o algo que la distinga.

**Things to evaluate.**

- ¿Hay un cap a N pencas o se ve mal con muchas? (WhatsApp list max = 10).
- ¿Los nombres largos se truncan elegantemente?
