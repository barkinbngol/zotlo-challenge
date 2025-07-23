<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'subscription_id',
        'zotlo_subscription_id',
        'status',
        'package_name',
        'expire_date',
    ];

    protected $casts = [
        'expire_date' => 'datetime',
    ];

    /**
     * boot metodu: subscription_id otomatik üretimi
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->subscription_id)) {
                $model->subscription_id = (string) Str::uuid();
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
