<?php
declare(strict_types=1);
namespace OCA\Cognos\Service;
/** A shared comparison for hand-back and delegated work application. */
final class RunDriftService {
    public function __construct(private DecisionService $decisions,private ProjectService $projects,private RunService $runs) {}
    /** Fingerprint of the exact returned drift; not proof of understanding or authority. */
    public function snapshot(string $uid, array $run): array {
        $missing = $this->decisions->missingForRun($uid, $run);
        $changed = $this->basisChanged($uid, $run['id']);
        usort($missing, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));
        usort($changed, static fn(array $a, array $b): int => [$a['location_id'], $a['version']] <=> [$b['location_id'], $b['version']]);
        $token = hash('sha256', json_encode([$run['id'], $missing, $changed], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return ['decisions_missing' => $missing, 'basis_changed' => $changed, 'drift_token' => $token];
    }

    /** 这次运行报过「依据了某处当前内容第几版」，而那一处现在已经是更新的版本。 */
    private function basisChanged(string $uid, string $runId): array {
        $out = [];
        foreach ($this->runs->dependencies($runId) as $dep) {
            if ($dep['type'] !== 'location_content' || $dep['version'] === null) {
                continue;
            }
            try {
                $loc = $this->projects->location($uid, $dep['id']);
            } catch (\RuntimeException) {
                continue;
            }
            if ($loc['revision'] > (int)$dep['version']) {
                // 改了哪些行：只在一边有的行（不标行内改动）
                $old = explode("
", (string)$this->projects->revision($uid, $loc['id'], (int)$dep['version'])['content']);
                $new = explode("
", (string)$loc['content']);
                $out[] = ['location_id' => $loc['id'], 'title' => $loc['title'], 'version' => (int)$dep['version'],
                    'current' => $loc['revision'], 'editor' => $loc['editor'],
                    'removed' => array_values(array_filter(array_diff($old, $new), 'strlen')),
                    'added' => array_values(array_filter(array_diff($new, $old), 'strlen'))];
            }
        }
        return $out;
    }

}
