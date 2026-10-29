<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Teach the app's coding agents (Claude Code, Codex) the integration rules:
 * a Claude Code skill plus a marked section in AGENTS.md.
 */
final class InstallAgentRulesCommand extends Command
{
    private const MARKER_START = '<!-- integration-runtime:agent-rules:start -->';

    private const MARKER_END = '<!-- integration-runtime:agent-rules:end -->';

    private const SKILL_DIR = '.claude/skills/accounting-integration-runtime';

    /** Installed by 1.0-1.3.0 under its QuickBooks-era name; moved on the next install. */
    private const LEGACY_SKILL_DIR = '.claude/skills/quickbooks-integration-runtime';

    /** sha256 of every SKILL.md shipped under the old name; an edited copy is never removed. */
    private const LEGACY_SKILL_SHA256 = [
        'f955d7011ebbd49feeeb91dae2a8c1c2cd06cadf93729b9b22b1f976026ea607',
        '5917d101e2fc0a2f3387b995b6990fd94e3918ee549f42b908d3118aa12156f2',
        'f25f8bfe1c77962641b3ac82a4300a75dc5d642261d34870f63e7685d81c50cd',
    ];

    private const DOCS = ['accounting-operations.md', 'accounting-operations.json', 'accounting-migration.md', 'quickbooks-operations.md', 'quickbooks-operations.json'];

    protected $signature = 'integration-runtime:agent-rules {--force : Overwrite the skill and replace the AGENTS.md section}';

    protected $description = 'Install Integration Runtime rules for Claude Code and Codex into this app';

    public function handle(Filesystem $files): int
    {
        $stubs = dirname(__DIR__, 2) . '/stubs';
        $skill = base_path(self::SKILL_DIR . '/SKILL.md');
        if ($files->exists($skill) && ! $this->option('force')) {
            $this->line('Skill exists, kept: ' . $skill . ' (use --force to replace)');
        } else {
            $files->ensureDirectoryExists(dirname($skill));
            $files->put($skill, $files->get($stubs . '/SKILL.md'));
            foreach (self::DOCS as $doc) {
                $files->copy(dirname(__DIR__, 2) . '/docs/' . $doc, dirname($skill) . '/' . $doc);
            }
            $this->info('Wrote ' . $skill . ' and the operation reference');
        }

        $agents = base_path('AGENTS.md');
        $section = self::MARKER_START . "\n" . rtrim($files->get($stubs . '/AGENTS.section.md')) . "\n" . self::MARKER_END . "\n";
        $current = $files->exists($agents) ? $files->get($agents) : '';
        $start = strpos($current, self::MARKER_START);
        $end = strpos($current, self::MARKER_END);
        if ($start !== false && $end !== false && $end > $start) {
            // A section an earlier version wrote names the old skill path,
            // which is removed below; it is replaced even without --force.
            $stale = str_contains(substr($current, $start, $end - $start), self::LEGACY_SKILL_DIR);
            if (! $this->option('force') && ! $stale) {
                $this->line('AGENTS.md already has the section, kept (use --force to replace)');
                $this->removeLegacySkill($files);

                return self::SUCCESS;
            }
            $current = substr($current, 0, $start) . $section . substr($current, $end + strlen(self::MARKER_END) + 1);
        } else {
            $current = ($current === '' ? '' : rtrim($current) . "\n\n") . $section;
        }
        $files->put($agents, $current);
        $this->info('Updated ' . $agents . ' (Codex and other agents read it; Claude Code reads it via CLAUDE.md "@AGENTS.md").');
        // Only after AGENTS.md points at the new path.
        $this->removeLegacySkill($files);

        return self::SUCCESS;
    }

    /**
     * Remove the skill this command installed under its old name, so agents
     * do not load two copies. Only an unedited SKILL.md (byte-identical to a
     * shipped version) is removed, with the reference docs installed beside
     * it; the directory goes only when nothing else, hidden files included,
     * is left. An edited skill is kept and reported.
     */
    private function removeLegacySkill(Filesystem $files): void
    {
        $dir = base_path(self::LEGACY_SKILL_DIR);
        $skill = $dir . '/SKILL.md';
        $content = $files->exists($skill) ? str_replace("\r\n", "\n", $files->get($skill)) : '';
        if (! str_contains($content, "\nname: quickbooks-integration-runtime\n")) {
            return; // not this package's skill
        }
        if (! in_array(hash('sha256', $content), self::LEGACY_SKILL_SHA256, true)) {
            $this->warn('Kept the old skill at ' . $dir . ' because it was edited. Move your changes to ' . self::SKILL_DIR . ' and delete it, so agents do not load both.');

            return;
        }
        $files->delete([$skill, ...array_map(static fn(string $doc) => $dir . '/' . $doc, self::DOCS)]);
        $left = scandir($dir);
        if ($left !== false && array_diff($left, ['.', '..']) === []) {
            $files->deleteDirectory($dir);
        }
        $this->info('Removed the old skill at ' . $dir . ' (now ' . self::SKILL_DIR . ')');
    }
}
