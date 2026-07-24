<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Municipio DANE (catálogo nacional, poblado desde el CSV en la migración).
 * Respalda el selector "Ciudad, Departamento" del formulario de dirección.
 *
 * `dane_code` (5) es la PK y el mismo código del perfil fiscal DIAN
 * (`contacts.municipality_dane_code`), así el selector produce un código válido
 * para FEV sin mapeos.
 *
 * @property string $dane_code
 * @property string $city
 * @property string $department_code
 * @property string $department
 */
class Municipality extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'dane_code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['dane_code', 'city', 'department_code', 'department'];

    /** "Ciudad, Departamento" — la etiqueta legible del selector. */
    public function label(): string
    {
        return $this->city.', '.$this->department;
    }

    /** Busca por ciudad o departamento (case-insensitive). */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $query->where(function (Builder $q) use ($like): void {
            $q->where('city', 'ILIKE', $like)->orWhere('department', 'ILIKE', $like);
        });
    }
}
