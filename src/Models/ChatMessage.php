<?php

declare(strict_types=1);

namespace Anwar\GunmaAgent\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    use HasUuids;

    protected $table = 'chat_messages';

    protected $fillable = [
        'session_id',
        'role',
        'content',
        'tool_calls',
        'tool_call_id',
        'tokens_used',
        'model',
        'metadata',
    ];

    protected $casts = [
        'tool_calls'  => 'array',
        'metadata'    => 'array',
        'tokens_used' => 'integer',
    ];

    /* ── Relationships ─────────────────────────────────────────── */

    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'session_id');
    }

    /* ── Scopes ────────────────────────────────────────────────── */

    public function scopeByRole($query, string $role)
    {
        return $query->where('role', $role);
    }

    /* ── Helpers ───────────────────────────────────────────────── */

    /**
     * Convert to OpenAI message format for context window.
     */
    public function toAiFormat(): array
    {
        $message = [
            'role'    => $this->role,
            'content' => $this->content,
        ];

        if ($this->tool_calls) {
            $message['tool_calls'] = $this->tool_calls;
        }

        if ($this->tool_call_id) {
            $message['tool_call_id'] = $this->tool_call_id;
        }

        return $message;
    }
}
