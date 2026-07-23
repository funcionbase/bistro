# flexyflow Panel — Guía de UI v3.4


Esta guía define el sistema visual, de copy y técnico para **bistro.flexyflow.co**. Adapta el lenguaje de la **Fase 2 del rediseño** del sitio marketing (`flexyflow.co` v2.1) a una aplicación SaaS operativa: POS, caja, planificador de turnos, reportes, PWA, modo offline.


**Stack:** Laravel 12 · Inertia v2 · React 19 · Tailwind CSS 4 · shadcn/ui (Radix) · Ziggy · Vite.

**Idioma:** Solo español (la app no es multilingüe). El tono de la UI es español neutro profesional con calidez — sin modismos paisas, sin diminutivos exagerados.

**Relación con el marketing:** la paleta hex y los tokens semánticos del rediseño están cableados en `resources/css/app.css`. Esta guía documenta cómo se aplican en componentes operativos (tablas, modales, formularios, sidebars) y cubre las áreas que no existen en el landing: dark mode, mobile-first, offline, PDFs, PWA.

---

## 1. Filosofía visual

Minimalismo editorial sobre fondo claro con lime `#C0FD79` como **acento positivo y editorial** de la marca. El contraste fuerte entre claro, lime y oscuro define la identidad. El lime se usa para momentos positivos a lo largo de la UI — logros, estados completados, confirmaciones, hitos celebrables — y como decoración editorial (eyebrows, pills de sección, etiquetas de categoría sobre surface dark, dot pulse "en vivo"). Se multiplica con criterio jerárquico (ver §3): un solo block-lime grande por vista, elementos chicos sin límite numérico, prohibido en warning/error/neutro frío.

Referentes que rigen el lenguaje visual (mismos que el marketing):

- **Bakken & Bæck** — disciplina, whitespace, tipografía como protagonista.
- **Slalom Build** — banner limpio, declarativo, sin gradientes "AI".
- **BCG X / Celonis** — simplicidad y contraste corporativo.
- **Linear** — densidad de información tolerada cuando es producto operativo.

Lo que **no** queremos: gradientes radiales tipo startup, glow effects, ilustraciones 3D genéricas, microcopy de bot ("¡Genial! Tu acción fue procesada con éxito 🎉"), iconografía abstracta sin función.

### Diferencias respecto al marketing

| Aspecto | `flexyflow.co` | `bistro.flexyflow.co` |
|---|---|---|
| Tipo | Landing editorial | App SaaS operativa |
| Bloques | Sections full-width | Páginas con sidebar |
| Lime | Block hero + CTA final + eyebrows + decoración | Block hero + CTA final + eyebrows + decoración + status semáforo operativo |
| Modos | Solo light | Light (default) + dark |
| Conversión | Form Netlify | App autenticada |
| Densidad | Whitespace generoso | Densidad operativa controlada |
| Mobile | Responsive | PWA + offline + BottomSheet |

---

## 2. Voice & tono de UI

**Tono base:** español neutro profesional con calidez. Cercano y confiable sin caer en SaaS frío ni en "asistente entusiasta". La app le habla a una cajera, a un mesero, a un dueño de restaurante — gente que está apurada, no quiere leer una novela.

### Reglas de copy

- **Verbos en infinitivo** para acciones del usuario en botones: `Abrir caja`, `Cerrar día`, `Asignar turno`. Nunca `Abriendo caja...` para el label estable, eso es solo para el estado loading.
- **Segunda persona singular con tuteo neutral (tú)**: `Tu caja está cerrada`, `No tienes turnos asignados`. Nunca "usted" (suena distante), ni "el usuario" (impersonal), ni voseo (`probá`, `mirá`, `tenés`) — el voseo es regional y queda fuera del registro neutro panhispánico que la app necesita.
- **Frases declarativas cortas**. Si una frase necesita coma, probablemente quepa en dos.
- **Números concretos antes que adjetivos vagos**: `3 turnos esta semana`, no `Algunos turnos esta semana`.
- **Errores accionables**, no descriptivos. ❌ `Error: No se pudo abrir la sesión` → ✅ `No pudimos abrir la caja porque ya hay una sesión activa. Ciérrala primero.`
- **Empty states con siguiente paso**: ❌ `No hay datos` → ✅ `Aún no hay órdenes hoy. Las que cobres aparecerán acá.`
- **Sin modismos paisas en UI**. El tono paisa-cariñoso del `CLAUDE.md` raíz **aplica solo al chat de Claude**, nunca a strings de UI. Un mesero de Bogotá o Cali también usa la app.

### Ejemplos antes / después

- ❌ `Operación realizada exitosamente` → ✅ `Listo, caja abierta`
- ❌ `No se han encontrado resultados que coincidan con su búsqueda` → ✅ `Nada coincide con "espinaca". Prueba con otra palabra.`
- ❌ `Por favor verifique los campos marcados en rojo` → ✅ `Faltan datos. Revisa los campos resaltados.`
- ❌ `El sistema se encuentra en modo offline` → ✅ `Sin conexión. Tus cobros se guardan y se sincronizan cuando vuelva.`
- ❌ `¿Está seguro que desea cancelar la orden?` → ✅ `Cancelar esta orden. ¿Seguro?`

### Reglas para mensajes financieros

- Usar `$` con separador de miles: `$ 1.250.000`. Nunca dejar números pelados en montos visibles.
- Refunds se muestran con signo y color: `−$ 35.000` en `text-destructive`. Nunca usar paréntesis estilo contabilidad anglosajona (`(35.000)`) — confunde al cajero.
- Diferencias de caja: usar palabras antes que solo color. `Sobra $ 2.000` / `Falta $ 5.000`, no solo verde/rojo. El color refuerza, no comunica.

---

## 3. Paleta de color

Los tokens viven en `resources/css/app.css`. La app usa el sistema de variables CSS de shadcn (`--background`, `--foreground`, `--primary`, etc.) mapeado a la paleta del rediseño. **Nunca usar hex hardcoded en componentes** — siempre vía token Tailwind (`bg-primary`, `text-foreground`).

### Tokens semánticos (light mode)

```css
/* Base */
--background:       #F0F0F0;   /* body */
--foreground:       #1E232E;   /* texto sobre claro */
--card:             #FFFFFF;   /* superficie de card */
--card-foreground:  #1E232E;

/* Acentos */
--primary:          #0052FF;   /* azul marca — CTAs primarios, links */
--primary-foreground: #FFFFFF;
--accent:           #C0FD79;   /* lime — acento positivo y editorial (ver política completa abajo) */
--accent-foreground: #1E232E;
--destructive:      #D9402A;   /* terracota editorial — dark: #F0876B (ver §3 "Por qué terracota") */

/* Neutros */
--secondary:        #E5E5E5;   /* ⚠️ ver nota de remap abajo */
--muted:            #E5E5E5;
--muted-foreground: #6B7280;
--border:           #E5E5E5;

/* Sidebar (tono cálido editorial) */
--sidebar-background: #F6F5F3;
--sidebar-accent:     #EEF3FF;
--sidebar-accent-foreground: #0052FF;
```

### ⚠️ Remap de `secondary` vs website

El convention de shadcn cambia el **rol semántico** del token `secondary` respecto al marketing v2.1. No es un alias — es otro color con otra función:

| Token | Website v2.1 (`theme.css`) | App v3 (`app.css`) |
|---|---|---|
| `secondary` | `#1E232E` — oscuro principal, peso, footer | `#E5E5E5` — neutro gris, hover sutil |

El rol "oscuro principal" en la app vive en `--foreground` (`#1E232E`) y en `--color-body-dark` (`#232733`). **No uses `bg-secondary` esperando que sea oscuro** — saldrá gris pastel. Para superficies oscuras (block-dark, sidebar dark, footer PWA), usa `bg-foreground` con `text-background`, o el hex literal del rediseño vía `bg-[color:var(--color-body-dark)]`.

### Tokens del rediseño (no-shadcn, uso explícito)

Estos están en `app.css` como utilidades CSS y se usan cuando se necesita el color literal del rediseño (bloques de marca, headers de PDF, transcripts de auth hero):

```css
--color-body-dark:    #232733;   /* footer / dark surface */
--color-theme-light:  #F6F5F3;   /* hero editorial / sidebar */
--color-dark:         #232733;   /* headings sobre claro */
--color-accent-blue:  #0B61FF;   /* hover variante de primary */
```

### Status semáforo

Para estados operativos (stock, turno activo, sincronización, cuadre de caja). Idéntico en light + dark mode con saturación ajustada en dark:

```css
--color-status-safe:     #22C55E;   /* dark: #4ADE80 */
--color-status-warning:  #F39C12;   /* dark: #FBBF24 */
--color-status-critical: #D9402A;   /* dark: #F0876B · unificado con --destructive (terracota editorial) */
```

**Convención de uso por dominio:**

| Dominio | safe | warning | critical |
|---|---|---|---|
| Stock | `> 20%` | `5–20%` | `< 5%` |
| Turno | activo en ventana | termina en `< 30min` | sin turno |
| Pago | conciliado | sin reference | refund pendiente |
| Sync offline | 0 pendientes | `1–5` pendientes | `> 5` pendientes o `> 1h` |
| Cuadre caja | diferencia `$0` | diferencia `≤ $5.000` | diferencia `> $5.000` |

### Por qué terracota — `destructive` + `critical` unificados (v3.2)

Desde v3.2, `--destructive` y `--color-status-critical` comparten el mismo hex: **terracota editorial** `#D9402A` (light) / `#F0876B` (dark). Antes eran dos rojos sutilmente distintos (`#EF4444` y `#E74C3C`), tan parecidos que en pantalla no se distinguían — la duplicación no aportaba información, solo carga cognitiva.

**Razón del cambio:**

1. **Una sola voz roja** — el DS tiene tres colores de marca (primary azul `#0052FF`, accent lime `#C0FD79`, destructive/critical terracota `#D9402A`). Mantener dos rojos casi iguales fragmentaba la paleta sin razón funcional.
2. **Personalidad editorial** — el rojo Tailwind genérico (`#EF4444`) es "el rojo de SaaS"; el terracota tiene identidad de marca, alineado con referentes Bakken & Bæck / Linear / Stripe. Combina mejor con el lime (rojo cálido vs verde-amarillo) sin pelearse.
3. **Diferenciación con warning** — el `#D9402A` está suficientemente lejos en hue del `--color-status-warning: #F39C12` (naranja) para no confundirse, manteniendo el semáforo legible.

**Distinción semántica que sigue válida** (aunque compartan color):

- **`--destructive`** — rojo de **acciones que el usuario dispara** para destruir/cancelar algo. Botones `Button variant="destructive"`, monto refund en tabla (`text-destructive`), `Alert variant="destructive"`, borde de input con error inline, `InputError` debajo del input.
- **`--color-status-critical`** — rojo de **estados que el sistema/datos disparan** en semáforo operativo. Badge "Sin stock", "Caja con falta", "Sync pendiente", dot crítico al lado de un turno sin asignar, indicador "Refund sin reference".

Una pantalla de caja puede coexistir un badge "Caja con falta" (status-critical, lo dice el sistema) con un botón "Eliminar receipt" (destructive, lo dispara el cajero). Comparten color pero el rol semántico distinto vive en el componente (Badge vs Button) y en el copy del label.

**Migración**: `app.css` ya tiene los tokens nuevos. Componentes que consumen los tokens (`bg-destructive`, `text-destructive`, `text-[color:var(--color-status-critical)]`) toman el terracota automáticamente en el siguiente build. Hex hardcoded en componentes (`text-red-500`, `bg-red-50`) sigue siendo brecha conocida (§21).

### Paleta complementaria (v3.3 — solo documentación, `app.css` pendiente)

Auditoría de mayo 2026 sobre 200+ componentes detectó **4 gaps semánticos** en el DS actual: no existe token `info`, el `success` se confunde con `safe`, el `warning` se fragmenta en 3 hues (amber/yellow/orange) y no hay paleta categórica para avatares/BCG matrix/tipos de invoice. Esta sub-sección documenta los **tokens propuestos** para cerrar esos gaps. **No están todavía en `app.css`** — primer paso es alinear nomenclatura y mapping de migración para que el equipo pueda usar `bg-[color:var(--color-status-info)]` ad-hoc hasta que se agreguen como variables.

#### Tokens propuestos

```css
/* Light mode */
--color-status-info:     #0284C7;   /* azul cyan-celeste — distinto de --primary */
--color-status-success:  #059669;   /* verde menta — distinto de --color-status-safe */
/* --color-status-warning: #F39C12  — ya existe, ver unificación abajo */
/* --color-status-critical: #D9402A — ya unificado con --destructive */

--color-category-violet: #7C3AED;
--color-category-cyan:   #0891B2;
--color-category-pink:   #DB2777;
--color-category-amber:  #CA8A04;
--color-category-green:  #16A34A;

/* Dark mode (saturación ajustada para fondos #232733 / #1E232E) */
--color-status-info:     #38BDF8;
--color-status-success:  #34D399;
/* warning dark ya existe: #FBBF24 */
/* critical dark ya existe: #F0876B */

--color-category-violet: #A78BFA;
--color-category-cyan:   #22D3EE;
--color-category-pink:   #F472B6;
--color-category-amber:  #FBBF24;
--color-category-green:  #4ADE80;
```

Stops auxiliares para fills y borders (mismo patrón que shadcn `c-{ramp}` 50/200/600/800):

| Token | 50 (fill) | 200 (border) | 600 (main) | 800 (text on fill) |
|---|---|---|---|---|
| `info` | `#E0F2FE` | `#BAE6FD` | `#0284C7` | `#075985` |
| `success` | `#ECFDF5` | `#A7F3D0` | `#059669` | `#065F46` |
| `warning` (DS actual) | `#FEF3C7` | `#FDE68A` | `#F39C12` | `#92400E` |

#### Cuándo usar cada token

**`--color-status-info` vs `--primary`**: ambos son azules, pero comunican cosas distintas. `--primary` `#0052FF` es el azul de **acción del usuario** — CTAs, links, focus rings, sidebar activo, numeración ornamental. `--color-status-info` `#0284C7` (cyan-celeste, más claro y verdoso) es el azul de **información del sistema** — toasts informativos, badges de "Suscripción", "En tránsito", "Pago en revisión", chip "leído" en mensajes. No compiten porque sus roles son distintos: si hacé click en algo es `--primary`; si te están avisando algo neutro es `--color-status-info`.

**`--color-status-success` vs `--color-status-safe`**: ambos son verdes, pero conceptualmente distintos. `--color-status-safe` `#22C55E` (verde semáforo brillante) es **estado operacional medido** — stock > 20%, turno activo en ventana, sync sin pendientes, cuadre $0. Lo dispara el sistema midiendo realidad operativa. `--color-status-success` `#059669` (verde menta más oscuro) es **UI feedback de confirmación** — `Toast.success`, "Empleado creado", "Pago aceptado", "Comprobante validado", "Margen OK". Lo dispara una acción que terminó bien. Regla práctica: si lo medís contra un umbral (5% stock, 30min turno), es `safe`. Si es un "listo, ya quedó", es `success`.

**`--color-status-warning` — unificación**: el token existente `#F39C12` se mantiene como hue único. Hoy hay drift: `amber-*` (PastDueBanner, food-cost margin warning, active-orders pending, menu-eng warnings), `yellow-*` (offline-banner, storage-quota-warning), `orange-*` (Invoice.pending, active-orders.in_kitchen, PWA install prompts, update-toast). **Todos migran a `--color-status-warning`**. Excepción decorativa: `branch-switcher` usa `fill-amber-500` para la estrella de "sede favorita" — eso no es semántico, es decoración brand de favoritos, se queda como está o se documenta como categórico aparte.

**`--color-category-*` vs status tokens**: los category tokens son para **identidad sin estado**. El color identifica una persona, una categoría, un cuadrante, no comunica "bueno/malo/atención". Usos: avatar de repartidor (hashing por ID → 1 de 5 categorías), BCG matrix (`menu-engineering-panel`: star=success, cow=info, puzzle=category-violet, dog=destructive), tipos de invoice (`InvoiceTypeChip`: subscription=info, addon=category-violet). **Nunca usar status tokens para categorías** porque "tipo addon es success" no comunica nada y rompe el lenguaje semántico.

#### Mapping de migración (drift Tailwind → token DS)

Tabla canónica para refactorizar el drift detectado en la auditoría. **Aplicar cuando se toque cada archivo, no en barrido masivo** para minimizar riesgo de regresión visual.

