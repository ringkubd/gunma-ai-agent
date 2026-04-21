<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatSession extends Model
{
    use HasUuids;

    protected $table = 'chat_sessions';

    protected $fillable = [
        'visitor_id',
        'customer_name',
        'channel',
        'status',
        'is_ai_enabled',
        'metadata',
    ];

    protected $casts = [
        'is_ai_enabled' => 'boolean',
        'metadata'      => 'array',
    ];

    /* ── Relationships ─────────────────────────────────────────── */

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'session_id')->orderBy('created_at');
    }

    /* ── Scopes ────────────────────────────────────────────────── */

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByVisitor($query, string $visitorId)
    {
        return $query->where('visitor_id', $visitorId);
    }

    public function scopeByChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    /* ── Helpers ───────────────────────────────────────────────── */

    public function end(): void
    {
        $this->update(['status' => 'ended']);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
