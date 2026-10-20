<?php

namespace ErnestDefoe\Cadence\Streak;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;

/**
 * Writes and reads a flame's journal. See the journal migration.
 */
class Journal
{
    public const MILESTONE = 'milestone';
    public const TIER = 'tier';
    public const RECORD = 'record';
    public const SAVED = 'saved';
    public const ENDED = 'ended';
    public const REVIVED = 'revived';
    public const GIVEN = 'given';
    public const EDITED = 'edited';
    public const RESET = 'reset';

    public function __construct(private ConnectionInterface $db)
    {
    }

    public function write(int $userId, string $type, int $value = 0, ?array $data = null, ?int $actorId = null, ?Carbon $at = null): void
    {
        $this->db->table('cadence_streak_journal')->insert([
            'user_id' => $userId,
            'type' => $type,
            'value' => max(0, $value),
            'data' => $data === null ? null : json_encode($data),
            'actor_id' => $actorId,
            'created_at' => ($at ?? Carbon::now('UTC'))->toDateTimeString(),
        ]);
    }

    /** @return array<int, array{type: string, value: int, data: mixed, at: string}> newest first */
    public function recent(int $userId, int $limit = 30): array
    {
        return $this->db->table('cadence_streak_journal')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit($limit)
            ->get(['type', 'value', 'data', 'created_at'])
            ->map(fn ($row) => [
                'type' => $row->type,
                'value' => (int) $row->value,
                'data' => $row->data ? json_decode($row->data, true) : null,
                'at' => Carbon::parse($row->created_at, 'UTC')->toIso8601String(),
            ])->all();
    }
}
