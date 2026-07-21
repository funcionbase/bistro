# Sanitización de inputs

> REGLA OBLIGATORIA. Consultar antes de tocar cualquier campo de texto de usuario (forms, persistencia, render).

**SIEMPRE sanea en AMBOS lados** — frontend Y backend. Ningún PR que toque texto del usuario se mergea sin tocar ambos. Política completa: `docs/wiki/SECURITY_INPUT_HANDLING.md`.

**Principio**: saneamos en persistencia, escapamos en render. Cero campos de usuario admiten HTML enriquecido. Única excepción: `legal_documents.content` (markdown trusted, sanitizado en render con `rehype-sanitize`).

**Checklist mínimo (sin esto NO se mergea)**:

*Backend*:
- [ ] FormRequest dedicada (cero `$request->validate(...)` inline en controllers para texto libre).
- [ ] Trait `App\Http\Requests\Concerns\SanitizesInput` + mapa `protected array $sanitize = ['campo' => 'categoría']`. Categorías: `plain_text_short`, `plain_text_long`, `markdown_trusted`, `identifier`, `json_payload`.
- [ ] Rule `new SafePlainText(maxBytes: N, allowWhitespace: bool)` en cada campo. `max:` en bytes (no chars) para que emojis no rompan la columna.
- [ ] Si la FormRequest ya tiene `prepareForValidation`, llamar `$this->sanitizeMappedFields()` al inicio.
- [ ] Middleware `NormalizeStrings` (solo NFC) registrado en `bootstrap/app.php`. El strip de control characters NO es global: lo hace cada FormRequest por-campo vía `SafePlainText`/`NoControlCharacters` y la categoría del trait `SanitizesInput`. NO desactivar salvo webhooks (whitelist: `/api/v1/webhooks/whatsapp`, `/api/v1/csp-report`).

*Frontend*:
- [ ] `onChange` (input/textarea) aplica `sanitizePlainText` de `resources/js/lib/input-sanitize.ts`, o usar `<SanitizedInput>` (primitive del DS).
- [ ] `maxLength` del elemento = `maxBytes` exacto del backend. Sin excepción.
- [ ] Si la feature tiene schema en `lib/schemas/<feature>.ts`, componerlo en `useForm`.

*Render*:
- [ ] Cero `{!! !!}` en Blade con texto de usuario. Cero `dangerouslySetInnerHTML` en JSX. Markdown solo vía `components/ui/markdown.tsx`.
- [ ] PDF/comanda térmica/email: `e()`/`{{ }}` + escape específico (ESC/POS strip de `\x1B`/`\x1D`).

**Owner NO es excepción**: la sanitización es independiente del rol. Owner que pegue `<script>` también se filtra.

**Si introduces una columna nueva de texto libre**: actualizá la tabla "Inventario de columnas críticas" en `SECURITY_INPUT_HANDLING.md` en el mismo PR o abre sub-issue.

**Anti-patrones prohibidos**:
- ❌ Editor WYSIWYG (Quill, TipTap, Trix) que persista markup sin coordinar.
- ❌ `htmlspecialchars`/`htmlentities`/`e()` en persistencia — escape va en render; `strip_tags` para guardar.
- ❌ `$request->validate(['campo' => 'string|max:255'])` inline.
- ❌ Sanitizar solo en frontend ("ya lo limpia el cliente"). Cliente es zona hostil.
- ❌ Sanitizar solo en backend ("server ya valida"). Sin saneo cliente, payload queda en estado React, preview, retry.
- ❌ Mismatch de `maxLength` entre frontend y backend.
