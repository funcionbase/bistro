<?php

declare(strict_types=1);

/**
 * URLs de los documentos legales que enlaza el flujo de enrollment.
 *
 * Términos y privacidad viven en el sitio institucional (flexyflow.co) —
 * mismos links que footer/consent-banner (`app-footer-meta.tsx`), fijos por
 * dominio, no varían por ambiente.
 *
 * El contrato de servicio vive dentro del propio SPA (`/legal/contract`),
 * por eso solo guardamos el path relativo: BootstrapService lo resuelve
 * contra `app.frontend_url`, que sí varía por ambiente (dev/qa/pdn).
 */
return [
    'terms' => 'https://flexyflow.co/terms-conditions/',
    'privacy' => 'https://flexyflow.co/privacy-policy/',
    'contract_path' => '/legal/contract',
];
