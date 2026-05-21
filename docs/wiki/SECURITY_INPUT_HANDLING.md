# Sanitización de inputs — Política única del proyecto

> **Fuente de verdad** para la categorización y saneamiento de texto de usuario en backend, frontend y base de datos. Cualquier campo nuevo de texto libre debe declarar su categoría según esta política. Cualquier cambio a esta política se discute en el issue de origen (#200) y se versiona en este documento.

---

## Principio rector

**Saneamos en persistencia, escapamos en render.** El backend nunca confía en el cliente, el cliente nunca confía en el servidor, y la base de datos guarda solo texto plano normalizado. La única excepción es `legal_documents.content` (markdown trusted, editado solo por el equipo vía deploy, sanitizado en render con `rehype-sanitize`).

Cero campos de usuario admiten HTML enriquecido. Cero columnas guardan markup. Cero canales de salida (KDS, comanda térmica, carta QR pública, WhatsApp, PDF de factura, email, panel admin) reciben texto sin pasar por la capa de saneamiento.

---

## Categorías

| Categoría | Cuándo usarla | Backend rule | Frontend helper | Render seguro |
|---|---|---|---|---|
| `plain_text_short` | Nombres, ciudad, número de mesa, código corto | `strip_tags` + `trim` + NFC + `NoControlCharacters` + `mb_substr` al `max:` declarado | `sanitizePlainText(v, max)` | `{{ }}` Blade / JSX interpolation |
| `plain_text_long` | Notas, descripciones, direcciones, razones de cancelación, mensajes de chat | `strip_tags` + `trim` + NFC + `NoControlCharacters` (permite `\n`, `\t`) + `mb_substr` | `sanitizePlainText(v, max)` | `{{ }}` Blade / JSX interpolation |
| `markdown_trusted` | **Únicamente** `legal_documents.content` (TOS, privacy, contratos) | Passthrough (texto raw del archivo del repo, sin transformar) | n/a (no editable por usuario) | `react-markdown` + `rehype-sanitize` con allowlist + `rehype-external-links` |
| `identifier` | NIT, email, teléfono, slug, código de cupón, código postal | Regex estricta por subtipo + `Str::lower` cuando aplique | `assertIdentifier(v, kind)` | `{{ }}` (no necesita sanitización extra; la regex ya filtró) |
| `json_payload` | `audit_logs.data`, `restaurant_menus.structure`, settings opacos | Validación por esquema (Laravel `array` + reglas anidadas) | n/a (interno) | `{{ json_encode(...) }}` con `JSON_HEX_TAG \| JSON_HEX_AMP` |

---

## Caracteres bloqueados

En todas las categorías `plain_text_*` se rechazan:

- `U+0000` – `U+001F` (control characters) **excepto** `U+0009` (TAB) y `U+000A` (LF) en `plain_text_long`. `plain_text_short` no permite ningún control character.
- `U+007F` (DEL).
- `U+202A` – `U+202E` (bidi overrides — usados para spoofing visual de nombres y direcciones, e.g. `pwnd‮gpj.exe`).

Se **permiten** explícitamente:

- `U+200E`, `U+200F` (LRM, RLM — markers de direccionalidad legítimos para árabe/hebreo).
- Emojis (rango Unicode > U+1F000) — no son control characters y son funcionales en chat, nombres de comercio, etc.

---

## Normalización Unicode

El middleware `NormalizeStrings` aplica **NFC** (Canonical Composition) a todos los campos `string` y `text` del payload antes de validation. Esto colapsa formas distintas que renderizan igual (e.g. `é` como `U+00E9` o como `U+0065 U+0301`) a una sola forma canónica.

Whitelist de rutas excluidas del middleware (no se normaliza):

- `/api/v1/webhooks/whatsapp` — el payload viene de Meta y se valida con signature; mutarlo invalidaría la firma.
- `/api/v1/webhooks/ses-notifications` — el payload viene de AWS SNS y se valida con firma RSA contra el canonical string byte-exact del body; mutarlo invalidaría la firma.
- `/csp-report` / `/api/v1/csp-report` — reporte de violaciones CSP que viene del navegador, no de un usuario.

---

## Reglas custom (PHP)

### `App\Rules\NoControlCharacters`

Falla si el valor contiene cualquier carácter del set bloqueado de arriba. Acepta `\t` y `\n` solo cuando se construye con `new NoControlCharacters(allowWhitespace: true)`.

### `App\Rules\SafePlainText`

Compuesta de `strip_tags` + `NoControlCharacters` + `mb_substr` (max length por **bytes**, no por chars). Se prefiere bytes para que un payload con muchos emojis no pase el cap real de la columna.

Uso típico:

```php
public function rules(): array
{
    return [
        'name' => ['required', new SafePlainText(maxBytes: 255)],
        'body' => ['required', new SafePlainText(maxBytes: 4000, allowWhitespace: true)],
    ];
}
```

---

## Trait `App\Http\Requests\Concerns\SanitizesInput`

Toda FormRequest que reciba texto libre declara su mapa de categorías:

```php
namespace App\Http\Requests\Chat;

use App\Http\Requests\Concerns\SanitizesInput;
use Illuminate\Foundation\Http\FormRequest;

class StoreChatMessageRequest extends FormRequest
{
    use SanitizesInput;

    protected array $sanitize = [
        'body' => 'plain_text_long',
    ];

    public function rules(): array
    {
        return [
            'body' => ['required', new SafePlainText(maxBytes: 4000, allowWhitespace: true)],
        ];
    }
}
```

El trait hookea `prepareForValidation()` y aplica la transformación según la categoría declarada. La validación rule sigue siendo la fuente de verdad del `max:` y de los tipos.

---

## Frontend — helpers (`application/resources/js/lib/input-sanitize.ts`)

```ts
sanitizePlainText(value: string, maxLength: number): string
assertNoControlChars(value: string): boolean
stripDangerousHtml(value: string): string  // defense in depth, no reemplaza al backend
assertIdentifier(value: string, kind: 'nit' | 'email' | 'phone' | 'slug' | 'coupon'): boolean
```

El frontend no garantiza nada — el backend es la única fuente de verdad. Los helpers existen para:

1. Cortar de inmediato textos absurdamente largos antes de mandar al servidor.
2. Bloquear caracteres invisibles en el `onChange` para que el usuario vea el feedback al pegar.
3. Compartir las mismas reglas conceptualmente entre cliente y servidor (auditable).

### Schemas zod (`application/resources/js/lib/schemas/`)

Cada feature crítica declara su schema en `schemas/<feature>.ts`. El schema:

- Aplica `sanitizePlainText` en transform.
- Refleja el `max` del backend (constante exportada compartida).
- Compone con `useForm` de Inertia vía un wrapper en `lib/zod-form.ts`.

---

## Render seguro

### Blade / JSX

Por default todo se escapa con `{{ }}` (Blade) y JSX interpolation (`{value}`). **Cero uso de `{!! !!}`** o `dangerouslySetInnerHTML` con texto de usuario.

### Markdown (`components/ui/markdown.tsx`)

Solo `legal_documents.content`. Renderiza con `react-markdown` + `rehype-sanitize` con allowlist:

- Tags permitidos: `h1`-`h4`, `p`, `ul`, `ol`, `li`, `strong`, `em`, `code`, `pre`, `blockquote`, `a`, `br`, `hr`, `table`, `thead`, `tbody`, `tr`, `th`, `td`.
- Atributo `href` solo con protocolos `http`, `https`, `mailto`.
- `rehype-external-links` agrega `rel="noopener noreferrer nofollow"` y `target="_blank"` a links externos.
- Bloqueado: `<script>`, `<iframe>`, `<object>`, `<embed>`, eventos `on*`, `style` inline, `javascript:`, `data:` (salvo `data:image/...` si se necesita en una fase posterior).

### PDF (DomPDF)

Todos los Blades en `application/resources/views/pdfs/**/*.blade.php` usan `{{ }}` exclusivamente. La auditoría de la Fase 3 confirma 0 uso de `{!! !!}` con campos de usuario.

### Comanda térmica (ESC/POS)

Los bytes de control `\x1B` (ESC) y `\x1D` (GS) se filtran en cualquier texto que provenga del cliente final (nombre del cliente, notas de orden, dirección). Solo se preservan cuando los emite el formatter interno.

### Email

Templates en `application/resources/views/emails/**/*.blade.php` usan `{{ }}`. El `subject` se sanitiza con `SafePlainText(maxBytes: 255)` si incluye nombre del cliente.

---

## Inventario de columnas críticas (snapshot main @ `ec8cec13`)

52 columnas de texto libre identificadas. Las que tienen canal de salida externo (KDS, comanda, QR, WhatsApp, PDF, email) van por la **Fase 1** del plan; el resto va por inercia con el middleware `NormalizeStrings`.

| Tabla | Columna | Categoría | Canal de salida |
|---|---|---|---|
| `chat_messages` | `body` | `plain_text_long` | WhatsApp bidireccional, panel admin |
| `order_items` | `name` | `plain_text_short` | Comanda, KDS, PDF factura, email |
| `order_items` | `notes` | `plain_text_long` | Comanda, KDS, PDF factura |
| `order_notes` | `body` | `plain_text_long` | Comanda, WhatsApp |
| `orders` | `delivery_address` | `plain_text_long` | SMS/email cliente, app courier |
| `restaurant_menus` | `name` | `plain_text_short` | Carta QR pública |
| `restaurant_menus` | `description` | `plain_text_long` | **Carta QR pública (sin auth)** |
| `legal_documents` | `content` | `markdown_trusted` | UI público (signup, contratos) |
| `cart_items` | `notes` | `plain_text_long` | UI cliente público |
| `table_session_guests` | `display_name` | `plain_text_short` | **QR público + comanda impresa** |
| `client_notes` | `note` | `plain_text_long` | UI admin (CRM) |
| `invoice_lines` | `description` | `plain_text_long` | PDF factura |
| `contacts` | `name` | `plain_text_short` | WhatsApp + UI admin |
| `contacts` | `notes` | `plain_text_long` | UI admin |
| `branches` | `name` | `plain_text_short` | PDF comanda, UI admin |
| `branches` | `address` | `plain_text_long` | PDF comanda, UI admin |
| `companies` | `name`, `legal_name`, `commercial_name` | `plain_text_short` | Facturación DIAN, PDF factura |
| `companies` | `address`, `slogan` | `plain_text_long` | Facturación, marca |
| `coupons` | `code` | `identifier` (coupon) | Aplicación de descuento |
| `coupons` | `description` | `plain_text_long` | UI cliente |
| `menu_items` | `name` | `plain_text_short` | Carta QR pública |
| `menu_items` | `description` | `plain_text_long` | Carta QR pública |
| `users` | `name` | `plain_text_short` | UI admin, panel cliente |
| `delivery_status_logs` | `reason` | `plain_text_long` | UI admin, app courier |

---

## Migración one-off de data histórica

La migración `2026_05_19_xxxxxx_sanitize_existing_freetext.php` (Fase 1.4) aplica saneamiento a la data existente al momento del deploy. Es **idempotente** (compara hash antes de mutar) y registra cada fila tocada en `audit_logs` con `action = 'sanitize.migrated'` para trazabilidad.

Columnas migradas:

- `chat_messages.body`
- `order_items.notes`
- `client_notes.note`
- `cart_items.notes`
- `delivery_status_logs.reason`
- `branches.address`

`legal_documents.content` **no** se migra (es markdown trusted; cualquier cambio requiere bump de versión y revisión legal — ver `legal/README.md`).

---

## Reglas para Claude (resumen operativo)

1. **Antes de aceptar un campo nuevo de texto**: declarar categoría arriba (sub-issue al wiki si la tabla no estaba contemplada).
2. **Antes de mergear un controller que persiste texto**: confirmar que la FormRequest usa el trait `SanitizesInput` y declara el mapa `$sanitize`.
3. **Antes de mergear una página con formulario nuevo**: confirmar que usa `sanitizePlainText` + `maxLength` consistente con backend, o el primitive `<SanitizedInput>`.
4. **Antes de renderizar texto de usuario en un canal nuevo** (e.g. nuevo PDF, nueva integración externa): pasar por la capa de escape correspondiente al canal (`{{ }}`, ESC/POS strip, etc.).
5. **Prohibido** introducir librerías de WYSIWYG / HTML editor que persistan markup sin coordinar política aquí.

---

## Referencias

- Issue de origen: [HU #200 — Sanitización transversal de inputs](https://github.com/cristianmarint/flexyflow.restaurante/issues/200).
- `CLAUDE.md` (raíz) — sección "Sanitización de inputs".
- `application/app/Http/Middleware/SecurityHeaders.php` — base para CSP + headers complementarios.
- OWASP ASVS v4 §5.1 (validation), §5.2 (sanitization), §14.4 (HTTP security headers).
- OWASP Cheat Sheet — Cross-Site Scripting Prevention.
- Unicode Technical Report #36 — Security Considerations.
