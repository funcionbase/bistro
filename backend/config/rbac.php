<?php

declare(strict_types=1);

/**
 * Fuente única de verdad para presentación del modelo RBAC.
 *
 * Las columnas de la matriz de permisos (`can_create | can_read | can_update |
 * can_delete`) viven hard-coded en migraciones, services y policies (donde
 * deben). Este config NO controla la semántica — la BD es la fuente real.
 * Lo único que centraliza es la **lista de acciones expuesta al frontend**
 * con sus labels en es-CO, para que `permissions-matrix.tsx` deje de
 * declarar el mismo array a mano.
 *
 * Si se agrega una columna nueva al modelo permissions (poco frecuente),
 * añadir el slug acá Y a la migración del modelo. Si solo cambia el label,
 * editar acá.
 */
return [

    'actions' => [
        ['key' => 'can_create', 'label' => 'Crear'],
        ['key' => 'can_read', 'label' => 'Leer'],
        ['key' => 'can_update', 'label' => 'Actualizar'],
        ['key' => 'can_delete', 'label' => 'Eliminar'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit (consumido por `php artisan rbac:audit` — HU #215 Fase 1)
    |--------------------------------------------------------------------------
    |
    | Configura el comando que cruza `routes/api.php` contra el catálogo de
    | permisos del FeatureSeeder y reporta rutas mutadoras sin middleware
    | `permission:<slug>,<action>`.
    |
    | Política:
    |   - Toda ruta con verbo mutador (POST/PUT/PATCH/DELETE) DEBE llevar el
    |     middleware `permission:<slug>,<action>` correspondiente.
    |   - Excepciones legítimas se enumeran en `audit.public_routes` con razón
    |     o se cubren via `audit.bypass_middlewares` (auth paralela: bot.jwt,
    |     table.guest).
    |   - Si una ruta no entra en ninguna excepción y no tiene `permission:`,
    |     se reporta como BRECHA. En CI, `--fail-on-gap` aborta el job.
    */
    'audit' => [

        // URIs (formato Laravel — sin `api/` prefix automático, se da
        // completo incluyendo `api/v1/...`) que son legítimamente públicas.
        // Cada entry trae la razón por la que vive aquí. Si la razón no es
        // claramente defensiva (auth, webhook firmado, guest público con
        // scoping propio), la review va a empujar a meter `permission:` en
        // su lugar.
        'public_routes' => [

            // --- Webhooks externos firmados ------------------------------
            'api/v1/webhooks/whatsapp' => 'WhatsApp Cloud API — autenticidad por X-Hub-Signature-256 + verify token.',
            'api/v1/csp-report' => 'Content-Security-Policy violation reports — disparados por el browser. Throttle 60/min por IP.',

            // --- Tokens únicos por correo --------------------------------
            'api/v1/whatsapp/verification/reject' => 'Botón "No fui yo" del correo de verificación WhatsApp. Token único + throttle 10/min.',

            // --- Endpoints públicos del cliente final --------------------
            // Sin JWT de usuario. Cada uno tiene throttle y rate-limit dedicado
            // en el routing. La autorización efectiva vive en el scope del
            // recurso (NIT de empresa, NIT + phone, etc.), no en RBAC.
            'api/v1/public/menu/{nit}/scan' => 'Telemetría pública del QR del menú (#95). Append-only, throttle:menu-scan-public.',
            'api/v1/public/loyalty/{nit}/lookup' => 'Consulta pública de puntos de fidelidad por NIT + phone. Throttle:loyalty-public.',
            'api/v1/public/loyalty/{nit}/redeem' => 'Redención pública de puntos por NIT + phone + code. Throttle:loyalty-public.',

            // --- Carrito en construcción del cliente final ---------------
            // Auth por CartJwtService (JWT propio del carrito), no por JWT
            // de usuario. El JWT del carrito firma `cart_id` + `company_nit`
            // y se valida en el controller. Allow-list porque el usuario
            // dueño del carrito no tiene `company_role_id`.
            'api/v1/cart/migrate-jwt/{jwt}' => 'Migración del JWT del carrito al loguearse el cliente. Auth por cart JWT propio.',

            // --- Cart con cupones operados por staff ---------------------
            // Endpoints POST que NO mutan estado: leen información del
            // cupón aplicable al carrito actual. Cualquier staff
            // autenticado (jwt + company.access) puede invocarlos para
            // construir un carrito. No exponen catálogo administrativo
            // (eso vive en `coupons.read`).
            'api/v1/cart/active-auto-apply' => 'Lectura de cupones auto-aplicables al carrito en construcción (#125 happy hour). Read-only.',
            'api/v1/cart/apply-coupon' => 'Validación de un código de cupón contra el carrito. Read-only — no muta cart_coupons.',

            // --- Chat: reasignación entre sedes --------------------------
            // Autorización composable que vive en `ChatController::reassignBranch`:
            //   1. Owner bypass (role.is_system = true).
            //   2. Permiso `chats.reassign_branch` en el JWT payload
            //      (slug ya en `FeatureSeeder`, owner-only por default,
            //      asignable a otros roles via UserPermissionsEditor).
            //   3. Acceso a la sede destino vía `branch_users` (no
            //      derivable solo del permiso — composable).
            // No se usa middleware `permission:` porque la regla (2 AND 3)
            // requiere lógica que vive en el controller.
            'api/v1/chats/{id}/reassign-branch' => 'Auth compuesta en controller (chats.reassign_branch + acceso a sede destino).',

            // --- Self-service del usuario autenticado --------------------
            // El usuario autenticado por JWT puede borrar su propia cuenta
            // sin permiso RBAC: no hay slug `account.delete` porque el
            // dueño del recurso es él mismo.
            'api/v1/me' => 'DELETE de la propia cuenta del usuario autenticado. Authorization: el dueño.',

            // --- Auth + onboarding (usuario aún sin rol asignado) --------
            // Estas rutas las consume un JWT que aún no tiene
            // `company_role_id` (acaba de loguearse, está creando su
            // empresa, o está cambiando de empresa). No aplican
            // `permission:` porque el rol todavía no existe en contexto.
            'api/v1/enrollment/user' => 'Crear el registro de usuario tras Google OAuth. JWT sin company aún.',
            'api/v1/enrollment/company' => 'Crear empresa (onboarding owner). JWT sin company aún.',
            'api/v1/enrollment/invited' => 'Aceptar invitación a empresa existente. JWT sin company aún.',
            'api/v1/auth/select-company' => 'Primera selección de empresa tras login. Cambia el context, no muta datos.',
            'api/v1/auth/switch-company' => 'Cambiar de empresa activa. Cambia el context, no muta datos.',
            'api/v1/auth/switch-branch' => 'Cambiar de sede activa. Cambia el context, no muta datos.',
            'api/v1/auth/logout' => 'Logout — invalida el JWT actual.',
        ],

        // Middlewares cuya presencia eximen a la ruta del chequeo `permission:`.
        // Son mecanismos de auth/autorización paralelos al RBAC de usuario.
        'bypass_middlewares' => [
            // Bot interno (KDS, etc.) — JWT propio firmado por el backend,
            // scoping por bot_id. No tiene `company_role_id` en contexto.
            'bot.jwt',

            // Sesión de mesa con QR — `ResolveTableGuest` inyecta sesión
            // guest. Authorization vive en la sesión (`table_session_id`),
            // no en RBAC de usuario.
            'table.guest',
        ],

        // Verbos HTTP considerados mutadores. GET/HEAD/OPTIONS quedan fuera
        // del chequeo por default — la lectura sin permiso es aceptable
        // salvo decisión explícita.
        'mutator_verbs' => ['POST', 'PUT', 'PATCH', 'DELETE'],

        // Acciones válidas del middleware `permission:`. Refleja
        // `EnsureFeaturePermission::VALID_ACTIONS`. Si una ruta declara
        // `permission:slug,foo` con `foo` fuera de esta lista, el audit lo
        // marca como configuración inválida (en runtime daría 500).
        'valid_actions' => ['read', 'create', 'update', 'delete'],

    ],
];
