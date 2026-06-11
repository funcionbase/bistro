<?php

namespace App\Policies;

/**
 * Política vacía; la lógica de autorización se define en Gate::define('manage-company-roles') dentro de AppServiceProvider.
 *
 * Se mantiene el archivo para que el Gate discovery de Laravel no rompa si se registra via Gate::policy().
 */
// Gate logic moved to Gate::define('manage-company-roles') in AppServiceProvider.
class CompanyRolePolicy {}