| Drift Tailwind actual | Token DS propuesto | Componentes afectados (top) |
|---|---|---|
| `bg-blue-50/100`, `text-blue-700/800`, `text-sky-400` (informativo) | `bg-[color:var(--color-status-info)]/15`, `text-[color:var(--color-status-info)]` | `ui/toast.tsx` (variant info), `InvoiceTypeChip` (subscription), `active-orders-panel` (in_transit), `chat-message-status-ticks` (leído) |
| `bg-green-50/100`, `text-green-700/800`, `bg-emerald-50`, `text-emerald-600/700`, `#10b981` | `bg-[color:var(--color-status-success)]/15`, `text-[color:var(--color-status-success)]` | `ui/toast.tsx` (success), `InvoiceStatusBadge` (paid), `SuspendedBlockedView` (accepted), `UploadPaymentProof`, `delivery-status-badge`, `delivery-card`, `confirm-complete-modal`, `food-cost-panel`, `menu-engineering-panel` (star) |
| `bg-amber-*`, `bg-yellow-*`, `bg-orange-*`, `text-amber/yellow/orange-*` (semántico de aviso) | `bg-[color:var(--color-status-warning)]/15`, `text-[color:var(--color-status-warning)]` | `PastDueBanner`, `SuspendedBlockedView` (submitted), `InvoiceStatusBadge` (pending), `active-orders-panel` (pending/in_kitchen), `offline-banner`, `storage-quota-warning`, `install-pwa-prompt`, `ios-install-hint`, `update-available-toast`, `food-cost-panel`, `menu-engineering-panel` |
| `bg-red-*`, `text-red-*` (acción destructiva o estado crítico) | `bg-destructive/10`, `text-destructive`, o `bg-[color:var(--color-status-critical)]/15` | `ui/confirm-dialog.tsx`, `InvoiceStatusBadge` (overdue), `SuspendedBlockedView` (rejected), `PastDueBanner` (final), `delete-user`, `delivery-status-badge`, `client-detail-modal`, `chat-message-status-ticks` (error), `input-error`, `whatsapp-verification-code-modal`, `food-cost-panel` (margen bajo + `#dc2626`), `menu-engineering-panel` (dog + `#dc2626`) |
| `bg-purple-*`, `text-purple-*`, `#a855f7` (categórico) | `bg-[color:var(--color-category-violet)]/15`, `text-[color:var(--color-category-violet)]` | `InvoiceTypeChip` (addon), `menu-engineering-panel` (puzzle) |
| `bg-{blue/green/purple/orange/pink/teal/indigo/red}-500` (rotación avatar) | Rotación `--color-category-*` (5 tonos) con hash por ID | `deliveries/courier-avatar` |
| `rgba(0, 82, 255, ...)` (heatmap intensity hardcoded) | Token RGB derivado de `--primary` (`rgba(var(--primary-rgb), ...)`) — agregar variable RGB en `app.css` | `dashboard/heatmap-chart` |
| `bg-gray-50/100`, `bg-zinc-100`, `bg-neutral-100`, `bg-neutral-200`, `dark:bg-neutral-700/900` | `bg-muted`, `bg-secondary` según contexto | `InvoiceDetailModal`, `SuspendedBlockedView`, `food-cost-panel` (zinc N/D), `user-info` (avatar fallback) |
| `text-gray-400/500/600/700`, `text-zinc-400/600` | `text-muted-foreground`, `text-foreground` según contraste | `InvoiceDetailModal`, `SuspendedBlockedView`, `print-receipt-button`, `shortcut-tooltip`, `shortcuts-help-modal` |
| `border-gray-200/300` | `border-border` | `SuspendedBlockedView`, `print-receipt-button` |
| `bg-gray-300` (handle drawer) | `bg-muted-foreground/30` | `ui/bottom-sheet.tsx`, `ui/bottom-sheet-dialog.tsx` |
| `bg-gray-700/40`, `bg-gray-900`, `border-gray-500/40` (kbd, tooltip oscuro) | `bg-foreground/10`, `bg-foreground`, `border-foreground/20` | `nav-main` (kbd), `shortcut-tooltip` |

#### Drift legítimo — no migrar

- **`menu-qr-poster.tsx`** — usa Canvas API (`ctx.fillStyle = '#ffffff'`). Canvas no consume CSS vars en runtime, los hex son necesarios.
- **`google-auth-button.tsx`** — hex del logo Google (`#4285F4`, `#34A853`, `#FBBC05`, `#EA4335`). Brand de terceros, intocable.
- **`chat-message-media.tsx`** — paths SVG del pin de Google Maps (`#EA4335`, `#4285F4`). Brand de terceros.
- **`role-badge.tsx`** — usa `#111827` / `#ffffff` para text-contrast logic contra `company_roles.color` (color custom por empresa). La lógica de luminancia es legítima.
- **`branch-switcher.tsx`** — `fill-amber-500` para estrella de "sede favorita". Decorativo, no semántico. Se puede dejar o documentar como categórico aparte.
- **Grid lines de Recharts** en `rgba(0,0,0,0.06)` — color neutro de gráfico, no requiere token de marca.

#### Pasos para implementar (cuando se decida)

1. Agregar los 4 tokens al `app.css` en `:root` y `.dark`.
2. Agregar variants nuevas a `Badge` (`info`, `success`) y `Alert` (`info`, `success`).
3. Refactorizar primitivas con drift: `toast.tsx` (usar variants info/success/destructive), `confirm-dialog.tsx` (destructive token), `bottom-sheet*` (muted-foreground handle).
4. Refactor por feature uno a uno, verificando visualmente cada PR. **No barrido masivo** — el riesgo de regresión es alto en una codebase con tanto drift acumulado.
5. Bumpear a v3.3 cuando todo esté en `app.css` + primitivas migradas.

### Color de marca del cliente (multi-tenant)

Cada empresa puede personalizar su `primary_color` (hex que el owner edita en `Dashboard → Configuración → Mi empresa`). En páginas públicas o flujos en los que el comensal ve la marca del restaurante (menú público `/menus`, mesa con QR `/t/{token}`, póster del QR), ese color **debe aplicarse en puntos clave, no como distintivo dominante**.

**Permitido (puntos clave):**

- Línea o borde acentuado fino (3-4px) en el top del header — `boxShadow: inset 0 3px 0 0 ${accent}` sobre `bg-card`.
- Dot pequeño (8px) junto al nombre comercial — indicador de identidad sin invadir.
- Chip de "Mesa N" usando el accent como `backgroundColor` con texto blanco — identifica el contexto físico del comensal.
- Borde lateral fino (2-4px) a la izquierda del título de cada categoría del menú — repetible sin saturar.
- Ícono pequeño del footer con `style={{ color: accent }}`.

**Prohibido (rompe la jerarquía):**

- Header completo con el primary_color como `backgroundColor` — el comensal lee texto blanco/luminoso sin saber si el contraste cumple AA; además resta protagonismo al contenido del menú.
- Precio de cada item con `color: accent` — se repite 30+ veces en pantalla y se vuelve ruido visual; el precio va en `text-foreground` y se distingue por peso (`font-semibold tabular-nums`).
- Heading H2 de categoría completo con `color: accent` — mismo problema, repetición que satura.
- Botones primarios con `backgroundColor: accent` inline — usar `Button` del DS con su variant default y dejar el accent solo en bordes/dots.

**Hex inválido o ausente:**

Siempre validar que `primary_color` cumple `^#[0-9a-fA-F]{6}$` antes de aplicarlo en `style`. Fallback al token foreground del DS (`#0F172A` en light) si no es válido. Nunca dejar entrar un valor del backend al `style` sin validar — abre la puerta a CSS injection trivial.

### Dark mode

La app **defaultea a light**. El dark es un toggle de usuario (`appearance-dropdown.tsx`). Todos los componentes y páginas DEBEN funcionar en ambos modos — verificarlo antes de mergear.

Reglas para autores de componentes:

- Nunca hex hardcoded. Siempre `bg-card`, `text-foreground`, `border-border`.
- Si necesitás un color que no existe como token, no inventes — abrí PR para añadirlo a `app.css`.
- Sombras en dark: usar `shadow-none` o `ring-1 ring-border` en lugar de `shadow-lg`. Las sombras pesadas no se ven en dark.
- Gradientes: prohibidos salvo aprobación. Si tenés que usar uno, debe degradar entre dos tokens existentes (no hex literales).

### Lime — política de uso (positivo + editorial)

El lime `#C0FD79` es el acento de **momentos positivos** y de **decoración editorial** de la marca. Se multiplica a lo largo de la UI siempre que cada uso comunique algo positivo o sea ornamento editorial coherente, respetando la jerarquía y las reglas anti-saturación de abajo.

#### Cuándo SÍ — momentos positivos

1. **Onboarding / bienvenida** — hero de `welcome.tsx`, paso final del enrollment, pantalla "empresa creada".
2. **Estados de logro / loyalty / completado** — `LoyaltyBadge` (cliente VIP), badge "Turno listo" en planificador, badge "Conciliado" en caja, badge "Completado" en filas de tabla, banner "Caja cuadrada sin diferencias", icono check en checklist resuelto.
3. **CTAs de naturaleza positiva** — `Cerrar día` cuando todo está conciliado, `Publicar turnos` cuando el planificador está completo, `Confirmar`, `Marcar listo`, `Completar onboarding`. Pueden coexistir varios botones `accent` en la misma vista si todos representan acciones positivas (ver §8).
4. **KPIs que superan meta** — métrica del día por encima del objetivo, ventas que rebasan el periodo anterior, ratio de conversión sobre el benchmark. El delta positivo va lime; el negativo va `destructive` o status semáforo.
5. **Toasts y confirmaciones de éxito** — toast `Empleado creado`, banner verde de "Sincronización completada". Lime va en el ícono o borde, **no** en el fondo full del toast (ver regla anti-saturación 7).

#### Cuándo SÍ — decoración editorial

6. **Pills / eyebrows uppercase** — chips encima de H1 con `bg-accent text-accent-foreground` (`COLABORADORES`, `RESUMEN`, `CONTACTO`, `MANUAL DEL PRODUCTO`). Mismo patrón que el website.
7. **Dot pulse decorativo** — `h-1.5 w-1.5 rounded-full bg-accent animate-pulse` dentro de pills "En vivo", indicadores de polling activo, presencia online de un colaborador. Es decoración positiva pura, no estado crítico.
8. **Labels de categoría sobre surface dark** — `text-accent` uppercase tracking sobre `bg-foreground` para etiquetar grupos en footer dark, sidebar dark (cuando está activo), panel oscuro de configuración. Patrón validado en footer de `flexyflow.co/` (`EXPLORAR`, `EMPRESA`).
9. **Stats héroe sobre block-lime** — números grandes (`24/7`, `3×`, `−60%` en el patrón `KpiHero`) sobre `bg-accent`. El lime es el lienzo; los stats viven en negro tabular encima.

#### Cuándo NO — prohibido aunque parezca tentador

- **Warning / error / refund / offline / modo readonly** — esos van por status semáforo (`safe/warning/critical`), `destructive` o `secondary`. Lime es **celebrable**, no "OK funcional" ni "estado resuelto" tras una falla.
- **Hover / focus / pressed** — el lime comunica estado, no interacción. Los hovers siguen siendo `bg-muted/40` o `bg-primary/90`; los focus rings van con `ring-ring` (azul primary).
- **Badges de "nuevo"** sin connotación positiva clara — si es etiqueta neutra de novedad, usar `secondary`.
- **Fondo de cards estándar, separadores, bordes, divisores** — esos viven en `bg-card`, `border-border`, `bg-border`.
- **Numeración ornamental** (`01`, `02`, `03` en grids de servicios o capacidades) — eso es rol de `text-primary` (azul), no del lime. Confirmado en website.
- **CTAs neutros** (Cancelar, Volver, Cerrar sin guardar, filtros, paginación) — `outline`, `ghost`, `secondary`, nunca `accent`.
- **Refunds o montos negativos** — `text-destructive`, no lime.

#### Reglas anti-saturación

La política positiva es permisiva, pero la saturación destruye el valor del lime. Estas reglas son **duras**:

1. **Presupuesto por viewport: máximo 3 elementos lime visibles** a la vez (block, badge, pill, icono y botón cuentan). Si hay un block-lime hero visible, ese consume 1 del presupuesto y solo quedan 2 elementos chicos antes de saturar.
2. **Block grande + accent CTA exclusivos en el mismo fold**: si la vista tiene `block-lime` hero arriba, el `Button variant="accent"` del flujo va **fuera del viewport inicial** o se baja a `variant="dark"` (negro sobre lime) / `variant="default"` (azul). No coexisten visibles.
3. **Tablas con > 30% de filas en estado positivo**: el badge "completado" deja de usar lime y pasa a `variant="safe"` (verde semáforo). Lime se reserva para hitos raros en esa lista (ej. "primera venta del mes", "récord histórico"). Regla práctica: si el lime aparece en cada fila, ya no significa nada.
4. **Un solo eyebrow lime por página**: los pills uppercase encima de H1 con `bg-accent` (modo "logro") solo se usan en **una** sección de la página. El resto de eyebrows en la misma vista van con `bg-secondary` (neutro).
5. **Lime no se acumula en header + footer**: si el header de la página tiene un elemento lime (eyebrow, badge), el footer/CTA final no es lime — usa `dark` o `primary`. El ojo lee la pantalla de arriba abajo, el lime no debe enmarcar.
6. **Hover y focus**: prohibido. Repetido por importancia. El lime no es interacción.
7. **Toasts positivos**: lime solo en el ícono (`text-accent-foreground` sobre `bg-accent` chip) o borde izquierdo del toast, **no** en fondo full. El fondo del toast se queda en `bg-card`.
8. **Refunds, errores, modo readonly, offline**: cero lime aunque sean "estados resueltos". Esos van por status semáforo (`safe/warning/critical`) o `destructive`. El lime es celebrable, no "OK funcional" tras una falla.

#### Jerarquía del block grande

Un solo **block-lime grande** por vista (hero `KpiHero`, sección CTA final tipo `bg-accent text-accent-foreground rounded-3xl p-12`). Los elementos lime chicos (badges, pills, dots, eyebrows, etiquetas de categoría) **no cuentan contra ese límite** — pero suman al presupuesto de 3 visibles del anti-saturación 1.

Una página puede tener: 1 block hero lime + 1 eyebrow lime + 1 badge lime "completado" = 3 elementos visibles, dentro del presupuesto. No puede tener: 2 blocks lime grandes + 4 badges lime, eso satura.

#### Regla de oro

Si dudás si meter lime, no lo metas. El daño de saturar lime es destruir el tono editorial de la marca; el daño de faltar lime es invisible para el usuario. El lime es contraste contra neutro — si la pantalla se vuelve mayoritariamente lime, deja de ser contraste.

#### Lime ≠ FlexyFont

Que el lime se multiplique para momentos positivos **no implica** que la `FlexyFont` también. Son políticas independientes:

- **Lime**: acepta multiplicación con jerarquía (block grande + chicos sin límite numérico, sujetos a las reglas anti-saturación de arriba).
- **FlexyFont**: sigue **escasa** según §4. Solo logo, hero de auth (`/login`, `/register`, `/enrollment/*`), hero de `welcome.tsx`, PDFs (header/totales/footer). En la app es una fuente pesada que dificulta la lectura en densidad operativa, por eso se reserva.

Un badge "completado" lleva lime pero **no** FlexyFont. Un dot pulse "en vivo" lleva lime pero **no** FlexyFont. Un block-lime hero puede usar FlexyFont en el número héroe (`KpiHero` stats con `font-brand text-4xl tabular-nums`) — ese es el único cruce válido.

---

## 4. Tipografía

### Familias

- **`Instrument Sans`** — fuente sans principal para TODA la UI (body + headings). Cargada vía `app.css`.
- **`FlexyFont`** — brand font. Reservada para **momentos de marca**:
  - Wordmark del logo (`AppLogo` → `font-brand` en el span).
  - Títulos de pantallas de auth (`/login`, `/register`, `/enrollment/*`).
  - Headers, totales y footer de PDFs generados (`workforce-report.blade.php`, cierre de caja, factura).
  - Hero de `welcome.tsx`.

  **Nunca** usar `FlexyFont` para body, labels de form, items de tabla, navegación del sidebar.

**Nota vs website (v2.1):** la guía de marketing usa **3 familias** — `font-primary` (sans body), `font-secondary` (display para todos los headings) y `font-brand` (slogan + stats numéricos). La app las consolida en **2** (Instrument Sans para body + headings, FlexyFont para brand) y **expande** los usos de `font-brand` a auth + welcome + PDFs.

Razones de la divergencia:

1. **Densidad operativa** — en panel denso, h2/h3 cercanos al body en tamaño se leen mejor con la misma familia; el contraste de jerarquía lo lleva el `font-weight`, no la familia.
2. **Carga de fuentes en PWA/mobile** — un `@font-face` menos mejora LCP en sesiones offline-first.
3. **Escasez de momentos de marca en flujo de app** — el marketing tiene slogans recurrentes en cada landing; la app solo tiene auth + welcome + reportes generados. `font-brand` se concentra ahí en lugar de diluirse en H2s de cada listing. El criterio de escasez se mantiene — `font-brand` sigue siendo evento puntual, no body ni heading genérico.

