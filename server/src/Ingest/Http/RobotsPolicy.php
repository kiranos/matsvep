<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Http;

final class RobotsPolicy
{
    /** @var list<array{agents: list<string>, rules: list<array{allow: bool, pattern: string}>}> */
    private array $groups = [];

    public function __construct(string $content)
    {
        $agents = [];
        $rules = [];
        foreach (preg_split('/\r?\n/', $content) ?: [] as $line) {
            $line = trim(explode('#', $line, 2)[0]);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$directive, $value] = explode(':', $line, 2);
            $directive = strtolower(trim($directive));
            $value = trim($value);
            if ($directive === 'user-agent') {
                if ($rules !== []) {
                    $this->groups[] = ['agents' => $agents, 'rules' => $rules];
                    $agents = [];
                    $rules = [];
                }
                $agents[] = strtolower($value);
            } elseif (($directive === 'allow' || $directive === 'disallow') && $agents !== [] && $value !== '') {
                $rules[] = ['allow' => $directive === 'allow', 'pattern' => $value];
            }
        }
        if ($agents !== []) {
            $this->groups[] = ['agents' => $agents, 'rules' => $rules];
        }
    }

    public function allows(string $userAgent, string $path): bool
    {
        $bestAgentLength = -1;
        $matchingGroups = [];
        foreach ($this->groups as $group) {
            foreach ($group['agents'] as $agent) {
                if ($agent !== '*' && !str_contains(strtolower($userAgent), $agent)) {
                    continue;
                }
                $length = $agent === '*' ? 0 : strlen($agent);
                if ($length > $bestAgentLength) {
                    $bestAgentLength = $length;
                    $matchingGroups = [$group];
                } elseif ($length === $bestAgentLength && !in_array($group, $matchingGroups, true)) {
                    $matchingGroups[] = $group;
                }
            }
        }

        $bestRuleLength = -1;
        $allowed = true;
        foreach ($matchingGroups as $group) {
            foreach ($group['rules'] as $rule) {
                $pattern = $rule['pattern'];
                $endAnchor = str_ends_with($pattern, '$');
                $pattern = rtrim($pattern, '$');
                $regex = '~^' . str_replace('\\*', '.*', preg_quote($pattern, '~')) . ($endAnchor ? '$' : '') . '~';
                if (preg_match($regex, $path) !== 1) {
                    continue;
                }
                $length = strlen(str_replace('*', '', $pattern));
                if ($length > $bestRuleLength || ($length === $bestRuleLength && $rule['allow'])) {
                    $bestRuleLength = $length;
                    $allowed = $rule['allow'];
                }
            }
        }
        return $allowed;
    }
}
