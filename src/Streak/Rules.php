<?php

namespace ErnestDefoe\Cadence\Streak;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Every admin-tunable rule a flame follows, read once per request.
 *
 * 🚨 Settings come back as strings, and '0' is a true string. Every value here
 * is cast on the way out so nothing downstream ever has to remember that.
 */
class Rules
{
    public const PREFIX = 'ernestdefoe-cadence.';

    public const DEFAULT_TIERS = [
        ['key' => 'spark', 'name' => '', 'days' => 1, 'color' => '#f97316', 'icon' => 'fas fa-fire', 'glow' => false, 'enabled' => true],
        ['key' => 'kindled', 'name' => '', 'days' => 7, 'color' => '#ef4444', 'icon' => 'fas fa-fire', 'glow' => false, 'enabled' => true],
        ['key' => 'blaze', 'name' => '', 'days' => 30, 'color' => '#3b82f6', 'icon' => 'fas fa-fire-flame-curved', 'glow' => false, 'enabled' => true],
        ['key' => 'inferno', 'name' => '', 'days' => 100, 'color' => '#a855f7', 'icon' => 'fas fa-fire-flame-curved', 'glow' => true, 'enabled' => true],
        ['key' => 'eternal', 'name' => '', 'days' => 365, 'color' => '#f59e0b', 'icon' => 'fas fa-crown', 'glow' => true, 'enabled' => true],
    ];

    public const DEFAULT_MILESTONES = '3,7,14,30,50,100,150,200,250,365,500,730,1000';

    private array $memo = [];

    public function __construct(private SettingsRepositoryInterface $settings)
    {
    }

    public function enabled(): bool
    {
        return $this->bool('streaks', true);
    }

    /** 'contribute' (default) or 'visit'. */
    public function mode(): string
    {
        return $this->get('streak_mode') === 'visit' ? 'visit' : 'contribute';
    }

    public function grace(): int
    {
        return $this->int('streak_grace', 0, 0, 3);
    }

    /** @return int[] ascending, unique, positive */
    public function milestones(): array
    {
        return $this->memo['milestones'] ??= self::numbers($this->get('streak_milestones') ?? self::DEFAULT_MILESTONES);
    }

    public function freezesPerMilestone(): int
    {
        return $this->int('streak_freeze_per_milestone', 1, 0, 5);
    }

    public function freezeBank(): int
    {
        return $this->int('streak_freeze_bank', 5, 0, 20);
    }

    /** The shortest flame that leaves embers when it goes out; 0 turns revival off. */
    public function embersMin(): int
    {
        return $this->int('streak_embers_min', 14, 0, 10000);
    }

    public function embersHours(): int
    {
        return $this->int('streak_embers_hours', 48, 1, 168);
    }

    /** Percent of the old flame a revival brings back. */
    public function embersShare(): int
    {
        return $this->int('streak_embers_share', 50, 1, 100);
    }

    public function hallMin(): int
    {
        return $this->int('streak_hall_min', 7, 1, 10000);
    }

    public function riskMin(): int
    {
        return $this->int('streak_risk_min', 3, 1, 10000);
    }

    /** Local hour from which a member who has not fed today is reminded. */
    public function riskHour(): int
    {
        return $this->int('streak_risk_hour', 20, 0, 23);
    }

    /** @return int[] */
    public function bonfireLevels(): array
    {
        return $this->memo['bonfire'] ??= self::numbers($this->get('streak_bonfire_levels') ?? '10,25,50,100');
    }

    /** Used for members whose browser has not told us theirs yet. */
    public function defaultTimezone(): string
    {
        return self::validTimezone($this->get('streak_timezone')) ?? 'UTC';
    }

    /** @return array<int, array{key: string, name: string, days: int, color: string, icon: string, glow: bool, enabled: bool}> */
    public function tiers(): array
    {
        if (isset($this->memo['tiers'])) {
            return $this->memo['tiers'];
        }

        $raw = json_decode((string) $this->get('streak_tiers'), true);
        $tiers = [];

        foreach (is_array($raw) && $raw ? $raw : self::DEFAULT_TIERS as $i => $tier) {
            if (! is_array($tier)) {
                continue;
            }

            $tiers[] = [
                'key' => preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($tier['key'] ?? 'tier'.$i))) ?: 'tier'.$i,
                'name' => mb_substr(trim((string) ($tier['name'] ?? '')), 0, 40),
                'days' => max(1, (int) ($tier['days'] ?? 1)),
                // 🚨 Validated, because this lands in a style attribute.
                'color' => preg_match('/^#[0-9a-f]{3,8}$/i', (string) ($tier['color'] ?? '')) ? $tier['color'] : '#f97316',
                'icon' => preg_match('/^[a-z0-9 -]{1,60}$/i', (string) ($tier['icon'] ?? '')) ? $tier['icon'] : 'fas fa-fire',
                'glow' => (bool) ($tier['glow'] ?? false),
                'enabled' => (bool) ($tier['enabled'] ?? true),
            ];
        }

        usort($tiers, fn ($a, $b) => $a['days'] <=> $b['days']);

        return $this->memo['tiers'] = $tiers;
    }

    /** The highest enabled tier a streak of this length reaches, or null. */
    public function tierFor(int $days): ?array
    {
        $found = null;

        foreach ($this->tiers() as $tier) {
            if ($tier['enabled'] && $days >= $tier['days']) {
                $found = $tier;
            }
        }

        return $found;
    }

    /** @return array<int, array{name: string, from: string, to: string, color: string, icon: string}> */
    public function seasons(): array
    {
        if (isset($this->memo['seasons'])) {
            return $this->memo['seasons'];
        }

        $raw = json_decode((string) $this->get('streak_seasons'), true);
        $seasons = [];

        foreach (is_array($raw) ? $raw : [] as $season) {
            if (! is_array($season)
                || ! preg_match('/^\d\d-\d\d$/', (string) ($season['from'] ?? ''))
                || ! preg_match('/^\d\d-\d\d$/', (string) ($season['to'] ?? ''))) {
                continue;
            }

            $seasons[] = [
                'name' => mb_substr(trim((string) ($season['name'] ?? '')), 0, 40),
                'from' => $season['from'],
                'to' => $season['to'],
                'color' => preg_match('/^#[0-9a-f]{3,8}$/i', (string) ($season['color'] ?? '')) ? $season['color'] : '#22c55e',
                'icon' => preg_match('/^[a-z0-9 -]{1,60}$/i', (string) ($season['icon'] ?? '')) ? $season['icon'] : 'fas fa-fire',
            ];
        }

        return $this->memo['seasons'] = $seasons;
    }

    public static function validTimezone(?string $tz): ?string
    {
        return $tz && in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : null;
    }

    /** @return int[] */
    public static function numbers(string $list): array
    {
        $out = array_values(array_unique(array_filter(array_map('intval', preg_split('/[^0-9]+/', $list) ?: []), fn ($n) => $n > 0)));
        sort($out);

        return $out;
    }

    private function get(string $key): ?string
    {
        $value = $this->settings->get(self::PREFIX.$key);

        return $value === null || $value === '' ? null : (string) $value;
    }

    private function bool(string $key, bool $default): bool
    {
        $value = $this->get($key);

        return $value === null ? $default : (bool) (int) $value;
    }

    private function int(string $key, int $default, int $min, int $max): int
    {
        $value = $this->get($key);

        return $value === null ? $default : max($min, min($max, (int) $value));
    }
}
