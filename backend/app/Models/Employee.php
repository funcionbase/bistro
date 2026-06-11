<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Perfil de colaborador (HHRR). Vive separado de users porque puede existir
 * sin acceso al sistema (cocinero/limpieza). Cuando el colaborador tiene
 * cuenta, user_id queda poblado y se sincronizan estados (active/inactive).
 *
 * Conservación DIAN: archivar (archived_at) nunca borrar — mínimo 10 años
 * para personas jurídicas. Toda mutación financiera (pay_rate, base_salary)
 * pasa por DB::transaction + AuditService::log.
 *
 * @property string $id
 * @property string $company_nit
 * @property ?int $user_id
 * @property string $primary_branch_id
 * @property ?string $position_id
 * @property string $doc_type
 * @property string $doc_number
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property ?string $phone
 * @property ?Carbon $birth_date
 * @property ?string $blood_type
 * @property ?string $contract_type
 * @property ?string $base_salary
 * @property string $pay_type
 * @property string $pay_rate
 * @property string $vinculation_status
 * @property ?Carbon $vinculation_valid_from
 * @property ?Carbon $vinculation_valid_until
 * @property ?int $min_days_off_override
 * @property ?Carbon $archived_at
 */
class Employee extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'company_nit',
        'user_id',
        'primary_branch_id',
        'position_id',
        'doc_type',
        'doc_number',
        'first_name',
        'last_name',
        'email',
        'phone',
        'birth_date',
        'blood_type',
        'address',
        'city',
        'eps',
        'arl',
        'pension_fund',
        'severance_fund',
        'bank',
        'account_type',
        'account_number',
        'emergency_contact_name',
        'emergency_contact_phone',
        'uniform_size',
        'contract_type',
        'base_salary',
        'pay_type',
        'pay_rate',
        'hire_date',
        'vinculation_status',
        'vinculation_valid_from',
        'vinculation_valid_until',
        'min_days_off_override',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'hire_date' => 'date',
            'vinculation_valid_from' => 'date',
            'vinculation_valid_until' => 'date',
            'archived_at' => 'datetime',
            'base_salary' => 'decimal:2',
            'pay_rate' => 'decimal:2',
            'min_days_off_override' => 'integer',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_nit', 'nit');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function primaryBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'primary_branch_id');
    }

    /** @return BelongsTo<EmployeePosition, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(EmployeePosition::class, 'position_id');
    }

    /** @return BelongsToMany<Branch, $this> */
    public function extraBranches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'employees_branches', 'employee_id', 'branch_id')
            ->withTimestamps();
    }

    /** @return HasMany<EmployeeShift, $this> */
    public function shifts(): HasMany
    {
        return $this->hasMany(EmployeeShift::class);
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @param  Builder<Employee>  $query */
    public function scopeActive($query): void
    {
        $query->whereNull('archived_at');
    }

    /** @param  Builder<Employee>  $query */
    public function scopeForCompany($query, string $companyNit): void
    {
        $query->where('company_nit', $companyNit);
    }
}
