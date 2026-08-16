# Workflow — planificación, bugs, registro, handoff, docs, versionado

> REGLA OBLIGATORIA. Consultar antes de cualquier tarea que escriba/modifique código o cierre trabajo.

## 1. Planificación antes de implementar

Para cualquier tarea que escriba o modifique código (más allá de 1 línea / typo):

1. **Plan primero**: usa skill `Plan` o `/plan`. Mínimo: archivos a crear/modificar y por qué, cambios de schema, endpoints afectados, riesgos contables/seguridad, pendientes fuera de alcance.
2. **Confirmación EXPLÍCITA**: "¿Procedo según este plan?" — esperar respuesta. No asumir.
3. **Solo entonces implementar**. Si te desvías significativamente, pausa y re-confirma.

**Flujo cuando viene de un Issue** (`#NNN`, URL, o branch `feature/NNN-*` / `fix/NNN-*`):

1. `gh issue view <NNN> --comments` — leer issue y comentarios.
2. Buscar plan existente (secciones "Plan", "Propuesta", "Approach", checklists).
3. Según hallazgo:
   - **No hay plan**: redacta uno y publícalo con `gh issue comment <NNN> --body-file`. Luego pregunta si procede.
   - **Hay plan**: pregunta "El issue ya tiene plan de <autor/fecha>, ¿procedo tal cual?". Resume puntos clave.
   - **Tienes ajustes al plan**: NO los implementes unilateral. Preséntalos como diff vs original; si se aprueban, deja comentario en el issue documentándolos.

**Excepciones (no requieren plan formal)**: bug fixes triviales de 1 línea, typos, instrucción directa del usuario ("cambia X por Y en archivo Z"), o "implementa directamente sin plan".

---

## 2. Actitud proactiva con bugs

Cuando encuentres un bug — en logs, consola, stacktrace, `storage/logs/laravel.log`, response rara — **NO lo señales y sigas**. Investiga, decide alcance, arregla.

**Arreglar siempre, sin pedir permiso**:
1. Bug que ves causar el error del log (stacktrace claro + fix obvio).
2. Bug en archivo que ya tocaste en la rama actual.
3. Bug en la cadena de la feature que estás haciendo.
4. Bug que afecta lo que el usuario mira ahora mismo.
5. Cualquier bug en logs locales mientras laburás en la rama.

**Antes de declarar "preexistente y fuera de scope"** confirma:
- ¿Sigue ocurriendo? Fechas del log + `git blame` + `git log` del archivo. Capaz un commit reciente ya lo resolvió.
- ¿Está el archivo en mi PR? Si sí, scope obligatorio.
- ¿Fix local o cross-cutting? Aislado → fíxalo. Cross-cutting con blast radius → explica y pregunta.

**NO arreglar (excepciones reales)**:
- Necesita decisión de producto (UX, política contable, copy final).
- Riesgo contable/financiero (dinero, receipts, invoices, estados terminales) → presenta diff y espera confirmación.
- Fix dispara cascada en módulos no relacionados → separar PR.
- Bug claramente abandonado (código retirado, flag deshabilitado, archivos huérfanos).

**Cómo comunicar el fix**:
- Commit con prefix `fix(<area>):` separado del `feat:` principal. Un commit por bug si son distintos.
- Respuesta al usuario: 2 líneas — qué bug, qué hiciste.

**Anti-patrón explícito**:
- ❌ "Encontré bug en `X.php:1261`, lo dejo señalado para issue separado."
- ✅ "Bug en `X.php:1261` (namespace mal importado). Arreglado en este PR como `fix(orders): import App\Models\User`."

---

## 3. Registro en comentarios de GitHub como historial

Los comentarios del issue son la **fuente de verdad histórica**. Hay que dejar registro de acciones significativas para que sobrevivan al compactado de sesión y sirvan a futuros agentes/humanos.

**Publica comentario cuando**:
1. Refinamiento inicial (plan + sección Impacto RBAC).
2. Decisiones de producto durante la conversación (qué, por qué, alternativas descartadas).
3. Cambios al alcance vs plan original.
4. Fase/hito completado.
5. Bloqueador encontrado (descripción, dónde, qué se intentó).
6. Bloqueador resuelto (cómo, decisión asociada).
7. Cierre del issue (resumen final, scope dentro/fuera, sub-issues, PRs).
8. Resultado de QA manual (checklist + hallazgos).

**NO publicar**: progreso intermedio trivial ("estoy revisando..."), diffs completos (van en commit/PR), logs crudos largos (resumir y enlazar), redundancia con commit message, pensamiento en voz alta.

**Formato**:
- Markdown limpio. Encabezado descriptivo (`## Decisiones tomadas`, `## Fase 1 completada`, `## Cierre del issue`).
- Cuerpo conciso, accionable, bullets cuando aplique.
- Cerrar con **estado actual** (qué sigue, qué se espera del usuario) si el issue queda abierto.
- Referencias: archivos como `path/archivo.php:NN`, PRs/commits/sub-issues con `#NNN` o URL.