### Escala para app operativa

A diferencia del marketing (que usa `text-display-2` ~95px), la app prioriza densidad legible. Pero **no es chata**: las páginas con peso editorial (dashboard inicial, welcome, onboarding, reportes) suben de tamaño para emular el feel del landing — sin saltar a 95px, sin cambiar de fuente. La consistencia tipográfica vive en dos modos:

**Modo denso (default)** — listings, formularios, tablas, cards de dato:

| Token Tailwind | Tamaño | Uso |
|---|---|---|
| `text-2xl md:text-3xl` | 24/30px | H1 de página (default) |
| `text-xl md:text-2xl` | 20/24px | H1 secundario, título de modal grande |
| `text-lg md:text-xl` | 18/20px | H2 de sección, título de card destacada |
| `text-base md:text-lg` | 16/18px | H3, título de panel |
| `text-base` | 16px | Body (default) |
| `text-sm` | 14px | Body denso (tablas, sidebar nav, labels de form) |
| `text-xs` | 12px | Metadata, helper text, footer de card |
| `text-[10px]` o `text-[11px]` | 10–11px | Chips uppercase, badges con tracking, KPIs secundarios |

**Modo editorial** — welcome, dashboard hero, KPI hero, pantallas de logro, empty states con personalidad:

| Token Tailwind | Tamaño | Uso |
|---|---|---|
| `text-4xl md:text-5xl lg:text-6xl` | 36/48/60px | Hero de welcome, H1 de pantalla de logro |
| `text-3xl md:text-4xl lg:text-5xl` | 30/36/48px | H1 editorial de dashboard / KPI hero |
| `text-2xl md:text-3xl` | 24/30px | H2 editorial de sección hero |

Tracking en modo editorial: `tracking-[-0.02em]`. Leading: `leading-[1.05]` para H1, `leading-[1.1]` para H2. Sin cambio de familia — sigue siendo `Instrument Sans`. El "feel display" lo da el tamaño + tracking negativo + `font-semibold`, no otra @font-face.

### Reglas

- **H1 de página (denso)** — listings, forms, tablas, settings: `text-2xl md:text-3xl font-semibold text-foreground tracking-tight`. Sin `font-brand`.
- **H1 de página (editorial)** — dashboard, reportes, KPI hero, pantallas de logro: `text-3xl md:text-4xl lg:text-5xl font-semibold text-foreground tracking-[-0.02em] leading-[1.05]`. Sin `font-brand`. Alineado a la izquierda.
- **Hero de auth/welcome** — momento de marca puro: `font-brand text-4xl md:text-5xl lg:text-6xl font-medium leading-[1.05] tracking-[-0.02em]`. Aquí sí va `FlexyFont`.
- **Eyebrow / pill encima del H1** — opcional pero recomendado en páginas editoriales: chip uppercase `tracking-[0.18em]` con `bg-accent text-accent-foreground` (modo "logro") o `bg-secondary text-secondary-foreground` (modo neutro). Patrón idéntico al "nuestros servicios" del landing.
- **Body**: `text-base` con `leading-relaxed` (1.625). Para descripciones extensas, `text-sm text-muted-foreground`. En hero editorial el sub-hero usa `text-lg md:text-xl text-muted-foreground max-w-2xl`.
- **Labels de form**: `text-sm font-medium`. Siempre asociar al input vía `<Label htmlFor>`.
- **Chips uppercase**: `text-[11px] uppercase tracking-[0.15em] font-semibold` (default) o `tracking-[0.18em]` (pill editorial). Usar para etiquetas de sección (`COLABORADORES`, `CAJA`, `REPORTES`).
- **Números financieros**: `tabular-nums` siempre — alinea decimales en tablas y totales.
- **Números KPI grandes (editorial)**: `font-brand` permitido como excepción cuando el número es el "héroe" del bloque (ej. `font-brand text-4xl md:text-6xl tabular-nums` en KpiHero / stats lime). Para KPIs estándar dentro de cards densas, sigue `tabular-nums` con `Instrument Sans` `font-semibold`.
- **Headings nunca centrados** en páginas operativas. Centrado se reserva para modales pequeños, empty states y CTAFinal-style.

---

## 5. Layout de app

### Shell global

La app se monta con `AppShell` → `AppSidebar` + `AppContent`. No es un landing de bloques full-width.

```
┌─────────────┬────────────────────────────────────┐
│             │  AppHeader (breadcrumbs + actions) │
│ AppSidebar  ├────────────────────────────────────┤
│   - nav     │                                    │
│   - branch  │       Page content                 │
│   - user    │       (max-w-7xl por defecto)     │
│             │                                    │
└─────────────┴────────────────────────────────────┘
```

- **Sidebar**: `Sidebar` de shadcn (`components/ui/sidebar.tsx`). Background `--sidebar-background` (`#F6F5F3` light / `#1E232E` dark). Colapsable en desktop, drawer en mobile.
- **Container de página**: `max-w-7xl mx-auto px-4 md:px-6`. Para reportes y tablas anchas, `max-w-screen-2xl`.

### Spacing dual (denso vs editorial)

- **Denso** — listings, forms, tablas, modales operativos: `space-y-6 md:space-y-8` entre secciones, header `mb-6`, secciones `py-8 md:py-12`.
- **Editorial** — dashboard inicial, welcome, onboarding, reportes con hero, pantallas de logro: `space-y-10 md:space-y-16` entre secciones, header `mb-8 md:mb-12`, secciones `py-12 md:py-20`. El bloque CTA final del lime puede llegar a `py-20 md:py-28`.

La regla: la primera página después del login y los flujos de impacto respiran como editorial; el día a día (caja, órdenes, inventario) es denso.

### Radius dual (editorial vs denso)

| Elemento | Radius | Token |
|---|---|---|
| Card de **dashboard / KPI hero / empty editorial / hero block** | 16px | `rounded-2xl` |
| Card de **listing wrapper / fieldset / panel denso / table-wrapper** | 8px | `rounded-lg` |
| Card de **fila de tabla / item de lista** (interno) | 6px | `rounded-md` |
| Botones / inputs / select | 6px | `rounded-md` |
| Pills, avatares, badges status | `rounded-full` | — |
| Modal Dialog / Sheet | 12px | `rounded-xl` |
| Bloque `block-lime` editorial (CTA final, welcome hero, KpiHero) | 24px | `rounded-3xl` o `rounded-[1.5rem]` |

**Por qué dual:** el website usa `rounded-2xl` (16px) en todo card porque vive sobre whitespace generoso. La app tiene contexto mixto: una card de KPI sobre dashboard inicial respira como landing y merece `rounded-2xl`; una card que envuelve una tabla de 200 filas se siente inflada con 16px, ahí va `rounded-lg`. La decisión se toma según el contexto, no por defecto.

### Breakpoints

Tailwind defaults: `sm` 640, `md` 768, `lg` 1024, `xl` 1280, `2xl` 1536. La app es mobile-first — toda página debe verse usable en `375px` (iPhone SE).

### Touch targets

Mínimo `44×44px` para cualquier elemento interactivo en mobile. El `Button` ya enforce esto vía `min-h-[44px] min-w-[44px]` en todas las variants — no sobreescribirlo.

---

## 6. Bloques aplicados a la app

Mapeo de los bloques-sección del marketing v2.1 a contextos de app:

### 6.1 Página por defecto (≈ `block-light`)

Fondo `--background` (`#F0F0F0` light / `#232733` dark). Contenedor con cards blancas (`bg-card`). Es el patrón estándar de listings, dashboards, formularios.

```tsx
<div className="space-y-6">
  <PageHeader title="Colaboradores" />
  <Card className="bg-card">
    {/* contenido */}
  </Card>
</div>
```

### 6.2 Hero / panel de logro (≈ `block-lime`)

Para `welcome.tsx`, `/enrollment/completado`, pantallas de éxito, KPI hero del dashboard. Fondo `bg-accent` (lime), texto `text-accent-foreground` (oscuro). **Un solo block-lime grande por vista** (regla de jerarquía, §3). Los elementos lime chicos de la misma página — badges "completado", eyebrows, dot pulse, etiquetas de categoría — **no cuentan contra ese límite**; sí cuentan contra el presupuesto de 3 elementos lime visibles por viewport (§3 anti-saturación 1).

Patrón base:

```tsx
<section className="bg-accent text-accent-foreground rounded-3xl p-8 md:p-12 lg:p-16">
  <span className="inline-flex items-center rounded-full bg-foreground text-background px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em]">
    Bienvenido
  </span>
  <h1 className="mt-6 font-brand text-4xl md:text-5xl lg:text-6xl font-medium leading-[1.05] tracking-[-0.02em]">
    Listo, tu empresa está creada
  </h1>
  <p className="mt-4 text-base md:text-lg max-w-2xl">
    Ya puedes invitar a tu equipo y empezar a vender.
  </p>
  <Button variant="dark" className="mt-8" data-cta="ir-al-dashboard">
    Ir al panel
  </Button>
</section>
```

### 6.2b Hero con stats (≈ hero del landing v2.1)

Reproduce el patrón hero del marketing — grid 8/4 con H1 grande a la izquierda y bloque lime con 3 stats a la derecha. Disponible como componente `KpiHero` (ver §7). Casos: `welcome.tsx` post-onboarding, dashboard inicial, summary de período.

```tsx
<section className="grid grid-cols-1 gap-8 md:grid-cols-12 md:gap-12">
  <div className="md:col-span-7 lg:col-span-8">
    <span className="inline-flex items-center rounded-full bg-secondary text-secondary-foreground px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em]">
      Resumen del día
    </span>
    <h1 className="mt-6 text-3xl md:text-4xl lg:text-5xl font-semibold tracking-[-0.02em] leading-[1.05]">
      Ventas hoy
    </h1>
    <p className="mt-4 text-base md:text-lg text-muted-foreground max-w-2xl">
      Resumen de operación del período seleccionado.
    </p>
  </div>
  <aside className="md:col-span-5 lg:col-span-4 bg-accent text-accent-foreground rounded-3xl p-6 md:p-8 flex flex-col gap-6 justify-center">
    <span className="inline-flex w-fit items-center rounded-full bg-foreground text-background px-3 py-1 text-[10px] font-semibold uppercase tracking-[0.2em]">
      En vivo
    </span>
    <dl className="grid grid-cols-3 gap-4">
      <div>
        <dt className="text-[11px] uppercase tracking-[0.15em] opacity-70">Órdenes</dt>
        <dd className="mt-1 font-brand text-3xl md:text-4xl tabular-nums">42</dd>
      </div>
      <div>
        <dt className="text-[11px] uppercase tracking-[0.15em] opacity-70">Ventas</dt>
        <dd className="mt-1 font-brand text-3xl md:text-4xl tabular-nums">$1.2M</dd>
      </div>
      <div>
        <dt className="text-[11px] uppercase tracking-[0.15em] opacity-70">Ticket</dt>
        <dd className="mt-1 font-brand text-3xl md:text-4xl tabular-nums">$28K</dd>
      </div>
    </dl>
  </aside>
</section>
```

Reglas para este patrón:

- Una sola instancia del **hero KpiHero** por página — el aside lime grande no se repite (regla de jerarquía: un solo block-lime grande por vista).
- En mobile el lime cae debajo del H1, no se rompe el grid.
- Stats numéricos aceptan `font-brand` como excepción (ver §4 — número como héroe). Es el único cruce válido entre lime y FlexyFont (§3).
- El **block grande** lime mantiene el rol "logro/en vivo/aspiracional" — si los KPIs son negativos (caja con falta, ventas bajas), el block no usa lime; cambiar a `bg-secondary` o usar status semáforo.
- **Badges de éxito chicos en la misma página coexisten sin problema** — un badge "Completado" en una fila de tabla más abajo, un eyebrow lime de otra sección, un dot pulse "En vivo" en el header de polling: todos suman dentro del presupuesto anti-saturación pero no compiten con el hero.

### 6.3 Surface de peso (≈ `block-dark`)

Sidebar en dark mode, footer del PWA shell, modales de confirmación destructiva. Fondo `bg-foreground` (token semántico oscuro en light mode) o `bg-[color:var(--color-body-dark)]` (hex literal del rediseño, `#232733`). Texto `text-background` para invertir.

**No usar `bg-secondary` para esto** — en la app shadcn ese token es gris claro (`#E5E5E5`), no oscuro. Ver §3 para el remap de `secondary` respecto al website.

### 6.4 Grid de cards (≈ `block-grid`)

Patrón para dashboard de capacidades, atajos, lista de métricas. Idéntico al marketing — `gap-px` + `bg-border` + `overflow-hidden rounded-2xl` da separadores 1px sin bordes redundantes (radius editorial, sin perder densidad interna):

```tsx
<div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-px bg-border overflow-hidden rounded-2xl">
  <article className="bg-card p-6 md:p-8 space-y-2 transition hover:bg-muted/40">
    <p className="text-[11px] tabular-nums text-primary tracking-[0.15em]">01</p>
    <h3 className="text-lg font-semibold">Título</h3>
    <p className="text-sm text-muted-foreground">Descripción</p>
  </article>
  {/* …más cards */}
</div>
```

Para grids con espacio (no separadores 1px) usar `gap-4 md:gap-6` y `bg-transparent`. Cuando son KPIs operativos densos (dashboard de cajero), usar `rounded-lg` en lugar de `rounded-2xl` — la decisión la manda el contexto, no el patrón.

---

## 7. Inventario de componentes shadcn

Lista de los componentes que ya viven en `resources/js/components/ui/`. **Antes de crear uno nuevo, revisar si existe.**

