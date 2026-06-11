<?php

declare(strict_types=1);

/**
 * URLs externas de los documentos legales (TOS, privacidad, contrato).
 *
 * Los documentos ya no viven en BD ni en este repo: ahora están publicados en
 * el wiki externo (`<wiki_base_url>/wiki/restaurante/legal/...`). Esta config
 * resuelve la URL absoluta por ambiente para que el frontend pueda enlazarlas
 * desde el flujo de enrollment (las abre en una pestaña nueva).
 *
 * Cambios de contenido se hacen en el wiki externo; el versionado es interno
 * a esa publicación (git history del wiki). En `user_acceptances` ya no se
 * guarda snapshot — la evidencia es `accepted_at` + URL pública del documento.
 */
return [

    // Base del wiki publicado por ambiente.
    //   dev/local → http://localhost:4321
    //   qa/pdn    → https://flexyflow.co
    'wiki_base_url' => env('LEGAL_WIKI_BASE_URL', 'https://flexyflow.co'),

    // Path relativo de cada documento dentro del wiki. La URL absoluta se
    // compone como `wiki_base_url . paths[type]`. Slug fijo: no varía por
    // ambiente, solo el host.
    'paths' => [
        'terms' => '/wiki/restaurante/legal/terminos/',
        'privacy' => '/wiki/restaurante/legal/privacidad/',
        'contract' => '/wiki/restaurante/legal/contrato/',
    ],
];