**Frecuencia**:
- 1 comentario por hito significativo, no por cada acción.
- Sesión con múltiples decisiones chicas → agrupa en un solo comentario de cierre.
- Sesión solo de exploración o preguntas sin hitos → no publicar nada.
- Decisión que revierte una anterior → comentario nuevo referenciando el anterior (NO editar viejos — append-only para auditoría).

**Cómo se publica**:

```bash
gh issue comment <NNN> --repo funcionbase-com/bistro \
  --body-file .tmp_decisions_NNN.md
rm .tmp_decisions_NNN.md
```

Preferir `--body-file` sobre `--body` por escape de multilinea.

**Por qué existe esta regla**: compactado de sesión, handoff entre agentes, auditoría append-only (alineado con cultura DIAN del proyecto), revisión humana sin abrir sesiones de Claude.

**Relación con `handoff.md`**: `handoff.md` es para retomar **la sesión actual**. Comentarios de GitHub son **historia permanente del issue**. Complementarios, no sustitutos. Decisión importante → GitHub (no solo handoff).

---

## 4. Handoff entre sesiones

Archivo `handoff.md` en la raíz como brief vivo de la sesión actual. NO documentación estática — puente para no perder contexto al compactar/cerrar.

**Cuándo actualizar**: antes de `/compact` o `/clear`, y al final de una sesión significativa. **Sobreescribir** (NO append). Si el usuario invoca `/compact` o `/clear` y no se actualizó, actualizar primero y luego permitir.

**Cuándo leerlo**: NUNCA automáticamente al inicio. SÓLO si el usuario lo pide ("lee el handoff", "retomamos donde quedamos", "qué pendía"). Si no se pide, ignorarlo — el contexto vive en `CLAUDE.md` y memoria persistente.

**Qué incluir (bajo ~200 líneas)**:
- Rama y estado git (rama activa, commits ahead/behind main, PR abierto).
- Objetivo de la sesión: una sola frase.
- Última acción tomada.
- Bloqueadores abiertos (errores, decisiones pendientes, CI/CD en curso).
- Archivos tocados (lista breve).
- Próximo paso sugerido.
- IDs en vuelo (GitHub run IDs, SSM command IDs, CFN stack names).

**NO incluir**: explicaciones extensas, diffs completos, logs crudos. Esos viven en commit history / workflow logs / código.

**Verificación**: si al iniciar el usuario pregunta "¿de qué trata este proyecto?", responder con info de `CLAUDE.md` + memoria persistente. Si pregunta "qué quedó pendiente la última sesión", ahí sí leer `handoff.md`.

---

## 5. Documentación viva

Después de cualquier cambio frontend o backend, actualizar:
- `docs/wiki/FUNCIONALIDADES_APP.md`
- `docs/wiki/FRONTEND_FILES.md`
- `docs/wiki/BACKEND_FILES.md`

(En el repo actual viven en `docs/wiki/`; si el path de `application/` no existe, usar `docs/wiki/`.)

---

## 6. Versionado y releases

**Versión = SemVer manual, mantenida por Claude.** Dos fuentes de verdad independientes (frontend y backend versionan por separado):

- **Frontend** → `frontend/package.json` campo `"version"`.
- **Backend** → `backend/composer.json` campo `"version"`.

**Regla obligatoria — al cerrar un cambio destinado a desplegarse:**

1. **Bump del `version`** en el manifest del plano que tocaste (`package.json` para frontend, `composer.json` para backend), siguiendo SemVer:
   - `patch` (x.y.**Z**): fix o cambio interno sin impacto de API/UX.
   - `minor` (x.**Y**.0): feature nueva retrocompatible.
   - `major` (**X**.0.0): breaking change (contrato API, payload JWT, schema irreversible).
2. **Badge del README: NO lo toques a mano** — el workflow `Version Guard` (`.github/workflows/version-guard.yml`) lo autosincroniza desde los manifests en cada push a `main` (commit `[skip-badge-sync]`).
3. Si tocaste ambos planos en el mismo cambio, bumpeá ambos.
4. **El olvido rompe el push**: `Version Guard` falla el push a `main` si cambió código desplegable (`frontend/src/**` o `backend/{app,routes,config,database}/**`) sin bump del manifest correspondiente.

**Releases automáticos (solo backend, solo pdn):** el workflow `App Deploy` crea el tag `bistro-backend-v<version>` y publica el GitHub Release leyendo `composer.json` de la rama desplegada — **únicamente cuando `environment=pdn` y el deploy termina OK**. Es idempotente: si el release ya existe (version sin re-bump) hace skip. Por eso `/releases` solo refleja lo que realmente corrió en producción.

- **qa NUNCA taguea** (entorno desechable).
- **Frontend NO auto-taguea**: se publica a Cloudflare con `wrangler deploy`, sin workflow. Si querés un release de frontend, créalo a mano (`gh release create bistro-frontend-vX.Y.Z`).
- Para que un cambio de backend genere release: bumpeá `composer.json`, mergealo a `main`, y corré `App Deploy` con `environment=pdn`.

**Excepciones (no requieren bump):** cambios solo a docs/wiki, CLAUDE.md, IaC, workflows, o assets que no se despliegan con la app.
