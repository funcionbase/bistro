<?php

namespace App\Http\Requests\Suppliers;

class UpdateSupplierRequest extends BaseSupplierRequest
{
    protected function isUpdate(): bool
    {
        return true;
    }

    protected function existingId(): ?string
    {
        // El id de suppliers es UUID (HasUuids). Castearlo a (int) rompía el
        // unique...ignore con "invalid input syntax for type uuid".
        return (string) $this->route('id');
    }
}
