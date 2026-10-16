<?php

namespace Plugins\Glitter\SocialLogin\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SocialLoginExchange extends Model
{
    protected $table = 'glitter_social_login_exchanges';

    protected $fillable = [
        'code_hash',
        'user_id',
        'session_binding_hash',
        'redirect_path',
        'expires_at',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
