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

    protected $signature = 'integration-runtime:agent-rules {--force : Overwrite the skill and replace the AGENTS.md section}';

    protected $description = 'Install Integration Runtime rules for Claude Code and Codex into this app';

    public function handle(Filesystem $files): int
    {
        $stubs = dirname(__DIR__, 2) . '/stubs';
        $skill = base_path('.claude/skills/quickbooks-integration-runtime/SKILL.md');
        if ($files->exists($skill) && ! $this->option('force')) {
            $this->line('Skill exists, kept: ' . $skill . ' (use --force to replace)');
        } else {
            $files->ensureDirectoryExists(dirname($skill));
            $files->put($skill, $files->get($stubs . '/SKILL.md'));
            foreach (['quickbooks-operations.md', 'quickbooks-operations.json'] as $doc) {
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
            if (! $this->option('force')) {
                $this->line('AGENTS.md already has the section, kept (use --force to replace)');

                return self::SUCCESS;
            }
            $current = substr($current, 0, $start) . $section . substr($current, $end + strlen(self::MARKER_END) + 1);
        } else {
            $current = ($current === '' ? '' : rtrim($current) . "\n\n") . $section;
        }
        $files->put($agents, $current);
        $this->info('Updated ' . $agents . ' (Codex and other agents read it; Claude Code reads it via CLAUDE.md "@AGENTS.md").');

        return self::SUCCESS;
    }
}
