<?php

namespace App\Http\Requests\Suppliers;

class StoreSupplierRequest extends BaseSupplierRequest
{
    protected function isUpdate(): bool
    {
        return false;
    }

    protected function existingId(): ?string
    {
        return null;
    }
}
