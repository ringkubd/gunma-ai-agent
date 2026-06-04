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
        'customer_id',
        'customer_name',
        'customer_email',
        'channel',
        'status',
        'is_ai_enabled',
        'metadata',
    ];

    protected $appends = [
        'resolved_name',
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

    /* ── Accessors ─────────────────────────────────────────────── */

    public function getResolvedNameAttribute(): ?string
    {
        if ($this->customer_name) {
            return $this->customer_name;
        }

        if ($this->customer_id) {
            $model = config('gunma-agent.models.customer');
            if ($model && class_exists($model)) {
                $customer = $model::find($this->customer_id);
                if ($customer) {
                    return $customer->name ?? $customer->first_name ?? null;
                }
            }
        }

        return null;
    }

    public function getResolvedEmailAttribute(): ?string
    {
        if ($this->customer_email) {
            return $this->customer_email;
        }

        if ($this->customer_id) {
            $model = config('gunma-agent.models.customer');
            if ($model && class_exists($model)) {
                $customer = $model::find($this->customer_id);
                if ($customer) {
                    return $customer->email ?? null;
                }
            }
        }

        return null;
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