| Componente | Archivo | Cuándo usar |
|---|---|---|
| `Button` | `button.tsx` | Cualquier acción. Variants en §8. |
| `Card` | `card.tsx` | Superficie de contenido sobre `--background`. Body de listings, formularios, panels. |
| `Dialog` | `dialog.tsx` | Modales centrados desktop (confirmaciones, edición rápida). |
| `Sheet` | `sheet.tsx` | Drawer lateral (filtros avanzados, detalle de orden). |
| `BottomSheet` | `bottom-sheet.tsx` | Equivalente mobile-first de Dialog. Usar en flujos POS. |
| `BottomSheetDialog` | `bottom-sheet-dialog.tsx` | Responsive: Dialog en desktop, BottomSheet en mobile. **Preferir este sobre Dialog directo** para modales nuevos. |
| `Table` | `table.tsx` | Listings con paginación. Ver §10. |
| `Input` / `Select` / `Label` | `input.tsx`, `select.tsx`, `label.tsx` | Forms. Ver §11. |
| `Checkbox` / `Toggle` / `ToggleGroup` | `checkbox.tsx`, `toggle.tsx`, `toggle-group.tsx` | Estados booleanos / selección múltiple visual. |
| `Tabs` | `tabs.tsx` | Cuando una página tiene 2–4 vistas equivalentes (ej. perfil vs agenda). |
| `Toast` | `toast.tsx` | Feedback efímero post-acción. Ver §13. |
| `Alert` | `alert.tsx` | Mensajes persistentes en la página (modo offline, drift de stock). |
| `Badge` | `badge.tsx` | Estado de fila/objeto. Falta semáforo — ver §9. |
| `Avatar` | `avatar.tsx` | Foto/inicial de user/employee. |
| `Tooltip` | `tooltip.tsx` | Explicación corta de iconos, atajos. |
| `FieldHint` | `field-hint.tsx` | Ícono (i) + tooltip junto al `<Label>` de un campo ambiguo. Autocontenido y accesible. No reimplementar con `Tooltip`+`Info` a mano. Ver §11. |
| `DropdownMenu` | `dropdown-menu.tsx` | Acciones secundarias (kebab menu en filas de tabla). |
| `Skeleton` | `skeleton.tsx` | Loading state con shape específico. |
| `Separator` | `separator.tsx` | Divisor 1px. Equivale a `<hr>` con tokens. |
| `Breadcrumb` | `breadcrumb.tsx` | Primitive shadcn de bajo nivel para breadcrumb manual. Casi nunca se usa directamente — preferir `AutoBreadcrumb`. |
| `AutoBreadcrumb` | `auto-breadcrumb.tsx` | Breadcrumb autogenerado a partir de la URL actual y el mapeo central en `lib/breadcrumb-routes.ts`. Cero config por página: lo renderiza `PageShell` por default. Para detail pages con segmento `:id`, el último crumb usa el `title` del PageShell. Si una página no debe mostrar breadcrumb (full-bleed, kiosk), pasar `showBreadcrumb={false}` al PageShell. La jerarquía está **homologada con el sidebar**: si cambiás un parent en `app-sidebar.tsx`, actualizá `breadcrumb-routes.ts` en el mismo PR. |
| `NavigationMenu` | `navigation-menu.tsx` | Submenús del header en escritorio. |
| `Collapsible` | `collapsible.tsx` | Expandir grupos en sidebar (`SidebarGroup`). |
| `Markdown` | `markdown.tsx` | Renderizar contenido legal, política, ayuda. |
| `PlaceholderPattern` | `placeholder-pattern.tsx` | Empty states ilustrados sin imagen real. |
| `LivePollingToggle` | `live-polling-toggle.tsx` | UI para activar `useInertiaPolling`. |
| `ConfirmDialog` | `confirm-dialog.tsx` | Wrapper sobre Dialog con patrón "¿Seguro?" + acción destructive. Responsive: Dialog en `≥md`, Sheet inferior en mobile. |
| `DataCardList` / `DataCard` | `data-card-list.tsx` | Alternativa mobile-first a `<Table>` para listings densos. La página renderiza ambos y los alterna con `sm:hidden` / `hidden sm:table`. Ver §10 "Variante mobile". |
| `DesktopOnlyHint` | `desktop-only-hint.tsx` | Alert `sm:hidden` para pantallas que se entienden mejor en tablet/desktop (KDS, planner). No bloquea; solo avisa. |
| `StickyActionBar` | `sticky-action-bar.tsx` | Barra fija inferior con safe-area iOS (`pb-safe-1`). Reemplaza el patrón duplicado `fixed inset-x-0 bottom-0` de floating CTAs (carrito QR mesa, checkout móvil). |
| `QtyStepper` | `qty-stepper.tsx` | Stepper numérico − valor + con touch targets 44×44 vía `Button size="icon"`. Reemplaza dos botones inline con `−`/`+`. Prop `compact` para contextos desktop (KDS, caja). |
| `MenuItemRowSkeleton` | `menu-item-row.tsx` | Skeleton co-ubicado con `MenuItemRow`. Replica thumb + 2 líneas + precio + acción. Usar dentro de listas mientras el catálogo público carga (`/menus/:nit`, `/t/:qr/menu`). |
| `OrderItemCardSkeleton` | `order-item-card.tsx` | Skeleton co-ubicado con `OrderItemCard`. Pensado para carrito QR mesa, vista del mesero y KDS mientras el primer state llega. |
| `SettingsFormSkeleton` | `settings-form-skeleton.tsx` | Skeleton de un form de cuenta (`/settings/*`). Cabecera + N pares label/input + botón submit. Prop `withDestructiveBlock` para el bloque "Eliminar cuenta" de profile. |
| `InvoiceStatusBadge` | `invoice-status-badge.tsx` | Pildora de estado de factura (`pending`/`paid`/`overdue`/`voided`) con tokens `--color-status-*`. Reemplaza `bg-yellow-100`/`bg-green-100`/etc hardcoded. Prop `compact` para card-stack. |
| `BillingSubscriptionSkeleton` / `BillingInvoicesSkeleton` | `billing-skeleton.tsx` | Skeletons fieles de la página `/billing`: subscription card (4 stats) e invoices con doble variante (cards en mobile, tabla 8 columnas en desktop). |
| `ListCardSkeleton` | `list-card-skeleton.tsx` | Skeleton reutilizable para listados administrativos de empresa (`branches`, `printers`, `tables`, `warehouses`). Variantes `row`/`card`, prop `responsive` para mobile-card + desktop-row, `gridClassName` para grids. |
| `WhatsappStatusPill` | `whatsapp-status-pill.tsx` | Pildora `Conectado` / `Sin conectar` con tokens DS (`--color-status-safe`, `muted`) para `/company/whatsapp`. Reemplaza al combo hardcoded `bg-emerald-50` / `bg-gray-50` que rompía dark mode. |
| `WhatsappPageSkeleton` | `whatsapp-page-skeleton.tsx` | Skeleton fiel de `/company/whatsapp` (header + alert + cards de conexión + preferencias). Prop `connected` para variar entre "ya conectado" y "elegir provisión". |
| `ReportsTableSkeleton` | `reports-table-skeleton.tsx` | Skeleton de la tabla de pedidos en `/company/reports`. Cards en mobile (qty + 4 fields), filas en desktop (6 cells). |
| `EmployeesTableSkeleton` | `employees-table-skeleton.tsx` | Skeleton fiel del listado `/employees`: cards mobile (4 fields + footer) + tabla 6 cols desktop. |
| `EmployeeDetailSkeleton` | `employee-detail-skeleton.tsx` | Skeleton del detalle de colaborador (`/employees/{id}`, `/me`, `/me/perfil`): pill de estado + panel salario + grid 6 detail rows. |
| `WeekAgendaSkeleton` | `week-agenda-skeleton.tsx` | Skeleton de `/me/agenda`: 7 day-cards con header + 1–2 slots de turno cada una. Apila vertical en mobile, grid de 7 cols en `md+`. |
| `MetricsSkeleton` | `metrics-skeleton.tsx` | Skeleton fiel de `/company/metrics` (#269): header + grid de 4 KPIs + 2 filas de paneles + paneles full-width. |
| `RouteSkeleton` | `route-skeleton.tsx` (en `components/`) | Fallback del `<Suspense>` del shell consciente de la ruta (#269). Mapea `pathname → *-skeleton.tsx` y cae a `PageShellSkeleton` genérico. Montado en `spa-app-layout.tsx`. Ver §13. |
| `RouteProgress` | `route-progress.tsx` | Barra fina superior de progreso de navegación (#269). `useNavigation` + `useIsFetching` (cargas iniciales). Montada una vez en `spa-app-layout.tsx`. Ver §13. |

### Componentes de design system (en `ui/`)

| Componente | Cuándo usar |
|---|---|
| `PageHeader` | Cabecera de página. Soporta `editorial` mode (hero) y `eyebrow` (pill encima del H1). Ver §6.2b. |
| `KpiHero` | Bloque hero con stats lime al lado del H1 — patrón hero del marketing. Ver §6.2b. |
| `EditorialEmpty` | Empty state con peso editorial (PlaceholderPattern grande + H2 + CTA). Alternativa al empty denso. Ver §10. |
| `DashboardPanel` | Wrapper estándar de Card para paneles del dashboard. Header con `icon + title + rightSlot`, `rounded-2xl shadow-sm` por defecto. Reemplaza el boilerplate `<Card><CardHeader><CardTitle><Icon/>...</CardTitle></CardHeader>`. |
| `StatTile` | Mini-card 3-up dentro de paneles del dashboard (Total / Convertidos / Abandonados, En progreso / Completadas / Tiempo prom.). Valor grande arriba, label abajo, prop `tone` (`default`/`safe`/`warning`/`critical`/`primary`/`accent`). |
| `KpiCell` | Tarjeta compacta de KPI estilo "label arriba / valor abajo" para grids 2-4 columnas en drawers y detalles. Diferente a `StatTile` (que es value-arriba, centered). |
| `DetailRow` | Par label/value vertical para drawers, detalles, sheets. |
| `FilterBar` | Barra de filtros sobre listings (search + selects + actions). |
| `PeriodNavigator` | Grupo prev / hoy / next + label del rango. Reutilizable en planificador, reportes, vistas con navegación temporal. |
| `MonthCalendarGrid` | Grid mes 7×N con render prop por celda. Today highlight, días fuera del mes con opacidad, click opcional. Para planner mensual, reports calendario, asistencia. |
| `SelectableTile` | Tile clickable rounded-xl con focus ring del DS, hover border-primary/50, disabled + tooltip opcional, spinner inferior. Para selectores de empresa, sede, plantilla onboarding. Children libres. |
| `WizardStepIndicator` | Indicador de pasos para wizards (enrollment, onboarding). Numérico o con labels, conector entre círculos, check al completar. |
| `FileDropzone` | Uploader drag-drop con preview chip (imagen o icono genérico) + remove. No valida tipo/tamaño — la página decide. Para forms con upload de docs/imágenes. |
| `Combobox` | Selector con buscador para catálogos largos (proveedores, insumos, clientes…). Single o multi-select, navegación por teclado, filtrado local o servidor (debounced), paginación por scroll, clearable, free-text create y virtualización automática. Despliega en flujo normal (no overlay) → seguro dentro de modales y en mobile. Ver §11. |

**Cuándo usar `DashboardPanel` vs `Card` directo:**

- Panel del **dashboard / reportes / métricas** con un título identificable y posible badge/timestamp a la derecha → `DashboardPanel`.
- Card de **listing wrapper** (rodea una tabla), **form fieldset**, **dato sin título** → `Card` directo.

**Cuándo usar `StatTile` vs `KpiCard` vs `KpiHero`:**

- 3-4 stats dentro de un panel (Convertidos / Abandonados / Total) → `StatTile`.
- KPI principal de página, con icono + cambio porcentual + delta vs período anterior → `KpiCard` (en `metrics/`).
- Hero de página con 1-3 stats grandes sobre lime → `KpiHero`.

### Componentes de dominio (no en `ui/`)

Estos viven en `resources/js/components/` raíz y combinan shadcn con lógica de negocio. Reutilizar antes de duplicar:

`AppSidebar`, `AppHeader`, `AppContent`, `BranchSwitcher`, `RoleBadge`, `LoyaltyBadge`, `RestaurantIdentity`, `UsersTable`, `InviteUserModal`, `PermissionsMatrix`, `UserPermissionsEditor`, `EmployeeForm`, `ShortcutsHelpModal`, `PlannerViewTabs` (segmented Semana/Mes para `/planificador`).

### Banners globales (montados en `app-layout.tsx`)

Banners persistentes que aparecen arriba del contenido en TODAS las páginas autenticadas. Filtran internamente — no se montan vacíos.

| Componente | Cuándo aparece | Variant DS | Notas |
|---|---|---|---|
| `components/billing/PastDueBanner` | `activeCompany.status === 'past_due'` | `Alert variant="warning"` | Countdown desde el día 1 hasta `expected_block_at` (TZ Bogotá). CTA outline a `/billing`. **No usar colores hardcoded** — tokens de status warning. |
| `components/billing/SuspendedBanner` | `activeCompany.status === 'suspended'` | `Alert variant="critical"` | Días vencido desde `payment_blocked_at` + monto adeudado (fetch a `/api/v1/billing/subscription`, skeleton inline) + CTA primario. Tokens `--color-status-critical`. |
| `components/branches/missing-branch-banner` | `!activeBranch` (3 sub-estados) | `Alert variant="warning"` | (1) `branches.length === 0` + puede crear → CTA `Crear primera sede` a `/company/branches`. (2) `branches.length === 0` sin permiso → mensaje "contactar admin" sin CTA. (3) Hay sedes + sin sede activa → CTA al selector. Centraliza la UX que antes duplicaban los banners operativos al detectar `NO_ACTIVE_BRANCH`. |
| `components/orders/pending-approvals-banner` | Hay items aprobables en la sede activa (mesa con QR #191). | `Alert variant="warning"` | Filtra por sede activa. |
| `components/orders/pending-cancellations-banner` | Hay cancelaciones pendientes en la sede activa. | `Alert variant="warning"` | Filtra por sede activa. |
| `components/cash-register/cash-register-alert-banner` | Caja cerrada + menú activo + horario hábil. | `Alert variant="warning"` | Montado en `app-sidebar-layout`, no en `app-layout`. |

**Regla general para banners globales**: deben renderizar `null` cuando la condición no aplique, no un wrapper vacío. Esto permite el `[&:not(:has(*))]:hidden` del contenedor en `app-layout.tsx` y evita el "salto" del layout cuando el banner desaparece.

`SuspendedBanner` y `PastDueBanner` son **mutuamente excluyentes** — un mismo `activeCompany.status` no puede disparar los dos a la vez. Si en el futuro se necesita un banner que ataque ambos estados a la vez, refactorizar a un solo componente con `variant` interno (igual que hace `OverdueBanner` dentro de `/billing/index.tsx`).

---

## 8. Botones

`Button` ya implementa las variants. Variants disponibles en `button.tsx`:

| Variant | Uso recomendado | Equivalente marketing v2.1 |
|---|---|---|
| `default` / `primary` | CTA principal de la página (`Guardar`, `Crear`, `Abrir caja`). | `.btn-primary` |
| `secondary` | Acción secundaria neutra (`Cancelar`, `Volver`). | `.btn-ghost` |
| `outline` | Botón con borde sutil, fondo transparente. Acciones secundarias densas (filtros, acciones de fila). | `.btn-ghost` |
| `ghost` | Sin borde, sin fondo. Para botones de icono y acciones terciarias. | `.btn-ghost` light |
| `link` | Underline, color primary. Para "volver" en breadcrumbs, links inline. | — |
| `destructive` | Eliminar, cancelar orden, archivar empleado. Siempre con `ConfirmDialog`. | — |
| `accent` | **CTAs de naturaleza positiva.** Lime para `Publicar turnos`, `Cerrar día sin diferencias`, `Completar onboarding`, `Confirmar`, `Marcar listo`. Pueden coexistir varios si todos son positivos. No usar en `Cancelar`, `Volver`, filtros ni acciones neutras. | `.btn-accent` (lime) |
| `dark` | CTA único sobre `block-lime` (`bg-accent`). Negro alto contraste sobre lime, mismo rol que el `btn-dark` del landing. | `.btn-dark` |

### Reglas

- **Un solo CTA primario visible** por pantalla. Si hay dos acciones equivalentes, una es `default`, la otra `outline`.
- **Acciones destructivas siempre confirmadas** con `ConfirmDialog`. Nunca un `destructive` que dispare al primer click.
- **Loading state**: usar `disabled` + spinner inline. El label cambia a "Guardando…" temporalmente; no eliminar el ícono.
- **Tamaños**: `default` (44px) para la mayoría. `sm` para filas de tabla densas (sigue siendo 44px min-h por touch). `lg` para hero CTAs. `icon` para botones cuadrados con solo icono — siempre acompañar con `Tooltip` para accesibilidad.
- **No mezclar variants** en una misma toolbar. Si la toolbar tiene 4 acciones, todas `outline` o todas `ghost`, no una de cada.
- **Uppercase**: el `Button` no force uppercase. La v2.1 lo hacía opcional vía `.btn-uppercase`; en la app, uppercase queda reservado para **chips de etiqueta de sección** (`text-[11px] uppercase tracking-[0.15em]` — ver §4), no para botones.

### `accent` — coherencia con website (verificado mayo 2026)

Mismo color (`#C0FD79`), **misma filosofía**. Una inspección visual del website `flexyflow.co` realizada en mayo 2026 confirma que app y website convergen en el uso del lime:

**Patrón observado en el website 2026** (home `flexyflow.co/`):

- 1 `aside.bg-accent` grande con stats hero (`24/7`, `3×`, `−60%`) — equivalente al `KpiHero` de la app.
- 1 `section.cta-section bg-accent text-accent-foreground py-28 md:py-40` como CTA final — equivalente al block-lime hero/CTA de la app.
- Eyebrow pills `bg-accent text-accent-foreground` recurrentes (`NUESTROS SERVICIOS`, `CONTACTO`).
- Dot pulse decorativo `bg-accent animate-pulse` dentro del pill del hero.
- Etiquetas de sección `text-accent` (lime) sobre `bg-foreground` (dark) en el footer (`EXPLORAR`, `EMPRESA`).
- Numeración ornamental de servicios (`01`–`06`) **NO** usa lime — va en `text-primary` (azul). El rol "ornamentación numérica" pertenece al azul.

**Conclusión**: ambos contextos usan lime para acentos positivos + decoración editorial, respetando jerarquía (un block grande + chicos sin límite numérico). La regla previa de "escasez total" / "único por pantalla" del v3.0 quedó obsoleta — el website nunca operó así.

**Diferencia contextual única** (no es regla, es consecuencia):

- **Website** — landing puro, whitespace generoso → ratio lime/no-lime más alto naturalmente.
- **App** — operativa, densidad alta (POS, tablas, formularios) → ratio lime/no-lime menor naturalmente.

No es una regla diferente: las reglas anti-saturación de §3 (presupuesto por viewport, regla 30% en tablas, eyebrow único por página) producen ese ratio menor en la app sin tener que enunciar una política aparte.

### `creative-hover-anim` autorizado en hero CTAs

El efecto firma del marketing (`creative-hover-anim` + `effect-color-*`) **puede usarse** en CTAs primarios de:

- `welcome.tsx` y pantallas de logro post-onboarding.
- KPI hero del dashboard inicial (CTA "Ver reportes" / "Cerrar día").
- Botón único sobre `block-lime` cuando el flujo es de "celebración" (cierre conciliado, onboarding completo).

**Prohibido** fuera de ahí — saturaría el panel operativo. Si una página de listing o form usa el efecto, se rechaza el PR.

### Atajos de teclado

**Navegación global — patrón "go to" con tecla líder `G`** (no `Alt+<letra>`). Se pulsa `G` y luego la tecla del destino (ej. `G` → `D` = Dashboard). Sin modificadores e inactivos en inputs, así que **no se cruzan** con atajos del navegador, de Windows/macOS ni de otros programas (`Alt+<letra>` en cambio inserta caracteres especiales en macOS y activa mnemónicos de menú en Firefox/Windows). Mapa canónico en `lib/shortcuts.ts` (`APP_SHORTCUTS`); motor de secuencias en `components/global-shortcuts.tsx` (montado en `spa-app-layout.tsx`). Los acordes con modificador que queden se validan contra `RESERVED_SHORTCUTS` (`hooks/use-keyboard-shortcut.ts`).

Atajos transversales: `?` abre `ShortcutsHelpModal`; `Ctrl/Cmd + .` alterna la barra lateral.

Atajos de acción dentro de una pantalla (POS, caja): indicar con `<kbd>` dentro del Button:

```tsx
<Button variant="default">
  Cobrar
  <kbd className="ml-2 text-[10px] opacity-60">Ctrl+Enter</kbd>
</Button>
```

Listar atajos globales en `ShortcutsHelpModal` (atajo `?`).

---

## 9. Badges, pills, status

### Badge (shadcn) — variants actuales

El `Badge` actual solo tiene `default`, `secondary`, `destructive`, `outline`. **Brecha conocida**: faltan variants para semáforo y accent. Hasta que se extiendan, usar `className` ad-hoc con tokens — no hex literal.

```tsx
{/* Estado seguro */}
<Badge className="bg-[color:var(--color-status-safe)]/15 text-[color:var(--color-status-safe)] border-transparent">
  Activo
</Badge>
```

**TODO sugerido (issue separado):** extender `badgeVariants` con `safe`, `warning`, `critical`, `accent` para no repetir las clases ad-hoc.

### Pills editorial

Misma fórmula que en marketing — pill uppercase sobre fondo claro para etiquetar secciones:

```tsx
<span className="inline-flex items-center rounded-full bg-accent text-accent-foreground px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em]">
  Colaboradores
</span>
```

Variante sobre lime (chip dark): `bg-foreground text-background` con el mismo shape.

### Badges de dominio

- **`RoleBadge`** (`role-badge.tsx`) — muestra rol con color custom de `company_roles.color`. Para roles del sistema usa colores fijos: Propietario azul, Administrador morado, Empleado neutro.
- **`LoyaltyBadge`** (`loyalty-badge.tsx`) — tier de cliente fiel. Usa lime para "VIP".
- **`GuestBadge`** (`components/ui/guest-badge.tsx`) — identidad de un comensal dentro del flujo de mesa con QR (#191). Avatar circular con iniciales y paleta determinista a partir del `displayName` (tokens DS, no hex). Tamaños `sm | md | lg`, opcional `phoneMasked` y status dot `active | awaiting | paid`. Usado en pantalla del comensal (header), detalle del mesero, ticket KDS y desglose de caja por persona. **Siempre tipar el nombre como input del cliente (snapshot inmutable, puede diferir del `contacts.name` canónico).**
- **`OrderItemCard`** (`components/ui/order-item-card.tsx`) — card de un `order_item` (#191). Render unificado entre cuatro contextos (cliente, mesero, KDS, caja). Muestra cantidad, nombre, notas, status badge sincronizado con `config('orders.item_statuses.labels/badges')`, subtotal y precio unitario. Acciones `onEdit` y `onCancel` opcionales — el caller decide cuándo permitir. Prop `readOnly` para vistas no-interactivas. **No replicar markup ad-hoc para items** — extender este componente con un prop nuevo si falta un caso.
- **`NotesEditor`** (`components/ui/notes-editor.tsx`) — textarea reusable con contador, max 500 chars (alineado con `order_items.notes` / `order_notes.body`) y botones de "quick actions" para agregar texto común ("sin cebolla", "sin sal"). Usado para notas individuales por item y notas grupales en mesa con QR. Backend sanitiza igual con `mb_substr(strip_tags(trim($input)), 0, 500)`; este componente refleja el límite en UI.
- **`BatchApprovalCard`** (`components/ui/batch-approval-card.tsx`) — card de una "tanda" pendiente (items con `submitted_at` cercano del mismo `guest_id`) que un mesero aprueba o rechaza desde el detalle de la sesión (#191 Fase 4). Selección múltiple por checkbox (default: todos seleccionados), preview con `OrderItemCard readOnly`, summary del subtotal seleccionado y CTAs `Aprobar tanda` / `Rechazar item`.
- **`CancellationRequestCard`** (`components/ui/cancellation-request-card.tsx`) — card de una `cancellation_request` que un comensal levantó sobre un item ya aprobado. Mostrar status (`pending|approved|denied`) con badge, motivo del cliente y botones del mesero para resolver. Aprobar marca el item como `cancelled` con `cancellation_reason=waiter_approved` en backend (auditable).
- **`KdsTicketCard`** (`components/ui/kds-ticket-card.tsx`) — ticket del KDS (#191 Fase 5). Tipografía grande para pantalla fija de cocina, mesa + comensal + plato + notas + banner de `kitchen_alert` replicadas desde `order_notes`. CTA según status: "Entró a cocina" (approved → in_kitchen), "Listo" (in_kitchen → ready), passive "Esperando entrega del mesero" (ready).
- **`TimeSinceCounter`** (`components/ui/time-since-counter.tsx`) — chip de cronómetro vivo con tono según umbrales (verde < 5 min, ámbar 5-15, rojo > 15 por default). Setea `setInterval` cada 30s. Reusable para SLA visual en KDS y futuras dashboards operativos.
- **`GuestItemList`** (`components/ui/guest-item-list.tsx`) — lista de items consumidos por un comensal con subtotal, totales pagado/pendiente y selección por checkbox para pago dividido (#191 Fase 6). Modo `readOnly` para vistas históricas (mesero/caja sin acciones). Reusable en caja, mesero y reportes futuros.
- **`SplitPaymentSheet`** (`components/ui/split-payment-sheet.tsx`) — BottomSheet para procesar un cobro parcial o total. Inputs: método (cash/card/transfer con icono), referencia obligatoria si no es cash, propina opcional (NO suma a total contable, va a `orders.tip_amount` separado). El caller genera `client_uuid` para idempotencia y maneja el POST.
- **`MenuQrPoster`** (`components/company/menu-qr-poster.tsx`) — extendido con prop `mode: 'menu' | 'table-session'` (#191 Fase 8). En modo `menu` (default) sigue codificando `/menus/{nit}?table=N` para el QR genérico del catálogo. En modo `table-session` codifica `/t/{qrToken}` para mesas con sesión grupal y pago dividido — el `qrToken` es alfanumérico de 40 chars generado por backend al crear la mesa, y resuelve sede+mesa directo sin necesidad de pasar `nit` ni `number`.

---

## 10. Tablas y data density

Listings (`/orders`, `/employees`, `/users`, `/clients`, `/menu`, `/coupons`) siguen el patrón:

```
┌─────────────────────────────────────────────┐
│  Header: filtros + búsqueda + CTA crear     │
├─────────────────────────────────────────────┤
│  Bulk actions bar (si hay selección)        │
├─────────────────────────────────────────────┤
│  Table (con paginación al pie)              │
├─────────────────────────────────────────────┤
│  Empty state si no hay filas                │
└─────────────────────────────────────────────┘
```

### Patrón canónico de tabla (v3.1)

La referencia visual y de tokens es `/coupons`. Se materializa en el componente `Table` de shadcn (`components/ui/table.tsx`), que ya incluye los defaults:

- **Wrapper**: `bg-card overflow-hidden rounded-lg border shadow-sm` (radius denso v3.1)
- **Scroll horizontal interno**: `overflow-x-auto` automático para tablas anchas en mobile
- **Thead**: `bg-muted/50 text-foreground text-xs uppercase`
- **TableHead (`<th>`)**: `px-4 py-3 text-left font-semibold`
- **TableRow body**: `hover:bg-muted/40 border-t transition-colors` (sin zebra-striping)
- **TableCell (`<td>`)**: `px-4 py-3 align-middle`
- **Números**: `tabular-nums` siempre (alinea decimales y miles)
- **IDs de orden / referencia**: `font-mono` para distinguirlos del texto fluido

**Uso recomendado**: prefiere los componentes shadcn (`Table`, `TableHeader`, `TableBody`, `TableRow`, `TableHead`, `TableCell`) sobre `<table>` HTML cruda — ya traen el patrón aplicado, dark-mode aware y consistente con el resto.

```tsx
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';

<Table>
    <TableHeader>
        <TableRow>
            <TableHead>Código</TableHead>
            <TableHead className="text-right">Total</TableHead>
            <TableHead>Estado</TableHead>
        </TableRow>
    </TableHeader>
    <TableBody>
        {items.map((item) => (
            <TableRow key={item.id}>
                <TableCell className="font-mono">{item.code}</TableCell>
                <TableCell className="text-right tabular-nums">{formatCurrency(item.total)}</TableCell>
                <TableCell><StatusBadge status={item.status} /></TableCell>
            </TableRow>
        ))}
    </TableBody>
</Table>
```

**Prop `bare`** (`<Table bare>`): omite el wrapper visual (bg-card / rounded-lg / border / shadow-sm) y solo emite la tabla con scroll horizontal. Usar cuando la tabla está dentro de un `<Card>` o panel que ya provee su propio shell — evita el "doble borde" visual.

**Tablas HTML crudas legítimas**: solo cuando necesitas un layout especial que el componente shadcn no soporta (matriz de permisos, calendario semanal del planificador, recetas editables con selects inline en cada celda). En esos casos, aplicar manualmente los tokens del patrón canónico — nunca dejar `bg-gray-*`, `text-gray-*` ni `text-emerald-*`/`text-red-*` hardcoded.

### Variante mobile — `DataCardList` (regla obligatoria)

Toda tabla con **≥6 columnas** (o cualquier listing operativo que no pase el check mobile a 375px sin overflow horizontal forzado) **debe** ofrecer una variante card-stack para `<sm`. `overflow-x-auto` es un paliativo del wrapper, **no** una solución de UX.

Patrón canónico:

```tsx
import { DataCardList, DataCard } from '@/components/ui/data-card-list';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';

{/* Mobile: cards apiladas */}
<DataCardList
    items={rows}
    getKey={(row) => row.id}
    className="sm:hidden"
    renderCard={(row) => (
        <DataCard
            title={row.name}
            subtitle={row.code}
            fields={[
                { label: 'Sede', value: row.branch_name },
                { label: 'Estado', value: <StatusBadge status={row.status} /> },
            ]}
            actions={<RowKebab row={row} />}
        />
    )}
/>

{/* Desktop: tabla densa */}
<div className="hidden sm:block">
    <Table>{/* TableHeader, TableBody, etc. */}</Table>
</div>
```

Reglas para el card-stack:

- **Campos prioritarios primero**: el `title` de la card es la identidad principal (nombre, código, periodo). Los `fields` se ordenan por importancia operativa, no por orden de columnas en la tabla.
- **Máximo 4–5 fields visibles**: si tu tabla tiene 8 columnas, las menos críticas se omiten en mobile (van detrás del kebab "Detalle" o en la página show).
- **Acciones de fila** siempre con `DropdownMenu` icon-only (kebab) en mobile, aunque en desktop sean inline. El espacio horizontal manda.
- **Sin tabla forzada en mobile**: no se acepta una `<Table>` con `overflow-x-auto` como única variante para listings nuevos.

### Checklist mobile obligatorio (375px) — antes de mergear

Cada PR que toque listings, modales o headers debe pasar esta verificación a mano:

- [ ] Sin overflow horizontal a 375px (`document.documentElement.scrollWidth === window.innerWidth`).
- [ ] Botones con `min-h-[44px] min-w-[44px]` (Button del DS ya lo enforce — no sobrescribir).
- [ ] Tablas con ≥6 columnas usan `DataCardList` en `<sm`.
- [ ] Modales con forms ≥3 campos usan `BottomSheetDialog` (Dialog desktop / BottomSheet mobile).
- [ ] Cero `min-w-[NNNpx]` ni `w-[NNNpx]` hardcoded sin override `sm:` o `md:`.
- [ ] `truncate` aplicado a texto dinámico viene acompañado de `title` o `<Tooltip>`.

### Reglas

- **Densidad**: filas `py-3` por defecto (estándar v3.1). Nunca `py-1` (rompe touch target).
- **Truncate** con `Tooltip` para celdas largas.
- **Acciones de fila**: `DropdownMenu` con icono kebab (`MoreHorizontal`) cuando hay >= 4 acciones. Hasta 3 acciones se aceptan inline (`Button variant="ghost" size="icon"`) con tonos semánticos: edit/info `text-muted-foreground`, warning `text-[color:var(--color-status-warning)]`, destructive `text-destructive`.
- **Bulk actions**: aparecen en una barra fija arriba de la tabla cuando hay selección > 0. Fondo `bg-secondary`, no flotante.
- **Paginación**: server-side con Inertia. Mostrar `Mostrando 1–25 de 312`. Default `per_page=25`, max 100. Botones `<Button variant="outline" size="sm">`.
- **Filtros**: en una `Sheet` lateral para conjuntos grandes; inline (`Select` + `Input`) para 2–3 filtros simples. Para filtros tipo segmented (Todos/Activos/Inactivos/...) usar botones rounded `bg-primary text-primary-foreground` (activo) vs `border-border bg-card text-muted-foreground hover:bg-muted` (inactivo) con `focus:ring-ring focus:ring-2`.
- **Empty state**: cuando no hay datos del todo. Empty state de "no coincidencias" es distinto (mantener filtros + sugerir limpiar).
- **Skeleton rows**: durante deferred prop de Inertia. Misma altura que las filas reales, 5–10 filas, `animate-pulse`.
- **Números siempre `tabular-nums`** para alinear decimales y miles.
- **Errores y warnings**: usar `<Alert variant="destructive">` (errores de carga) o `<Alert variant="warning">` (modo readonly, programa deshabilitado) sobre la tabla, no banners ad-hoc con `border-red-200 bg-red-50`.

### Empty states — dos modos

**Denso (default)** — para listings con filtros activos, modales vacíos, panels secundarios. Compacto, no roba foco de la tabla principal.

```tsx
<div className="flex flex-col items-center text-center py-12 px-4">
  <UsersIcon className="size-10 text-muted-foreground" />
  <h3 className="mt-4 text-base md:text-lg font-semibold">Aún no tienes colaboradores</h3>
  <p className="mt-1 text-sm text-muted-foreground max-w-md">
    Crea el primer perfil para asignarle turnos y permisos.
  </p>
  <Button variant="default" className="mt-5" asChild>
    <Link href={route('employees.create')}>Crear colaborador</Link>
  </Button>
</div>
```

**Editorial** — para páginas vacías sin filtros, primera vez del usuario en el módulo, pantallas con peso de bienvenida (`/clients` sin clientes, `/menu` sin platos, `/employees` sin empleados, dashboard sin ventas todavía). Trae el feel del landing — pattern de fondo, H2 grande, sub generoso. Disponible como `EditorialEmpty`.

```tsx
<section className="relative overflow-hidden rounded-3xl border bg-card px-6 py-16 md:px-12 md:py-24 text-center">
  <PlaceholderPattern className="absolute inset-0 size-full text-muted-foreground/15" />
  <div className="relative mx-auto max-w-2xl space-y-6">
    <span className="inline-flex items-center rounded-full bg-secondary text-secondary-foreground px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em]">
      Empezar
    </span>
    <h2 className="text-3xl md:text-4xl font-semibold tracking-[-0.02em] leading-[1.1]">
      Aún no tienes colaboradores
    </h2>
    <p className="text-base md:text-lg text-muted-foreground">
      Crea el primer perfil para asignarle turnos, permisos y verlo en el planificador.
    </p>
    <Button variant="default" size="lg" asChild>
      <Link href={route('employees.create')}>Crear el primero</Link>
    </Button>
  </div>
</section>
```

**Cuándo usar cuál:**

- Listing con filtros aplicados → denso. ("No coincide nada con `espinaca`.")
- Listing sin filtros, primera vez → editorial.
- Sub-panel vacío dentro de una página con otras secciones llenas → denso.
- Página entera vacía (módulo nuevo, restaurante recién creado) → editorial.
- Modal sin contenido → denso.

Si dudás, denso. El editorial es para momentos de "esto es el inicio de algo".

---

## 11. Formularios

### Estructura

Form largos se dividen en **fieldsets** con título uppercase. `EmployeeForm` es la referencia (5 fieldsets: identidad, contacto, sede, seguridad social, pago, jornada).

```tsx
<form onSubmit={handleSubmit} className="space-y-8">
  <fieldset className="space-y-4">
    <legend className="text-[11px] uppercase tracking-[0.15em] font-semibold text-muted-foreground">
      Identidad
    </legend>
    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div className="space-y-1.5">
        <Label htmlFor="first_name">Nombres</Label>
        <Input id="first_name" {...register('first_name')} />
        <InputError message={errors.first_name} />
      </div>
      {/* …más campos */}
    </div>
  </fieldset>

  <fieldset className="space-y-4">{/* …Contacto */}</fieldset>

  <div className="flex gap-3 justify-end">
    <Button type="button" variant="outline">Cancelar</Button>
    <Button type="submit" variant="default">Guardar</Button>
  </div>
</form>
```

### Reglas

- **Label arriba del input**, nunca placeholder como label.
- **Tooltip de ayuda en campos ambiguos**: `FieldHint` (`components/ui/field-hint.tsx`) — ícono (i) junto al `<Label>` con la explicación. No inventar el patrón inline (`Tooltip` + `Info` a mano); usar el primitive. Reservarlo para campos cuyo nombre no basta (ej. SLA de KDS, tarifa por tipo de pago, tipo de negocio de la sede).
- **Helper text** debajo del input en `text-xs text-muted-foreground`.
- **Errores inline** con `InputError` (rojo, `text-xs`) **debajo del campo que los causa** + `aria-invalid` en el control. Aparecen al `onBlur` o al submit, nunca al primer keystroke.
- **Validación de servidor (422)**: mapear el `errors` de la respuesta a estado por-campo (`{ campo: mensaje }`) y renderizarlo inline en cada campo. **Nunca** aplanar los errores de campo a un solo mensaje al pie del form (`Object.values(errors)[0]`, join, etc.). El Alert/mensaje al pie queda solo para errores NO atribuibles a un campo (red, 403, o el genérico cuando el 422 no trae `errors`). Ver §13 → Error.
- **Required**: marcar con asterisco `*` en el label, no en el placeholder. Texto consistente: `Nombres *`.
- **Campos monetarios**: usar `Input type="text" inputMode="decimal"` con máscara de miles. Nunca `type="number"` (rompe formato local).
- **Selects grandes** (`> 7 opciones`): usar `Combobox` (buscador integrado). Para <= 7 usar el `<select>` nativo / `Select`, `RadioGroup` o `ToggleGroup`.
- **Botones del form**: alineados a la derecha en desktop, columna completa en mobile (`flex-col-reverse`).
- **Autosave** está prohibido para datos financieros — siempre submit explícito.

### Combobox (selector con buscador)

Primitive `components/ui/combobox.tsx`. Úsalo en cualquier selección sobre catálogos largos (proveedores, insumos, clientes, sedes). Reemplaza al `<select>` nativo cuando hay scroll-fatigue o el usuario necesita teclear para encontrar.

**Por qué despliega inline (no flotante):** el panel se abre **en el flujo del documento**, no como overlay con portal. Así evita el clipping del `overflow` dentro de modales/drawers, el conflicto con el focus-trap de Radix Dialog, y se ve correcto en mobile. Por eso conviene ubicarlo en su **propia fila** (ancho completo), no en una celda angosta de tabla.

**Props principales:**

| Prop | Tipo | Para qué |
|---|---|---|
| `value` / `onChange` | `string` (o `string[]` si `multiple`) | Controlado. |
| `options` | `{ value, label }[]` | Catálogo. |
| `multiple` | `boolean` | Multi-select (toggle + check, panel no se cierra al elegir). |
| `clearable` | `boolean` | Muestra "X" para limpiar. |
| `onSearchChange` | `(q) => void` | **Modo servidor**: filtra el padre; el componente emite el query *debounced* (`searchDebounceMs`, default 250) y no filtra local. |
| `loading` | `boolean` | Spinner en la lista (modo async). |
| `onReachEnd` | `() => void` | Paginación: se llama al acercarse al final del scroll. |
| `onCreateOption` | `(label) => void` | Free-text: muestra fila "Crear «…»" cuando no hay match. |
| `footer` | `ReactNode` | Acción fija al pie (ej. botón "Crear insumo"); cierra el panel al click. |
| `renderOption` | `(opt, {selected,active}) => ReactNode` | Render custom por fila (categoría/stock, íconos, texto secundario). Debe caber en ~36px (virtualización). |
| `open` / `onOpenChange` | `boolean` / `(o)=>void` | Modo controlado del panel (opcional; si se omite el componente lo maneja solo). |
| `virtualizeThreshold` | `number` | Nº de opciones para virtualizar (default 100). |
| opciones | `{ value, label, disabled? }` | `disabled` atenúa y bloquea la opción. |

**Capacidades:** búsqueda insensible a mayúsculas/acentos, navegación por teclado (↑/↓/Home/End/Enter/Escape), virtualización automática para listas grandes, y a11y (`role="listbox"`, `aria-activedescendant`, `aria-multiselectable`).

```tsx
// Single, con búsqueda local
<Combobox value={proveedorId} onChange={setProveedorId} options={opts} placeholder="Selecciona…" searchPlaceholder="Buscar proveedor…" />

// Async + paginado (modo servidor)
<Combobox value={id} onChange={setId} options={results} loading={isFetching} onSearchChange={setQuery} onReachEnd={fetchNextPage} />
```

### 11.x Tooltips y ayuda contextual

Objetivo: que el usuario entienda qué hace cada control sin abrir el manual y sin equivocarse primero. Se usan los dos primitivos que ya existen — **no se crea ninguno nuevo**:

- `components/ui/tooltip.tsx` (Radix) para hover sobre un elemento existente.
- `components/ui/field-hint.tsx` → `FieldHint` (ícono `(i)` junto a un `<Label>`) y `ReasonTooltip` (explica por qué un control está deshabilitado).

Siete reglas (origen: plan WhatsApp §8.4c):

1. **Nada crítico vive solo en el hover.** El tooltip amplía, nunca reemplaza. En móvil no hay hover: si la información es necesaria para decidir, va en el texto o en un `Popover` con tap. (Ej.: el estado del canal se muestra como texto en la tarjeta, no solo en el pill.)
2. **Los elementos `disabled` no disparan eventos de mouse** — Radix nunca mostraría el tooltip. Hay que envolver el control en un `<span tabIndex={0}>` que sea el `TooltipTrigger`. Eso es exactamente lo que hace `ReasonTooltip`: usarlo para el motivo de una acción gris (falta permiso / sede sin automatización / número desconectado), que es el tooltip que más falta hace. Sin el wrapper se implementa y no aparece nunca.
3. **`delayDuration={150}`** (el mismo de `FieldHint`). En 0 estorba con solo cruzar el mouse; en 700 parece que la app no responde. (Distinto del `ShortcutTooltip`, que usa 3000 ms para atajos de teclado.)
4. **Máximo ~140 caracteres, una sola idea.** Si necesita más, es un `Popover` o un link al manual.
5. **Nunca contenido interactivo adentro** (botones, links): el puntero no llega sin cerrar el tooltip. Eso es un `Popover`.
6. **No poner tooltip en lo obvio** (ícono de enviar, lupa de buscar). Un tooltip en todo es un tooltip en nada.
7. El trigger **conserva su `aria-label`**; Radix ya cablea `aria-describedby`.

```tsx
// Motivo de una acción deshabilitada (el caso crítico de la regla 2):
<ReasonTooltip reason={!canUpdate ? 'Necesitás el permiso «Editar chats» para responder.' : null}>
  <Button disabled={!canUpdate}>Responder</Button>
</ReasonTooltip>
```

---

## 12. Dialogs, Sheets, BottomSheets

### Cuándo usar cuál

| Patrón | Cuándo |
|---|---|
| `BottomSheetDialog` | **Default para modales nuevos.** Dialog en desktop, BottomSheet en mobile. |
| `Dialog` directo | Confirmaciones cortas (¿Seguro?) o flujos que no funcionan bien en mobile. |
| `Sheet` (lateral) | Filtros avanzados, detalle de orden, edición lateral mientras se ve el listing. |
| `BottomSheet` puro | Acciones rápidas en POS (seleccionar mesa, propina, método de pago). |
| `ConfirmDialog` | Cualquier acción destructiva. Wrapper estándar. |

### Reglas

- **X siempre visible** en la esquina superior derecha, incluso cuando el contenido scrollea.
- **Scroll interno**, no de la página de fondo. El modal cierra con scroll-lock en `body`.
- **Z-index**: respetar el stack de Radix. No usar `z-[9999]` ad-hoc — abre PR para ajustar el stack si hay conflicto.
- **Focus trap**: garantizado por Radix. Verificar que el primer input recibe focus al abrir.
- **Escape cierra**, click en backdrop también, salvo `ConfirmDialog` destructivo (puede requerir click explícito en Cancelar).
- **Títulos**: `text-lg font-semibold`, no `font-brand`.
- **Footer del modal**: botones alineados derecha en desktop, `flex-col-reverse` en mobile (primario arriba en mobile = pulgar más cerca).
- **Anidación**: prohibida (modal abriendo modal). Si el flujo requiere dos pasos, usar wizard interno con `Tabs` o stepper.

---

## 13. Estados de carga, vacíos, error

### Loading

> El frontend es un **SPA (React Router v7 + TanStack React Query)**, no Inertia. No hay "deferred props": la carga diferida se logra **descomponiendo queries por sección** + **skeletons por bloque**.

**Navegación entre rutas (#269).** Al moverse entre páginas conviven dos mecanismos, montados una sola vez en `spa-app-layout.tsx`:

- **`RouteProgress`** (`components/ui/route-progress.tsx`): barra fina superior (`bg-primary`) que avanza con la transición de ruta (`useNavigation`) y las cargas iniciales de datos (`useIsFetching` con predicado `data === undefined`, para no parpadear en cada refetch de polling). Patrón *trickle*: arranca, avanza asintótico al 90%, completa al terminar.
- **`RouteSkeleton`** (`components/route-skeleton.tsx`): fallback del `<Suspense>` del shell. Mientras descarga el chunk lazy de la ruta destino pinta el `*-skeleton.tsx` que **calca** esa pantalla (no un spinner). Las rutas sin skeleton dedicado caen a un `PageShellSkeleton` genérico (header + panel). **Nunca** pantalla en blanco ni spinner suelto.

**Convención de skeleton por ruta:** cada ruta pesada expone un `*-skeleton.tsx` que replica su layout (header, filtros, tabla/grid, KPIs) y se enchufa en el mapa `ROUTE_SKELETONS` de `route-skeleton.tsx`. Detalle (`:id`) antes que listado en el orden del mapa. El skeleton se importa **eager** (vive en el bundle principal) porque debe pintarse al instante; por eso solo usa `<Skeleton>` + tokens DS, nada pesado.

**Carga progresiva por sección (el núcleo de #269).** Patrón canónico (referencia: `pages/dashboard.tsx` y `pages/metrics/index.tsx`):

- **1 query crítica** = el contenido principal (lista/tabla/tablero). Render apenas responde.
- **N queries secundarias** = KPIs, agregados, charts, catálogos de filtros. Cada una con su skeleton local; **no bloquean** la crítica.
- Bloques caros puramente visuales (heatmaps, charts grandes) → evaluar `IntersectionObserver`/`WhenVisible`: pedir solo al entrar al viewport.
- Preferir `useQuery` sobre `useState/useEffect` (cache + dedupe + estados de carga consistentes). Anti-flash en navegaciones repetidas: `placeholderData`/`keepPreviousData` y respetar `staleTime` (no mostrar skeleton si la data en cache está fresca).

**Otros loaders:**

- **Spinner inline** en botones durante submit. `disabled` + label "Guardando…".
- **Skeleton no abusivo**: 3–10 elementos, no toda la pantalla parpadeando.

### Vacío (empty)

- "No hay datos aún" vs "no hay coincidencias" son distintos — usar el patrón apropiado.
- Siempre con next step (CTA o sugerencia).
- Usar `PlaceholderPattern` para empty states sin imagen propia.

### Error

- **Errores de servidor (5xx)**: banner `Alert variant="destructive"` arriba de la página con CTA "Reintentar".
- **Errores de form (4xx con `errors`)**: inline en el campo afectado, con `<InputError message={errors.campo} />` (o `<p className="text-destructive text-xs">`) debajo del input y `aria-invalid` en el control. **Nunca** aplanar los errores de campo a un solo mensaje al pie del formulario (`Object.values(errors)[0]`, join, etc.): el usuario debe ver el error donde está el campo que lo causa.
- **El Alert/mensaje al pie del form queda solo para errores NO atribuibles a un campo**: red, 403, o el genérico cuando el 422 no trae `errors`. Mapear siempre `errors` (422) a estado por-campo antes de caer al genérico.
- **Errores de red / offline**: usar el sistema de `offline/` components, no un Alert genérico.
- **Toast para errores efímeros** que no requieren acción (ej. "No se pudo copiar al portapapeles").

### Toasts (feedback efímero)

- **Éxito**: 3 segundos. `variant="default"`. Mensaje corto, primera persona del verbo en pasado: `Empleado creado`.
- **Error**: 5 segundos o hasta dismiss. `variant="destructive"`.
- **Máximo 3 toasts simultáneos**, FIFO.
- **No usar toast para errores que el usuario debe accionar** — usar Alert o Dialog.
- **Skeleton `animate-pulse`** y otros loaders animados respetan la política de motion (§14).

---

## 14. Animaciones y motion

> **Status v3.4 — política nueva (esta release).** Tokens y wrap `prefers-reduced-motion` en `app.css` pendientes (Fase 2 de #189). Aplicación en componentes pendiente (Fase 3-4). Ver §21 brechas.

### Filosofía — "Pocas, sutiles, con propósito"

El default es **no animar**. Añadir movimiento es decisión consciente disparada por uno de los 3 casos válidos abajo. La filosofía de minimalismo editorial (§1) prohíbe lenguaje *"startup AI"* — y eso aplica al motion igual que aplica al color: glow, parallax, micro-animaciones en cada celda son ruido, no marca.

Regla práctica: si tienes que justificar la animación con *"queda bonito"*, no va. Si la justificas con *"comunica algo que el color/copy no comunica"* (un logro, un encaje, un dato vivo), va.

### Tokens — contrato del DS

`app.css` define (o definirá vía Fase 2 #189) en `:root`:

```css
:root {
    /* Motion — durations */
    --motion-duration-fast: 150ms;   /* hovers mínimos, focus rings */
    --motion-duration-base: 200ms;   /* default para entradas/salidas */
    --motion-duration-slow: 300ms;   /* transiciones de layout pequeñas */
    --motion-duration-dnd:  600ms;   /* solo drag&drop bounce */

    /* Motion — easings */
    --motion-ease-out:    cubic-bezier(0.16, 1, 0.3, 1);     /* default: arranca rápido, desacelera */
    --motion-ease-in-out: cubic-bezier(0.4, 0, 0.2, 1);      /* loops como pulse-subtle */
    --motion-ease-bounce: cubic-bezier(0.34, 1.56, 0.64, 1); /* drop-bounce only */
}
```

Toda @keyframe consume estos tokens (`animation: scaleIn var(--motion-duration-base) var(--motion-ease-out) both`). **No magic numbers** de duración o easing fuera del bloque de tokens. Los tokens viven en `:root` (no en `@theme`) porque son ad-hoc del DS y no necesitan exponerse a Tailwind como utilidades — se consumen directo con `var(--motion-*)` desde CSS.

### Cuándo SÍ — 3 disparadores válidos

| Disparador | Patrón | Componentes / vistas ejemplo |
|---|---|---|
| **Momento de logro** | `animate-scale-in` del check + fade del `block-lime`. Ocurre **una sola vez**, después se queda estático. | Caja cuadrada sin diferencias (`cash-register-panel`), onboarding completado (`enrollment/company`), "Turnos publicados" (`planner/week`) |
| **Drag&drop / kanban transitions** | `drop-bounce` 600ms `var(--motion-ease-bounce)` al soltar; fade-in 200ms al re-ordenar fila. Comunica "encajó". | `orders/board`, `menu/sortable-category`, `menu/sortable-item`, `planner/week` |
| **Toast / Alert + live polling** | Slide-in 200ms al aparecer toast (Radix default). `animate-pulse` lime en el dot del `live-indicator` **solo si polling está activo**. | `Toast`, `live-indicator`, `live-polling-toggle` |

### Cuándo NO — prohibido sin excepción

| Prohibición | Por qué |
|---|---|
| **Parallax / scroll-jacking / scroll-triggered** | Reservado a marketing landing. En SaaS operativo (caja, planner, dashboard) es ruido y bloquea la lectura rápida (§1). |
| **Hover micro-anim en celdas de tabla o filas de listing** | Satura visualmente `/orders`, `/employees`, `/inventory`, `/users`. Hover solo cambio de color de fondo (`hover:bg-muted`). |
| **Glow / neon / gradientes radiales / glassmorphism animado** | Lenguaje "startup AI" que el §1 declara out-of-brand. |
| **Lime pulse en estados estables** | `bg-accent animate-pulse` solo cuando hay polling activo o drop-zone activa. En estados estables viola §3 anti-saturación 6. |
| **Page transitions de Inertia entre rutas** | Con datos densos y polling en `/dashboard` / `/metrics` / `/caja`, una transición global se sentiría pesada y bloquearía la percepción de "datos frescos". |

### Defaults de duración y easing

- **Duración default**: 200ms (`var(--motion-duration-base)`). Sirve para fade-in, scale-in, slide-in de toasts, transiciones de color en hover/focus.
- **Easing default**: `cubic-bezier(0.16, 1, 0.3, 1)` (`var(--motion-ease-out)`). Arranca rápido, desacelera — sensación *snappy* coherente con Linear, no *bouncy juguetón*.
- **Excepción bouncy**: `drop-bounce` 600ms `var(--motion-ease-bounce)` queda como único bouncy autorizado. El bounce comunica "encajó" en drag&drop; fuera de ahí es ruido. Cualquier otro caso bouncy requiere aprobación explícita y entrada nueva en esta sección.
- **Lime `animate-pulse` infinito**: solo cuando hay polling activo o drop-zone activa. Apagar (no renderizar la utility) cuando el polling se pausa — no dejar pulseando en background.

### Catálogo de utilities listas

No inventar paralelas — consumir estas:

| Utility | Cuándo |
|---|---|
| `animate-fade-in` | Entrada suave (skeleton → contenido renderizado, banner que aparece sin ruido). |
| `animate-scale-in` | Aparición central con peso (check de logro, badge de hito, modal de éxito). |
| `animate-pulse-subtle` | Live indicators discretos, drop zones activas. Loop infinito de baja amplitud (opacity 1 → 0.6). |
| `animate-drop-bounce` | Drag&drop confirmation. Único bouncy autorizado. |
| `animate-pulse` (Tailwind core) | Skeletons de loading (rows, cards, charts). Loop infinito de alto contraste. |
| `animate-spin` (Tailwind core) | Spinner inline para botones disabled durante submit y para íconos de carga (`LoaderCircle` / `Loader2`). Loop infinito de baja intensidad. |
| `animate-in fade-in-0 zoom-in-95` (tw-animate-css) | Dialog / Sheet / DropdownMenu — Radix lo aplica automáticamente, **no sobrescribir**. |

### Accesibilidad — `prefers-reduced-motion`

**Toda @keyframe debe estar wrapped en `@media (prefers-reduced-motion: no-preference)`**. Fuera del media query las utilities `.animate-*` quedan vacías → para usuarios con motion sensitivity la app se ve idéntica pero estática, no rota.

Patrón canónico:

```css
@media (prefers-reduced-motion: no-preference) {
    @keyframes fadeIn {
        from { opacity: 0; }
        to   { opacity: 1; }
    }

    .animate-fade-in {
        animation: fadeIn var(--motion-duration-base) var(--motion-ease-out) both;
    }
}
```

**Verificación** — DevTools › Rendering › *Emulate CSS media feature prefers-reduced-motion: reduce*. Confirmar que ningún elemento se anima: ni dot lime de polling, ni drop-bounce, ni fade-in de skeletons, ni transición de toasts. La app debe seguir 100% funcional pero estática.

**Excepciones** — Radix UI (`Dialog`, `DropdownMenu`, `Sheet`, `Tooltip`) y `tailwindcss-animate` ya respetan `prefers-reduced-motion` internamente; no requieren wrap manual.

### Microcopy del motion

El motion no reemplaza al copy. Cuando hay logro:
- Animación: `animate-scale-in` del check.
- Copy: oración corta en pasado, primera persona del verbo — `Caja cuadrada sin diferencias` (no *"¡Felicidades! 🎉"*).

El §2 (voice) aplica igual al texto que acompaña una microinteracción de logro.

---

## 14.1 Estado de adopción por componente

> **Status v3.4 — auditoría completa (Fase 4 de #189).** Recorre `resources/js/components/**` (191 componentes) tras la implementación de §14. Mantenerla viva: cuando se agregue motion a un componente nuevo, actualizar la fila correspondiente.

### Resumen por categoría

| Categoría | # | Utility(es) | Estado |
|---|---|---|---|
| **Radix wrappers** (`dialog`, `sheet`, `tooltip`, `dropdown-menu`, `select`, `navigation-menu`, `toast`) | 7 | `animate-in` / `animate-out` / `fade-in-0` / `zoom-in-95` / `slide-in-from-*` | ✅ tw-animate-css respeta `prefers-reduced-motion` solo |
| **Skeletons de loading** (`ui/skeleton`, `dashboard/skeleton/*`) | 5 | `animate-pulse` (Tailwind) | ✅ §14 catálogo |
| **DS canónico — momento de logro, drag&drop, live polling** (`ui/achievement-mark`, `dashboard/live-indicator`, `menu/sortable-category`, `menu/sortable-item`, `menu/category-items-list`) | 5 | `animate-fade-in`, `animate-scale-in`, `animate-pulse-subtle`, `animate-drop-bounce` | ✅ §14 catálogo (Fase 1-3 de #189) |
| **Spinners de loading button** (`LoaderCircle` / `Loader2` en submit/disabled) | 28 | `animate-spin` (Tailwind) | ✅ §14 catálogo (entry nueva en v3.4) |
| **Custom DS-coherente** (`deliveries/delivery-card`, `deliveries/reassign-modal` campo condicional) | 3 | `animate-fade-in` (entrada suave) | ✅ §14 catálogo |
| **Sin motion** | 143 | — | ✅ Default §14: no animar |

**Total**: 191 componentes, 48 con motion (~25 %). **Cero violaciones residuales** tras los fixes listados abajo.

### Brechas detectadas y corregidas en v3.4

| Componente | Antes | Acción | Razón |
|---|---|---|---|
| `deliveries/assign-courier-modal.tsx` (badge `FULL`) | `animate-pulse-subtle` en el badge crítico | Removido — color critical estático | Estado estable de courier ocupado, no es polling activo ni drop-zone (§14 reserva lime pulse para polling activo) |
| `hours/open-status-badge.tsx` (dot "Abierto ahora") | `animate-pulse` en el dot lime | Removido — color safe estático | El status del restaurante es estable durante todo el horario, no es señal activa (§14 + §3 anti-saturación: pulse en estables prohibido) |
| `coupons/loyalty-card.tsx` (Alert "Consultando puntos…") | `<Sparkles animate-pulse>` | Reemplazado por `<LoaderCircle animate-spin>` | El ícono `Sparkles` linda con lenguaje *startup AI* (§1) y el `animate-pulse` sobre el ícono era ruido. `LoaderCircle` alinea con los 28 callsites de loading button del repo |

### Lo que **no** se detectó (verificado y limpio)

- **Hover micro-animaciones** (`hover:animate-*`): 0 callsites. El DS prohíbe patrones que saturen listados densos (§14 cuándo NO).
- **Animaciones prohibidas** (`animate-bounce`, `animate-ping`, `animate-spin-slow`): 0 callsites.
- **Page transitions de Inertia**: no implementadas, coherente con §14 cuándo NO.
- **Animaciones de gráficos** (`recharts`): traen las suyas y no se sobrescriben — out of scope §14 (refactorar es decisión separada).

### Refactor opcional futuro (no bloquea)

Los 28 callsites de `<LoaderCircle className="...animate-spin" />` repiten un patrón idéntico. Si crece la superficie o queremos uniformizar tamaños/colores por contexto, vale crear un primitive `<LoadingSpinner size="sm|base" />` y migrar incremental. Hoy el patrón es claro y aislado — no urge.

### Cómo mantener esta tabla

Cuando agregues motion a un componente nuevo:

1. **Primero**: revisá §14 catálogo. Si tu caso no encaja, **no inventes una utility paralela** — abre discusión o issue antes.
2. **Si añadís una utility nueva al DS**: agregala al catálogo §14, define el `@keyframe` en `app.css` dentro del wrap `@media (prefers-reduced-motion: no-preference)` y consume tokens `var(--motion-duration-*)` / `var(--motion-ease-*)`.
3. **Lime pulse**: confirmá que la señal es **activa** (polling, drop-zone) y no estado estable. Si es estable, el color sin pulse ya comunica.
4. **Tras tu cambio**: actualizá la fila correspondiente en esta tabla. Si tu componente pasó de "Sin motion" a alguna otra categoría, mové la fila y ajustá el conteo del resumen.

---

## 15. PWA, offline, mobile

### PWA shell

- Componentes en `components/pwa/`. Install prompt, update banner, service worker status.
- App instalable desde el navegador. Manifest en `public/manifest.json`.
- **Splash screen**: respeta tokens del rediseño — fondo `--color-theme-light`, logo `font-brand`.

### Modo offline

- Componentes en `components/offline/`. Banner persistente arriba cuando se pierde la conexión.
- **Caja sin conexión**: las órdenes y cobros se guardan en IndexedDB (`offline/`). El badge `pending_sync_count` aparece en el header de caja.
- **Cierre de caja con pendientes**: bloqueado por el backend (`#140`). El frontend muestra un Alert claro: `Tienes N cobros sin sincronizar. Conecta a internet antes de cerrar.`

### Mobile-first

- Pantallas POS (orden, cobro, productos) priorizan `min-w-[44px]` en todo elemento interactivo.
- **Sidebar en mobile** colapsa a drawer. Toggle en el header (icono hamburguesa).
- **Tablas**: en mobile, considerar `Card` por fila o columnas colapsables. No scroll horizontal salvo para reports densos.
- **BottomSheet** preferido sobre Dialog para acciones de POS — el pulgar alcanza la parte baja.

### Safe-area en PWA instalada (notch / home indicator / gesture bar)

Cuando la app corre como PWA instalada (`display-mode: standalone`) el contenido va edge-to-edge bajo la UI del SO. Para que header y contenido no queden pegados a la status bar / notch / home indicator / barra de gestos y sean difíciles de tocar:

- **Requisito base**: `index.html` declara `viewport-fit=cover` en el `<meta viewport>`. Sin eso, `env(safe-area-inset-*)` reporta `0` y todas las utilidades safe-area quedan inertes.
- **Utilidades canónicas** (en `css/app.css`):
  - `.pwa-safe-top` / `.pwa-safe-bottom` → `padding-top|bottom: max(<comfort>, env(safe-area-inset-top|bottom, 0px))`, **gated a `@media (display-mode: standalone)`** (en navegador normal no aplican; el layout queda idéntico). Usar en barras superiores fijas/sticky y en el contenedor de contenido inferior de cada superficie cuando **no** tienen padding propio en ese eje.
  - `.pb-safe-1` → barra inferior fija/flotante (`StickyActionBar`, carrito QR). Ya existente.
  - `.pt-safe` / `.pb-safe` / `.pl-safe` / `.pr-safe` → inset puro (`env(...)`, fallback `0px`), sin comfort base.
- **Elemento que YA tiene padding en ese eje** (p.ej. header con `pt-4`): NO apilar `.pwa-safe-*` (colisiona la misma propiedad). Reemplazar el padding base por el valor arbitrario `pt-[max(<base>,env(safe-area-inset-top,0px))]` — determinista, navegador idéntico (`env=0` → base), standalone respeta el inset.
- **Cobertura del shell**: el panel autenticado cubre todas sus páginas en 2 puntos — `app-sidebar-header.tsx` (`.pwa-safe-top` + `min-h-*` para que el inset crezca el header sin aplastar el contenido) y `app-content.tsx` → `SidebarInset` (`.pwa-safe-bottom`). Superficies cara-cliente (menú público, pedido en mesa) y auth aplican el mismo criterio en sus roots/headers.

### Standalone full-screen (kiosk) — patrón KDS (#115)

Patrón para pantallas que viven en una tableta fija sin sesión web (cocina, eventualmente impresoras térmicas, displays de domicilio):

- Layout dedicado tipo `kds-standalone-layout.tsx`: **NO** monta `AppShell` / `AppSidebar` / `AppSidebarHeader`. Solo `ToastProvider` + `Head` + un wrapper `min-h-dvh w-screen overflow-x-hidden flex flex-col`. `dvh` (no `vh`) para no perder espacio cuando iOS Safari colapsa la URL bar.
- Auth por device-token (no JWT): cookie HttpOnly `kds_device_token` o `Authorization: Bearer`. Middleware dedicado (`kds.device` en este caso) resuelve `active_company_nit`, `active_branch_id`, `active_station_id` e inyecta en `$request->attributes` sin sesión.
- `useAutoPolling({ intervalMs, onTick, pauseWhenHidden: true })` — polling continuo con pausa por `visibilitychange`. NO usar `useLivePolling` (ese requiere toggle manual + auto-off de 5 min).
- Sin `OfflineBootstrap` / `SyncToast` / `OfflineBanner`: el sync-engine requiere JWT + active_company; si el caso necesita offline, montar un módulo dedicado con device-token como key.
- Tipografía y áreas táctiles: `min-h-[44px]` sigue siendo invariante de DS. Para legibilidad a distancia (cocina), el nombre principal del item arranca en `text-xl sm:text-2xl` (no en `text-base` como inputs).
- SLA y semáforos: usar tokens semánticos del DS (`bg-safe`, `bg-warning`, `bg-critical` con sus pares `text-*` y `border-*/40`) — el color identitario de la entidad (estación, sede) se aplica al chip del header con `style={{backgroundColor: hex}}` por venir de BD. **Prohibido** hex hardcoded en bordes/fondos de SLA.

---

## 16. PDFs y brand

PDFs generados desde Blade (`resources/views/pdf/*.blade.php`) — workforce report, cierre de caja, factura electrónica, recibo de cliente.

### Reglas de brand en PDF

- **Header**: logo + razón social + NIT + sede. Usar `FlexyFont` para "FLEXYFLOW" si aparece como wordmark.
- **Títulos de sección y totales**: `FlexyFont` para diferenciarlos del body.
- **Body, tablas, metadatos**: `Instrument Sans` o fallback `Arial`/`Helvetica` (no todos los PDFs renderizan bien custom fonts).
- **Color**: fondo blanco siempre. Headings `#232733`, body `#1E232E`. Lime solo en banners de "estado conciliado".
- **Footer**: línea separadora 1px `#E5E5E5`, texto `text-xs` con paginación, fecha de generación, y URL del panel.
- **Orientación**: vertical por defecto. Landscape solo cuando la tabla lo exija (workforce con 7 días + métricas).
- **DomPDF** es lo que usamos hoy. Confirmar `font-family` cargada vía CSS `@font-face` antes de usar `FlexyFont`.

---

## 17. Analytics y data-cta

Cuando se instrumente GA4 (Fase posterior), seguimos la convención del marketing v2.1:

```tsx
<Button
  variant="default"
  data-cta="abrir-caja"
  data-cta-location="dashboard"
  onClick={openCash}
>
  Abrir caja
</Button>
```

Eventos a instrumentar (idénticos al marketing donde apliquen, más específicos de app):

| Evento | Cuándo | Parámetros |
|---|---|---|
| `cta_click` | Click en `[data-cta]` | `cta_id`, `cta_location`, `page_path` |
| `form_submit` | Submit de form | `form_id` |
| `order_completed` | Orden cerrada con pago | `order_id`, `total`, `payment_method` |
| `cash_session_opened` | Apertura de caja | `branch_id`, `opening_amount` |
| `cash_session_closed` | Cierre de caja | `branch_id`, `cash_difference` |
| `shift_assigned` | Asignación de turno | `employee_id`, `branch_id` |
| `offline_mode_entered` | Pérdida de conexión durante uso | — |
| `pwa_installed` | Install prompt aceptado | — |

Implementación vía helper en `resources/js/lib/analytics.ts` (a crear cuando se instrumente).

---

## 18. Accesibilidad

- **Contraste AA mínimo** (WCAG 2.1): `text-foreground` sobre `bg-background` ya cumple. Verificar combinaciones con `--muted-foreground` en dark.
- **Focus visible** en todo interactivo. `Button` y `Input` ya incluyen `focus-visible:ring-2 focus-visible:ring-ring`.
- **`<label>` asociado** a todo `<input>` con `htmlFor`. Los labels visualmente ocultos usan `sr-only`.
- **ARIA**: Radix ya añade `aria-*` correctos en modales, menús, dropdowns. No sobreescribir salvo casos puntuales.
- **Skip link**: la app tiene "Saltar al contenido principal" en el header. No removerlo.
- **Iconos solo**: siempre `aria-label` o `Tooltip`. Un botón solo con icono sin label rompe accesibilidad.
- **Idioma del documento**: `<html lang="es">` (configurado en `app.blade.php`).
- **Keyboard navigation**: el sidebar, las tablas y los modales deben ser operables sin mouse. Verificar `Tab`, `Shift+Tab`, `Esc`, `Enter`, flechas en listas.
- **Atajos globales**: documentados en `ShortcutsHelpModal`. Atajo de apertura `?`.

---

## 19. Mapeo Marketing → Plataforma

Tabla de equivalencias para traducir un patrón del rediseño v2.1 a la app:

| Marketing v2.1 | Plataforma v3.0 | Notas |
|---|---|---|
| `block-light` (section) | Página por defecto con `bg-background` | La página entera es el "bloque" |
| `block-lime` (CTA final) | Hero de `welcome.tsx` / pantalla de logro / KpiHero | Un solo block-lime grande por vista; elementos chicos lime (badges, pills, dots) sin límite numérico — ver §3 |
| `block-dark` | Sidebar en dark mode / footer PWA | Surface de peso, no full-width |
| `block-grid` (gap-px) | Grid de métricas en dashboard | Mismo truco `gap-px bg-border` |
| `.btn-primary` | `Button variant="default"` | Azul `#0052FF` |
| `.btn-accent` | `Button variant="accent"` | Lime, CTAs positivos (pueden coexistir varios); ver §8 |
| `.btn-dark` | `Button variant="dark"` | CTA único sobre `block-lime` (negro `#232733` sobre lime, alto contraste). Mismo rol en website y app. |
| `.btn-ghost` | `Button variant="outline"` o `secondary` | |
| Pill editorial uppercase | Mismo patrón con `bg-accent` o `bg-secondary` | |
| Hero `text-display-2` 95px | `text-3xl md:text-5xl` con `font-brand` en auth/welcome | App densa, no landing |
| `font-secondary` (display) | `font-brand` (FlexyFont) en momentos de marca | Limitado a wordmark/hero/PDF |
| `font-primary` (sans) | `Instrument Sans` (default) | Toda la UI |
| Form Netlify | Inertia forms con server validation | |
| `OptimizedImage` Astro | `<img loading="lazy">` o componente Inertia | |
| `JsonLD` por tipo de página | No aplica (app autenticada, no SEO) | |
| Atajos `data-cta` | Idéntico | §17 |
| Header solo-logo centrado | `AppHeader` con breadcrumbs + sidebar trigger | App, no landing |
| Footer denso con links | `AppSidebar` (footer del sidebar tiene user menu) | |
| Filigrana eliminada | No tiene sentido en app | |
| Spanish-only `i18n` | Idéntico | Solo `es` |
| `OptimizedImage` reload | Vite HMR | |
| `pnpm dev` | `npm run dev` o `composer run dev` | Distinto package manager |

### Lo que NO viene del marketing

- **Dark mode** — no existe allá.
- **Sidebar pattern** — el marketing no tiene navegación lateral.
- **PWA / offline** — el marketing es estático.
- **Tabla densa** — el marketing usa cards editoriales.
- **BottomSheet** — el marketing no es mobile-first operativo.
- **Brand font en PDFs** — el marketing no genera PDFs.
- **Política financiera visual** — refunds, tabular-nums, semáforo de caja: específico de app.

---

## 20. Convenciones de implementación

- **Tailwind 4** con variables CSS. Siempre tokens, nunca hex hardcoded.
- **shadcn/ui** como librería base. No añadir Material UI, Mantine, Chakra ni Headless UI directo (Radix ya lo trae shadcn).
- **Componentes** en `resources/js/components/`. Los de shadcn en `ui/`. Los de dominio en raíz por feature.
- **Pages Inertia** en `resources/js/pages/{feature}/{action}.tsx`. Nombres en kebab-case o snake según convención existente (revisar sibling).
- **Iconos**: `lucide-react`. No mezclar con otras librerías de iconos.
- **Animaciones**: política completa en §14. Plugin `tailwindcss-animate` ya cargado; custom keyframes en `app.css` (`fade-in`, `scale-in`, `pulse-subtle`, `drop-bounce`) consumen tokens `var(--motion-*)`. No agregar @keyframes por fuera del catálogo §14.
- **CSS adicional** se añade a `resources/css/app.css`, no a archivos sueltos. Usar `@layer` para no romper especificidad.
- **Inertia v2 features**: deferred props, polling, prefetch, merging props. Usar para reducir loading visual percibido.
- **`route()` (Ziggy)** para links internos, nunca string hardcoded.

### Versionado de la guía

Cada cambio material bumpea la versión semántica al inicio del archivo:

- **PATCH** (`v3.0` → `v3.0.1`) — clarificaciones de copy, fixes tipográficos, ejemplos extra.
- **MINOR** (`v3.0` → `v3.1`) — nueva sección, nuevo patrón documentado, extensión de tokens.
- **MAJOR** (`v3.0` → `v4.0`) — cambio de filosofía, ruptura con paleta, deprecación amplia de patrones.

---

## 21. Estado de adopción

Esta guía es **prescriptiva** — describe el destino, no el estado actual completo. Componentes y pantallas existentes que no se alinean se refactorizan en issues separados.

### Lo que YA está alineado

- Tokens CSS del rediseño en `app.css` (paleta completa, light + dark, semáforo).
- `FlexyFont` registrada y usada en `AppLogo` wordmark.
- `Button` con variants `accent`, `primary` y `dark` (v3.1).
- Touch targets `min-h-[44px]` en `Button`.
- `BottomSheetDialog` responsivo.
- Política de motion documentada en §14 (v3.4): filosofía, 3 disparadores SÍ, prohibiciones NO, catálogo de utilities, contrato de tokens y `prefers-reduced-motion`. Utilities base (`fade-in`, `scale-in`, `pulse-subtle`, `drop-bounce`) ya existen en `app.css` — **solo documentación**; tokens y a11y wrap pendientes (ver brechas).
- `Sidebar` colapsable con tokens propios.
- Status semáforo en CSS.
- `PageHeader` con modo `editorial` y `eyebrow` (v3.1).
- `KpiHero` (block-lime con stats, v3.1).
- `EditorialEmpty` (empty editorial, v3.1).
- `DashboardPanel` (Card+header estándar para widgets de dashboard, v3.1).
- `StatTile` (mini-card 3-up con tone, v3.1).
- `--destructive` + `--color-status-critical` unificados al terracota editorial `#D9402A` light / `#F0876B` dark (v3.2). Ver §3 "Por qué terracota".
- `--accent` documentado con política "positivo + decoración editorial" (v3.2). Ver §3 "Lime — política de uso".
- Paleta complementaria documentada en v3.3 (info, success diferenciado de safe, warning unificado, category 5 stops). Ver §3 "Paleta complementaria". **Solo documentación** — `app.css` y refactor de componentes pendientes (ver brechas abajo).

### Brechas conocidas (issues a abrir)

- ~~`Badge` no tiene variants `safe`, `warning`, `critical`, `accent`~~ — **resuelto**: `badge.tsx` ya implementa las 4 variants. Usar `<Badge variant="safe|warning|critical|accent">` directamente.
- ~~Falta `Button variant="dark"`~~ — **resuelto en v3.1**: variant `dark` agregada a `button.tsx` (negro sobre lime).
- El token `--secondary` de shadcn cambia de rol respecto al website (allá es `#1E232E` oscuro, acá `#E5E5E5` neutro). Documentado en §3, pero queda confuso para quien venga del marketing — evaluar renombrar el alias en `app.css` para alinear nomenclatura.
- Falta helper `analytics.ts` para `data-cta` tracking.
- PDFs no cargan `FlexyFont` de manera consistente (verificar `workforce-report.blade.php` y `cash-register-close.blade.php`).
- Empty states en algunos listings usan textos genéricos ("Sin resultados") — refactor pendiente.
- Toasts en algunos flujos antiguos usan mensajes en primera persona del verbo en presente continuo ("Guardando…") como label estable — debería ser solo durante el loading.
- ~~Strings de UI con voseo~~ — **resuelto en v3.1**: barrido completo, cero matches de voseo en strings de UI.
- **Drift de paleta neutral/gray** — ~30 instancias de `bg-neutral-*`, `text-gray-*`, `bg-gray-*`, `bg-zinc-*` (auditoría mayo 2026). Concentrado en `InvoiceDetailModal`, `SuspendedBlockedView`, `print-receipt-button`, `shortcut-tooltip`, `shortcuts-help-modal`, `user-info`, `nav-main` (kbd), `food-cost-panel` (zinc), `ui/bottom-sheet*` (handle). Migrar a `bg-muted`, `bg-secondary`, `text-muted-foreground`, `text-foreground`, `bg-muted-foreground/30` según mapping en §3 "Paleta complementaria". El visual es similar pero rompe dark mode en algunos casos. Issue separado.
- **Drift Tailwind semántico** (auditoría mayo 2026) — 4 categorías pendientes de migración a tokens del DS:
  - **Info** (azul informativo): `ui/toast.tsx` variant info, `InvoiceTypeChip` subscription, `active-orders-panel` in_transit, `chat-message-status-ticks` leído. ~6 archivos. Migrar a `--color-status-info` cuando se agregue al `app.css`.
  - **Success** (verde de confirmación, distinto de safe): `ui/toast.tsx` variant success, `InvoiceStatusBadge` paid, `SuspendedBlockedView` accepted, `UploadPaymentProof`, `delivery-card`, `delivery-status-badge`, `confirm-complete-modal`, `food-cost-panel`, `menu-engineering-panel` star. ~10 archivos. Migrar a `--color-status-success`.
  - **Warning unificado** (amber/yellow/orange convergen): `PastDueBanner`, `SuspendedBlockedView` submitted, `InvoiceStatusBadge` pending, `active-orders-panel` pending/in_kitchen, `offline-banner`, `storage-quota-warning`, `install-pwa-prompt`, `ios-install-hint`, `update-available-toast`, `food-cost-panel`, `menu-engineering-panel`, `whatsapp-verification-code-modal`. ~12 archivos. Migrar a `--color-status-warning` (ya existe en DS).
  - **Destructive/critical** (red Tailwind → token): `ui/confirm-dialog.tsx` (primitiva!), `InvoiceStatusBadge` overdue, `SuspendedBlockedView` rejected, `PastDueBanner`, `delete-user`, `client-detail-modal`, `input-error`, `print-receipt-button`, `chat-message-status-ticks`, `food-cost-panel` (`#dc2626`), `menu-engineering-panel` (`#dc2626` dog). ~11 archivos. Migrar a `bg-destructive/10`, `text-destructive`.
- **Paleta categórica pendiente** — `deliveries/courier-avatar` rota 8 colores Tailwind hardcoded para identificar repartidores; `menu-engineering-panel` usa 4 hex (`#10b981`, `#3b82f6`, `#a855f7`, `#dc2626`) para BCG matrix; `InvoiceTypeChip` usa purple para "addon". Documentado mapping a `--color-category-*` (5 stops) en §3 "Paleta complementaria". Pendiente agregar tokens a `app.css` y refactor.
- **Heatmap charts con primary RGB hardcoded** — `dashboard/heatmap-chart.tsx` usa `rgba(0, 82, 255, ...)` y `dashboard/sales-heatmap.tsx` usa `rgba(var(--color-primary-rgb, 79 70 229) / ...)` (fallback indigo desactualizado). Necesita agregar `--primary-rgb: 0 82 255` al `app.css` y consumirlo en ambos.
- **Primitivas del DS con drift** — `ui/toast.tsx` (success/error/info hardcoded blue/green/red), `ui/confirm-dialog.tsx` (red-100/600/700 en lugar de destructive token), `ui/bottom-sheet.tsx` + `ui/bottom-sheet-dialog.tsx` (handle `bg-gray-300`). Prioridad alta: las primitivas se consumen en decenas de pantallas.
- **Badges de dominio con color ad-hoc** — `coupon-status-badge`, `coupon-type-badge`, `delivery-status-badge`, `segment-badge`, `chat-source-badge`, `open-status-badge`, `InvoiceStatusBadge` implementan su propia lógica de color en lugar de delegar al `Badge` (que ya tiene variants `safe|warning|critical|accent`). Refactor pendiente para unificar.
- **Recharts hex literals** — `food-cost-panel.tsx`, `menu-engineering-panel.tsx`, `InventoryValuationChart.tsx` usan hex literales para series de gráficos. Recharts no consume CSS vars directamente; resolver con `getComputedStyle` en runtime o aceptar como excepción documentada en §3.
- **Motion sin tokens ni `prefers-reduced-motion`** (Fase 2-4 de #189) — `app.css` define `fadeIn`/`scaleIn`/`pulse-subtle`/`drop-bounce` con duración y easing hardcoded (200ms, 600ms, `cubic-bezier(...)` literales) y **sin** wrap `@media (prefers-reduced-motion: no-preference)`. Pendiente: agregar `--motion-duration-{fast,base,slow,dnd}` y `--motion-ease-{out,in-out,bounce}` al `:root` y refactorizar las 4 @keyframes. Adicional: aplicar microinteracción "momento de logro" en cash close / onboarding / planner (Fase 3) y auditar los ~170 componentes UI (Fase 4).

### Cómo proponer cambios a la guía

1. Abrir issue con `[ui-guidelines]` en el título.
2. Si el cambio afecta un componente, abrir PR que actualice **guía + componente** en el mismo commit.
3. Bumpear versión al inicio del archivo según SemVer.
4. Pedir review a alguien del equipo de producto y a alguien de ingeniería.

---

## 22. Referencias visuales

- **Bakken & Bæck** — bakkenbaeck.com (baseline editorial)
- **Linear** — linear.app (sidebar denso, atajos, dark mode)
- **Stripe Dashboard** — stripe.com (tablas financieras, tabular-nums)
- **Slalom Build** — slalombuild.com (hero pattern)
- **Cal.com** — cal.com (form patterns shadcn)
- **Vercel Dashboard** — vercel.com/dashboard (light/dark, pills)
- **shadcn/ui** — ui.shadcn.com (componentes base, dark mode docs)

---

Esta guía es el contrato visual y de copy de la plataforma. Cualquier componente o página nueva debe poder explicarse en términos de los bloques (§6), los componentes (§7), los botones (§8) y el tono (§2). Cuando una decisión no esté cubierta acá, primero se actualiza esta guía y después se implementa el código.

---

## 15. Sanitización de inputs (HU #200)

> Política completa en `docs/wiki/SECURITY_INPUT_HANDLING.md`. Esta sección resume lo aplicable a la capa visual.

### Patrón canónico de input controlado

1. **Texto corto (nombres, ciudad, slug visible)** — usar `<SanitizedInput>`
   (`components/ui/sanitized-input.tsx`) con `maxLength` igual al `max`
   declarado en el backend. `allowWhitespace` default `false`.

   ```tsx
   <SanitizedInput
       value={name}
       onChange={setName}
       maxLength={120}
   />
   ```

2. **Texto largo (notes, description, address, motivo, body)** — usar
   `<textarea>` (o `<NotesEditor>` cuando aplique) y aplicar
   `sanitizePlainText(value, maxLength, true)` en el `onChange`:

   ```tsx
   <textarea
       value={notes}
       onChange={(e) => setNotes(sanitizePlainText(e.target.value, 500, true))}
       maxLength={500}
   />
   ```

3. **Markdown trusted** — único caso permitido: `<Markdown content={...} />`
   (`components/ui/markdown.tsx`). Ningún otro componente debe usar
   `react-markdown` directamente. Si necesitás render de markdown,
   reutilizá el componente del DS o extendé su allowlist en consenso.

4. **NUNCA** `dangerouslySetInnerHTML` con texto del usuario. La auditoría
   de seguridad falla con cualquier uso nuevo.

### Cuándo crear o extender

- Si un patrón visual nuevo de input aparece en >1 página, promover a
  primitive en `components/ui/` siguiendo §7. Aplicar saneamiento
  internamente.
- Si un schema zod nuevo cubre >1 form, vivir en
  `resources/js/lib/schemas/<feature>.ts` usando los factories
  `plainTextShort` / `plainTextLong` de `schemas/common.ts`.

### Tokens y semántica

El feedback visual del sanitizer (cap de bytes alcanzado, control char
rechazado) sigue el sistema editorial:

- Cap próximo a max → `text-amber-600` (warning suave).
- Mensajes de error de validación → `text-destructive`.

NO inventar paletas para "violación de sanitización"; reusar los tokens
de estado existentes (§3 paleta).

