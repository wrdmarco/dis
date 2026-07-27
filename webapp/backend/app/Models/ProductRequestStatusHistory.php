<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProductRequestStatusHistory extends Model
{
    use UsesUlids;

    protected $fillable = [
        'product_request_id',
        'from_status',
        'to_status',
        'note',
        'changed_by',
        'changed_by_name_snapshot',
    ];

    public function productRequest(): BelongsTo
    {
        return $this->belongsTo(ProductRequest::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
