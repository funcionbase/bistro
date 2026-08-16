# Guía de Contribución

> Estado: Estable
> Owner: equipo de plataforma

---

## Filosofía

- **El código es la única fuente de verdad.** La documentación nunca debe inventar comportamiento; refleja lo que el código hace **hoy**.
- **Cada PR actualiza la wiki.** Si tu cambio modifica un endpoint, una pantalla, un permiso o una variable de entorno, la wiki debe reflejarlo en el mismo PR.
- **Commits pequeños y frecuentes.** Un commit por preocupación.

---

## Setup local

```bash
# clonar
git clone <repo>
cd bistro.restaurante/application

# dependencias
composer install
npm install

# entorno
cp .env.example .env
php artisan key:generate

# base de datos
php artisan migrate --seed

# desarrollo
composer run dev   # corre Laravel + Vite + queue listener en paralelo
```

Variables `.env` requeridas: ver [Variables de Entorno](Variables-de-Entorno.md).

---

## Workflow de Git

1. Crea rama desde `main` con prefijo de tipo: `feature/<slug>`, `fix/<slug>`, `chore/<slug>`.
2. Trabaja en commits pequeños con mensajes en presente y en español (alineado con la convención del repo).
3. Antes de abrir el PR:
   - Ejecuta `vendor/bin/pint --dirty --format agent` para PHP modificado.
   - Verifica que el frontend compile: `npm run build`.
   - Si modificaste docs públicas, actualiza la wiki en `docs/wiki/`.
4. Abre el PR contra `main` siguiendo el checklist (abajo).

**Reglas duras:**
- Nunca pasar `--no-verify`, `--no-gpg-sign` ni saltarse hooks.
- Nunca `git push --force` a `main`.
- Nunca commitear `.env`, credenciales o llaves.

---

## Checklist de PR

Cada PR debe llevar (idealmente en el `.github/PULL_REQUEST_TEMPLATE.md`, hoy se hace manualmente):

```markdown
## Resumen
<qué cambia y por qué>

## Tipo
- [ ] Feature
- [ ] Bug fix
- [ ] Refactor
- [ ] Docs

## Checklist
- [ ] `vendor/bin/pint --dirty --format agent` ejecutado
- [ ] `npm run build` corre sin errores
- [ ] Si toca endpoints/permisos: `BACKEND_FILES.md` actualizado
- [ ] Si toca pantallas/componentes: `FRONTEND_FILES.md` actualizado
- [ ] Si toca funcionalidades visibles: `FUNCIONALIDADES_APP.md` actualizado
- [ ] Si toca contratos públicos: la página correspondiente del wiki en `docs/wiki/` está actualizada
- [ ] Variables `.env` nuevas documentadas en `docs/wiki/Variables-de-Entorno.md` y `.env.example`
- [ ] Errores nuevos documentados en `docs/wiki/Errores-API.md`

## Cómo probar
<pasos manuales o comandos>

## Riesgos
<bloqueos, dependencias, rollbacks>
```

---

## Convenciones de código

### PHP

- PHP 8.2: constructor property promotion, types estrictos en parámetros y retorno.
- Llaves en todas las estructuras de control, incluso de una línea.
- TitleCase para llaves de Enum.
- Preferir PHPDoc a comentarios inline. Solo comentar el "por qué" no obvio.
- No introducir dependencias sin aprobación.

### TypeScript / React

- Hooks únicamente; no class components.
- Prop types explícitos. Sin `any` salvo justificación.
- Estilos: Tailwind v4 + variables CSS de `FRONTEND_UI_GUIDELINES.md`.
- Nombres de archivo: `kebab-case` para páginas y componentes (`pages/menu/index.tsx`, `components/menu/dish-card.tsx`).
- Hooks con prefijo `use-` (`use-token.ts`, `use-period-filter.ts`).

### Convenciones de PR para wiki

- Una página `.md` por dominio. Misma página = misma URL final en el wiki.
- Encabezado obligatorio: `Estado`, `Versión API`, `Owner`.
- Tablas para endpoints; bloques `http`/`json` para ejemplos.
- Sección "Notas de seguridad" en módulos sensibles.

---

## Publicación al wiki de GitHub

Hoy (manual):

```bash
# 1. clonar el wiki si es la primera vez
git clone git@github.com:<org>/<repo>.wiki.git wiki-remote

# 2. copiar el contenido de docs/wiki/ al clon
rsync -av --delete docs/wiki/ wiki-remote/

# 3. commit y push
cd wiki-remote
git add .
git commit -m "docs: sync wiki desde main"
git push origin master
```

Cuando `WIKI_AUTO_UPDATE=true`, un workflow de GitHub Actions hará lo mismo automáticamente tras cada merge a `main`.

---

## Definición de Hecho (DoD) por feature

- [ ] Código de la feature completo, formateado con Pint y compilado.
- [ ] Sin tests rotos (los existentes deben seguir pasando aunque no agregues nuevos).
- [ ] Endpoints documentados en la página de wiki correspondiente.
- [ ] Permisos nuevos agregados a la matriz en [Usuarios-Roles-Permisos](Usuarios-Roles-Permisos.md).
- [ ] Variables `.env` nuevas en [Variables de Entorno](Variables-de-Entorno.md) y `.env.example`.
- [ ] `FUNCIONALIDADES_APP.md`, `BACKEND_FILES.md`, `FRONTEND_FILES.md` actualizados según corresponda.
- [ ] Si la feature introduce un nuevo dominio: nueva página en `docs/wiki/` enlazada desde `Home.md`.
- [ ] PR con el checklist completo.
