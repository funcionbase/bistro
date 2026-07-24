<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesJwtActor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\StoreNoteRequest;
use App\Http\Requests\Clients\StoreTagRequest;
use App\Models\Branch;
use App\Models\ClientNote;
use App\Models\ClientTag;
use App\Models\Contact;
use App\Rules\SafePlainText;
use App\Services\AuditService;
use App\Services\CrmService;
use App\Services\FeaturePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * CRM básico de clientes (#123 + refactor #235).
 *
 * Cada Contact ES un cliente. La identidad canónica desde #235 es
 * (company_nit, doc_number) cuando hay doc, complementada con phone (que
 * puede repetirse entre familiares). Las rutas usan `contacts.id` como
 * route key para evitar ambigüedad cuando varios clientes comparten phone.
 *
 * Permisos: clients.read / clients.create / clients.update / clients.delete.
 */
class ClientController extends Controller
{
    use ResolvesJwtActor;

    public function __construct(
        private readonly FeaturePermissionService $permissionService,
        private readonly CrmService $crmService,
        private readonly AuditService $auditService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'clients', 'read');

        $companyNit = (string) $request->attributes->get('active_company_nit');

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'segment' => ['nullable', 'string', 'max:32'],
            'tag' => ['nullable', 'string', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->crmService->listClients(
            companyNit: $companyNit,
            filters: [
                'search' => $validated['search'] ?? null,
                'segment' => $validated['segment'] ?? null,
                'tag' => $validated['tag'] ?? null,
            ],
            page: (int) ($validated['page'] ?? 1),
            perPage: (int) ($validated['per_page'] ?? 25),
        );

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'segments' => CrmService::SEGMENTS,
                'available_tags' => $this->crmService->availableTags($companyNit),
            ],
        ]);
    }

    /**
     * Registra manualmente un contacto (Contact) desde el CRM.
     *
     * `kind` declara si es persona natural o jurídica (empresa). El catálogo
     * doc_type se valida cruzado contra kind: NIT/NIT_EXT solo para empresas,
     * CC/CE/TI/PA/RC solo para naturales. La identidad canónica es
     * (company_nit, doc_number); el UNIQUE parcial en BD impide duplicados.
     *
     * Phone es opcional (empresa NIT sin móvil válido). Razón social se
     * captura por separado en empresas.
     *
     * Resolución de sede para `contacts.branch_id`:
     *  1. `active_branch_id` del JWT.
     *  2. Sede `is_default=true` activa.
     *  3. Si no hay sede activa → 422.
     */
    public function store(Request $request): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'clients', 'create');

        $companyNit = (string) $request->attributes->get('active_company_nit');

        $validated = $request->validate([
            'kind' => ['required', Rule::in([Contact::KIND_NATURAL, Contact::KIND_COMPANY])],
            'doc_type' => ['required', Rule::in([...Contact::NATURAL_DOC_TYPES, ...Contact::COMPANY_DOC_TYPES])],
            'doc_number' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9-]+$/i'],
            'dv' => ['nullable', 'string', 'size:1', 'regex:/^[0-9]$/'],
            'phone' => ['nullable', 'string', 'max:32'],
            'name' => ['required', new SafePlainText(maxBytes: 120, allowWhitespace: true)],
            'legal_name' => ['nullable', new SafePlainText(maxBytes: 160, allowWhitespace: true)],
            'email' => ['nullable', 'email', 'max:120'],
            'address' => ['nullable', new SafePlainText(maxBytes: 200, allowWhitespace: true)],
            'municipality_dane_code' => ['nullable', 'string', 'size:5', 'regex:/^[0-9]{5}$/'],
            'notes' => ['nullable', new SafePlainText(maxBytes: 1000, allowWhitespace: true)],
        ]);

        // Validación cruzada kind ↔ doc_type. Evita estados imposibles tipo
        // "persona natural con NIT" o "empresa con cédula de ciudadanía".
        $kind = $validated['kind'];
        $allowedDocs = $kind === Contact::KIND_COMPANY
            ? Contact::COMPANY_DOC_TYPES
            : Contact::NATURAL_DOC_TYPES;

        if (! in_array($validated['doc_type'], $allowedDocs, true)) {
            throw ValidationException::withMessages([
                'doc_type' => [$kind === Contact::KIND_COMPANY
                    ? 'Las empresas solo aceptan NIT o NIT extranjero.'
                    : 'Las personas naturales solo aceptan CC, CE, TI, PA o RC.',
                ],
            ]);
        }

        // Empresa exige razón social (legal_name) para la factura electrónica.
        if ($kind === Contact::KIND_COMPANY && empty(trim((string) ($validated['legal_name'] ?? '')))) {
            throw ValidationException::withMessages([
                'legal_name' => ['La razón social es obligatoria para empresas.'],
            ]);
        }

        // Phone opcional. Si viene, normalizamos al canónico 57XXXXXXXXXX.
        $phone = null;
        if (! empty($validated['phone'])) {
            $phone = CrmService::normalizePhone($validated['phone']);
            if (! preg_match('/^57\d{10}$/', $phone)) {
                throw ValidationException::withMessages([
                    'phone' => ['Ingresa un móvil colombiano de 10 dígitos que empiece por 3.'],
                ]);
            }
        }

        // Lookup cross-sede deliberado por doc_number (canónico). Si ya existe
        // un Contact con ese doc en esta empresa, no duplicamos.
        $existing = Contact::withoutBranchScope()
            ->where('company_nit', $companyNit)
            ->where('doc_number', $validated['doc_number'])
            ->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'doc_number' => ['Ya existe un cliente con ese número de documento en esta empresa.'],
            ]);
        }

        $branchId = $request->attributes->get('active_branch_id');
        if ($branchId === null) {
            $branchId = Branch::query()
                ->where('company_nit', $companyNit)
                ->whereNull('archived_at')
                ->orderByDesc('is_default')
                ->orderBy('created_at')
                ->value('id');
        }

        if ($branchId === null) {
            throw ValidationException::withMessages([
                'doc_number' => ['La empresa aún no tiene sedes activas. Crea una sede antes de registrar clientes.'],
            ]);
        }

        $actor = $this->actingUserOrFail($request);
        $name = trim($validated['name']);

        $contact = DB::transaction(function () use ($companyNit, $phone, $name, $kind, $validated, $branchId, $actor): Contact {
            $contact = new Contact;
            $contact->company_nit = $companyNit;
            $contact->phone = $phone;
            $contact->name = $name;
            $contact->kind = $kind;
            $contact->doc_type = $validated['doc_type'];
            $contact->doc_number = $validated['doc_number'];
            $contact->dv = $validated['dv'] ?? null;
            $contact->legal_name = $validated['legal_name'] ?? null;
            $contact->email = $validated['email'] ?? null;
            $contact->address = $validated['address'] ?? null;
            $contact->municipality_dane_code = $validated['municipality_dane_code'] ?? null;
            $contact->notes = $validated['notes'] ?? null;
            $contact->branch_id = $branchId;
            $contact->save();

            $this->auditService->log('client.created', $actor, $contact, [
                'contact_id' => $contact->id,
                'kind' => $contact->kind,
                'doc_type' => $contact->doc_type,
                'doc_number' => $contact->doc_number,
                'client_phone' => $contact->phone,
                'client_name' => $contact->name,
                'branch_id' => $branchId,
            ]);

            return $contact;
        });

        $this->crmService->forgetCache($companyNit, $contact->id);

        return response()->json([
            'data' => [
                'id' => $contact->id,
                'phone' => $contact->phone,
                'name' => $contact->name,
                'kind' => $contact->kind,
                'doc_type' => $contact->doc_type,
                'doc_number' => $contact->doc_number,
                'legal_name' => $contact->legal_name,
                'email' => $contact->email,
                'notes' => $contact->notes,
                'branch_id' => $contact->branch_id,
                'created_at' => $contact->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function show(Request $request, string $contact): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'clients', 'read');

        $companyNit = (string) $request->attributes->get('active_company_nit');

        $profile = $this->crmService->profile($companyNit, $contact);

        if ($profile === null) {
            abort(404, 'Cliente no encontrado.');
        }

        return response()->json(['data' => $profile]);
    }

    /**
     * Edita los datos de un contacto existente desde el CRM (#123). Mismas
     * reglas que `store` (identidad canónica company_nit+doc_number, validación
     * cruzada kind↔doc_type, razón social obligatoria en empresas), pero el
     * UNIQUE de doc_number excluye al propio contacto. `address` y
     * `municipality_dane_code` son `sometimes`: el diálogo del CRM no los envía,
     * así que su ausencia NO los borra (los edita el flujo DIAN aparte).
     */
    public function update(Request $request, string $contact): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'clients', 'update');

        $companyNit = (string) $request->attributes->get('active_company_nit');
        $contactModel = $this->loadContactOrFail($companyNit, $contact);

        $validated = $request->validate([
            'kind' => ['required', Rule::in([Contact::KIND_NATURAL, Contact::KIND_COMPANY])],
            'doc_type' => ['required', Rule::in([...Contact::NATURAL_DOC_TYPES, ...Contact::COMPANY_DOC_TYPES])],
            'doc_number' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9-]+$/i'],
            'dv' => ['nullable', 'string', 'size:1', 'regex:/^[0-9]$/'],
            'phone' => ['nullable', 'string', 'max:32'],
            'name' => ['required', new SafePlainText(maxBytes: 120, allowWhitespace: true)],
            'legal_name' => ['nullable', new SafePlainText(maxBytes: 160, allowWhitespace: true)],
            'email' => ['nullable', 'email', 'max:120'],
            'address' => ['sometimes', 'nullable', new SafePlainText(maxBytes: 200, allowWhitespace: true)],
            'municipality_dane_code' => ['sometimes', 'nullable', 'string', 'size:5', 'regex:/^[0-9]{5}$/'],
            'notes' => ['nullable', new SafePlainText(maxBytes: 1000, allowWhitespace: true)],
        ]);

        $kind = $validated['kind'];
        $allowedDocs = $kind === Contact::KIND_COMPANY
            ? Contact::COMPANY_DOC_TYPES
            : Contact::NATURAL_DOC_TYPES;

        if (! in_array($validated['doc_type'], $allowedDocs, true)) {
            throw ValidationException::withMessages([
                'doc_type' => [$kind === Contact::KIND_COMPANY
                    ? 'Las empresas solo aceptan NIT o NIT extranjero.'
                    : 'Las personas naturales solo aceptan CC, CE, TI, PA o RC.',
                ],
            ]);
        }

        if ($kind === Contact::KIND_COMPANY && empty(trim((string) ($validated['legal_name'] ?? '')))) {
            throw ValidationException::withMessages([
                'legal_name' => ['La razón social es obligatoria para empresas.'],
            ]);
        }

        $phone = null;
        if (! empty($validated['phone'])) {
            $phone = CrmService::normalizePhone($validated['phone']);
            if (! preg_match('/^57\d{10}$/', $phone)) {
                throw ValidationException::withMessages([
                    'phone' => ['Ingresa un móvil colombiano de 10 dígitos que empiece por 3.'],
                ]);
            }
        }

        // UNIQUE de identidad excluyendo al propio contacto: no puede chocar con
        // OTRO cliente de la misma empresa, pero sí conservar su propio doc.
        $dupe = Contact::withoutBranchScope()
            ->where('company_nit', $companyNit)
            ->where('doc_number', $validated['doc_number'])
            ->where('id', '!=', $contactModel->id)
            ->first();

        if ($dupe !== null) {
            throw ValidationException::withMessages([
                'doc_number' => ['Ya existe otro cliente con ese número de documento en esta empresa.'],
            ]);
        }

        $actor = $this->actingUserOrFail($request);
        $name = trim($validated['name']);

        DB::transaction(function () use ($contactModel, $phone, $name, $kind, $validated, $actor): void {
            $contactModel->kind = $kind;
            $contactModel->doc_type = $validated['doc_type'];
            $contactModel->doc_number = $validated['doc_number'];
            $contactModel->dv = $validated['dv'] ?? null;
            $contactModel->phone = $phone;
            $contactModel->name = $name;
            $contactModel->legal_name = $validated['legal_name'] ?? null;
            $contactModel->email = $validated['email'] ?? null;
            $contactModel->notes = $validated['notes'] ?? null;

            // sometimes: solo se tocan si el request los trae (el CRM no).
            if (array_key_exists('address', $validated)) {
                $contactModel->address = $validated['address'];
            }
            if (array_key_exists('municipality_dane_code', $validated)) {
                $contactModel->municipality_dane_code = $validated['municipality_dane_code'];
            }

            $contactModel->save();

            $this->auditService->log('client.updated', $actor, $contactModel, [
                'contact_id' => $contactModel->id,
                'kind' => $contactModel->kind,
                'doc_type' => $contactModel->doc_type,
                'doc_number' => $contactModel->doc_number,
                'client_phone' => $contactModel->phone,
                'client_name' => $contactModel->name,
            ]);
        });

        $this->crmService->forgetCache($companyNit, $contactModel->id);

        return response()->json(['data' => $this->crmService->profile($companyNit, $contactModel->id)]);
    }

    public function storeNote(StoreNoteRequest $request, string $contact): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'clients', 'update');

        $companyNit = (string) $request->attributes->get('active_company_nit');
        $contactModel = $this->loadContactOrFail($companyNit, $contact);

        $actor = $this->actingUserOrFail($request);

        $note = DB::transaction(function () use ($companyNit, $contactModel, $request, $actor) {
            $note = ClientNote::create([
                'company_nit' => $companyNit,
                'contact_id' => $contactModel->id,
                'client_phone' => $contactModel->phone,
                'note' => $request->string('note')->toString(),
                'created_by' => $actor->id,
            ]);

            $this->auditService->log('client.note_created', $actor, $note, [
                'contact_id' => $contactModel->id,
                'client_phone' => $contactModel->phone,
                'note_id' => $note->id,
            ]);

            return $note;
        });

        $this->crmService->forgetCache($companyNit, $contactModel->id);

        return response()->json([
            'data' => [
                'id' => $note->id,
                'note' => $note->note,
                'created_at' => $note->created_at?->toIso8601String(),
                'author' => ['id' => $actor->id, 'name' => $actor->name],
            ],
        ], 201);
    }

    public function destroyNote(Request $request, string $contact, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'clients', 'delete');

        $companyNit = (string) $request->attributes->get('active_company_nit');
        $contactModel = $this->loadContactOrFail($companyNit, $contact);

        $note = ClientNote::forContact($companyNit, $contactModel->id)->findOrFail($id);
        $actor = $this->actingUserOrFail($request);

        DB::transaction(function () use ($note, $actor, $contactModel): void {
            $this->auditService->log('client.note_deleted', $actor, $note, [
                'contact_id' => $contactModel->id,
                'client_phone' => $contactModel->phone,
                'note_id' => $note->id,
                'note_excerpt' => mb_substr($note->note, 0, 200),
            ]);
            $note->delete();
        });

        $this->crmService->forgetCache($companyNit, $contactModel->id);

        return response()->json(null, 204);
    }

    public function storeTag(StoreTagRequest $request, string $contact): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'clients', 'update');

        $companyNit = (string) $request->attributes->get('active_company_nit');
        $contactModel = $this->loadContactOrFail($companyNit, $contact);

        $actor = $this->actingUserOrFail($request);

        $tag = DB::transaction(function () use ($companyNit, $contactModel, $request, $actor) {
            $tag = ClientTag::firstOrCreate(
                [
                    'company_nit' => $companyNit,
                    'contact_id' => $contactModel->id,
                    'tag' => $request->string('tag')->toString(),
                ],
                [
                    'client_phone' => $contactModel->phone,
                    'created_by' => $actor->id,
                ]
            );

            if ($tag->wasRecentlyCreated) {
                $this->auditService->log('client.tag_added', $actor, $tag, [
                    'contact_id' => $contactModel->id,
                    'client_phone' => $contactModel->phone,
                    'tag' => $tag->tag,
                ]);
            }

            return $tag;
        });

        $this->crmService->forgetCache($companyNit, $contactModel->id);

        return response()->json([
            'data' => [
                'id' => $tag->id,
                'tag' => $tag->tag,
            ],
        ], $tag->wasRecentlyCreated ? 201 : 200);
    }

    public function destroyTag(Request $request, string $contact, string $id): JsonResponse
    {
        $this->permissionService->assertPermission($request, 'clients', 'delete');

        $companyNit = (string) $request->attributes->get('active_company_nit');
        $contactModel = $this->loadContactOrFail($companyNit, $contact);

        $tag = ClientTag::forContact($companyNit, $contactModel->id)->findOrFail($id);
        $actor = $this->actingUserOrFail($request);

        DB::transaction(function () use ($tag, $actor, $contactModel): void {
            $this->auditService->log('client.tag_removed', $actor, $tag, [
                'contact_id' => $contactModel->id,
                'client_phone' => $contactModel->phone,
                'tag' => $tag->tag,
            ]);
            $tag->delete();
        });

        $this->crmService->forgetCache($companyNit, $contactModel->id);

        return response()->json(null, 204);
    }

    /**
     * Carga el Contact validando que pertenezca a la empresa activa. Aborta
     * 404 si no existe o pertenece a otra empresa (no leak cross-tenant).
     */
    private function loadContactOrFail(string $companyNit, string $contactId): Contact
    {
        $contact = Contact::withoutBranchScope()
            ->where('company_nit', $companyNit)
            ->where('id', $contactId)
            ->first();

        if ($contact === null) {
            abort(404, 'Cliente no encontrado.');
        }

        return $contact;
    }
}
