<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\http;

use CraftCms\Cms\Cp\Data\NavItem;
use CraftCms\Cms\Entry\EntryTypes;
use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Support\Url;
use CraftCms\Cms\Ui\Contracts\Node;
use CraftCms\Cms\Ui\Nodes\Callout;
use CraftCms\Cms\Ui\Nodes\Heading;
use CraftCms\Cms\Ui\Nodes\MarkdownContent;
use CraftCms\Cms\Ui\Nodes\Table;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use DateTimeImmutable;
use Illuminate\Http\Request;
use InvalidArgumentException;
use wmd\designfield\helpers\Registry;
use wmd\designfield\helpers\UsageReport;
use wmd\designfield\services\Groups;
use wmd\designfield\services\Health;
use wmd\designfield\services\Usage;

/**
 * The plugin's settings page (Settings → Plugins → Design Field): the configuration
 * check and the usage report, each on its own tab. It only reads: the config file is
 * the configuration, so there is nothing to save and the page opens with admin changes off.
 *
 * A tab is a URL (`?tab=`), so only the open one is built: the usage report reads every entry.
 *
 * @author WMD
 * @since 2.0.0
 */
class SettingsScreen
{
    // Const Properties
    // =========================================================================

    /**
     * Control panel path of the page.
     */
    public const PATH = 'settings/plugins/design-field';

    /**
     * The tabs in nav order: `?tab=` value => label. The first is the default.
     */
    public const TABS = ['configuration' => 'Configuration', 'usage' => 'Usage'];

    /**
     * The periods the usage report offers, in months; 0 counts everything.
     */
    public const PERIODS = [0 => 'All time', 12 => 'Last 12 months', 6 => 'Last 6 months', 3 => 'Last 3 months'];

    /**
     * Below this many entries a block's findings are a hint, as in `design-field:usage`.
     */
    private const SAMPLE = 5;

    public function __construct(
        private readonly Groups $groups,
        private readonly Health $health,
        private readonly Usage $usage,
        private readonly EntryTypes $entryTypes,
    ) {
    }

    // Public Methods
    // =========================================================================

    /**
     * @param Request $request
     * @return CpScreenResponse
     *
     * @author WMD
     * @since 2.0.0
     */
    public function response(Request $request): CpScreenResponse
    {
        $tab = $request->query('tab');
        $tab = is_string($tab) && isset(self::TABS[$tab]) ? $tab : array_key_first(self::TABS);

        $nodes = match ($tab) {
            'usage' => $this->usageNodes($request),
            default => $this->configurationNodes(),
        };

        $subnav = [];
        foreach (self::TABS as $key => $label) {
            $subnav[] = new NavItem()->label($label)->href(self::url($key))->selected($key === $tab);
        }

        return new CpScreenResponse()
            ->title('Design Field')
            ->addCrumb('Settings', 'settings')
            ->addCrumb('Plugins', 'settings/plugins')
            ->subnav($subnav)
            // The nodes go out as a list: the Ui page reads `ui.nodes` as a JSON array.
            ->ui(Ui::make(array_values($nodes)), new UiContext(), ['contentMaxWidth' => false]);
    }

    /**
     * The page's URL for a tab.
     *
     * @param string $tab One of the TABS keys
     * @param array<string,scalar> $params More query parameters
     * @return string
     *
     * @author WMD
     * @since 2.0.0
     */
    public static function url(string $tab = 'configuration', array $params = []): string
    {
        return Url::cpUrl(self::PATH, ($tab === array_key_first(self::TABS) ? [] : ['tab' => $tab]) + $params);
    }

    // Private Methods
    // =========================================================================

    /**
     * What `design-field:check` prints: the configuration against this install, and
     * the options against the templates that render them.
     *
     * @return list<Node>
     */
    private function configurationNodes(): array
    {
        $config = $this->health->check();
        $templates = $this->health->templates();
        $failing = count(array_filter([...$config, ...$templates], static fn(array $finding) => $finding['level'] !== Health::INFO));

        return [
            MarkdownContent::make('configuration-intro', 'Options, blocks and presets are set in `config/craft/design-field.php`. This page checks that file against this install and its templates; `php artisan design-field:check` prints the same, and fails a deploy when something needs fixing.'),
            $failing === 0
                ? Callout::make('configuration-verdict', 'No problems found.')->variant('success')
                : Callout::make('configuration-verdict', $failing === 1 ? 'One finding to fix.' : "$failing findings to fix.")->variant('warning'),
            Heading::make('configuration-heading', 'Configuration check')
                ->description('Blocks named in the file that this install does not have, fields set to a missing block, preset choices no block offers, and option pictures missing under the web root.'),
            self::findingsTable('configuration-findings', $config, 'The configuration matches this install.'),
            Heading::make('templates-heading', 'Template check')
                ->description('Options no template reads (editors change them and nothing happens), template reads of options a block does not offer, and layouts without a template file.'),
            self::findingsTable('template-findings', $templates, 'Templates and options match.'),
        ];
    }

    /**
     * @param string $uid
     * @param list<array{level:string,message:string}> $findings
     * @param string $empty What the table says with no finding
     * @return Table
     */
    private static function findingsTable(string $uid, array $findings, string $empty): Table
    {
        $rows = [];

        foreach ($findings as $i => $finding) {
            $rows[] = [
                'id' => $i + 1,
                'level' => match ($finding['level']) {
                    Health::ERROR => 'Error',
                    Health::WARNING => 'Warning',
                    default => 'Note',
                },
                'finding' => $finding['message'],
                '_status' => match ($finding['level']) {
                    Health::ERROR => 'expired',
                    Health::WARNING => 'warning',
                    default => 'grey',
                },
            ];
        }

        return Table::make($uid)
            ->columns([
                ['key' => 'level', 'label' => 'Level', 'thin' => true],
                ['key' => 'finding', 'label' => 'Finding', 'wrap' => true],
            ])
            ->rows($rows)
            ->emptyMessage($empty);
    }

    /**
     * What `design-field:usage` prints, one table row per block. Counting reads every
     * entry, so it runs only when asked (`run=1`).
     *
     * @param Request $request
     * @return list<Node>
     */
    private function usageNodes(Request $request): array
    {
        $nodes = [
            MarkdownContent::make('usage-intro', 'Which choices editors actually make, counted over every entry (drafts and revisions left out). An option nobody changes can be dropped from its block or fixed in the template; a choice everyone picks can become the default. On the command line: `php artisan design-field:usage`.'),
        ];

        try {
            $registry = $this->groups->getRegistry();
        } catch (InvalidArgumentException $e) {
            return [...$nodes, Callout::make('usage-error', 'The report needs a configuration that builds: ' . $e->getMessage())->variant('danger')];
        }

        if (!$request->boolean('run')) {
            return [...$nodes, MarkdownContent::make('usage-run', sprintf('[Run the usage report](<%s>)', self::url('usage', ['run' => 1])))];
        }

        $months = $request->integer('months');
        $months = isset(self::PERIODS[$months]) ? $months : 0;
        $report = $this->usage->report([], $months > 0 ? new DateTimeImmutable("-$months months") : null);

        // Counted everything anyway: refresh "Most used" in long dropdowns too. A report
        // limited to a period would shrink those counts to its window.
        if ($months === 0) {
            $this->usage->storeCounts($report);
        }

        $summary = UsageReport::summarize($report);
        $periods = [];
        foreach (self::PERIODS as $period => $label) {
            $periods[] = $period === $months ? "**$label**" : sprintf('[%s](<%s>)', $label, self::url('usage', ['run' => 1] + ($period > 0 ? ['months' => $period] : [])));
        }

        $nodes[] = MarkdownContent::make('usage-period', 'Count blocks saved in: ' . implode(' · ', $periods));
        $nodes[] = MarkdownContent::make('usage-totals', sprintf(
            '**%d** %s with findings · **%d** %s never changed · **%d** always the same choice · %d %s counted%s',
            count($summary['types']),
            count($summary['types']) === 1 ? 'block' : 'blocks',
            $summary['neverChanged'],
            $summary['neverChanged'] === 1 ? 'option' : 'options',
            $summary['oneValue'],
            count($report),
            count($report) === 1 ? 'block' : 'blocks',
            $months === 0 ? '. “Most used” in long dropdowns now follows these counts.' : '.',
        ));

        if ($summary['nowhere'] !== []) {
            $nodes[] = Callout::make('usage-nowhere', 'Never changed in any block: ' . implode(', ', array_map(
                static fn(array $group) => sprintf('%s (%d blocks, %d entries)', $group['label'], $group['types'], $group['entries']),
                $summary['nowhere'],
            )))->variant('warning');
        }

        if ($summary['types'] === []) {
            $nodes[] = Callout::make('usage-none', $report === [] ? 'No block with a Design field has entries yet.' : 'Nothing to tidy: every option is in use.')->variant($report === [] ? 'info' : 'success');
        }

        if ($summary['types'] !== []) {
            $nodes[] = $this->usageTable($summary['types'], $registry);
        }

        return $nodes;
    }

    /**
     * One row per block: the options nobody changed and the ones where every entry
     * picked the same choice. Blocks with few entries come last: hints, not evidence.
     *
     * @param array<string,array{entries:int,findings:list<array<string,mixed>>}> $types UsageReport::summarize()['types']
     * @param Registry $registry
     * @return Table
     */
    private function usageTable(array $types, Registry $registry): Table
    {
        uasort($types, static fn(array $a, array $b) => ($a['entries'] < self::SAMPLE) <=> ($b['entries'] < self::SAMPLE));
        $rows = [];

        foreach ($types as $type => $data) {
            $groups = $registry->groupsFor($type);
            $label = static fn(string $handle, ?string $key): string => (string)(($groups[$handle] ?? null)?->options[(string)$key]['label'] ?? $key);
            $never = [];
            $same = [];

            foreach ($data['findings'] as $finding) {
                if ($finding['verdict'] === UsageReport::ONE_VALUE) {
                    $same[] = sprintf('%s: %s', $finding['label'], $label($finding['handle'], $finding['value']));
                } elseif ($finding['unused'] !== []) {
                    // A layout list: which layouts nobody picked matters more than the default.
                    $never[] = sprintf('%s: %s (never picked: %s)', $finding['label'], $label($finding['handle'], $finding['default']), implode(', ', array_map(static fn(string $key) => $label($finding['handle'], $key), $finding['unused'])));
                } else {
                    $never[] = sprintf('%s: %s', $finding['label'], $label($finding['handle'], $finding['default']));
                }
            }

            $entryType = $this->entryTypes->getEntryTypeByHandle($type);
            $rows[] = [
                'id' => $type,
                'block' => $entryType === null ? $type : ['label' => $entryType->name, 'url' => Url::cpUrl("settings/entry-types/$entryType->id")],
                'entries' => $data['entries'] < self::SAMPLE ? sprintf('%d (few: a hint)', $data['entries']) : $data['entries'],
                'never' => implode(' · ', $never),
                'same' => implode(' · ', $same),
                '_sort' => ['entries' => $data['entries']],
            ];
        }

        return Table::make('usage-blocks')
            ->columns([
                ['key' => 'block', 'label' => 'Block', 'sortable' => true],
                ['key' => 'entries', 'label' => 'Entries', 'sortable' => true, 'thin' => true],
                ['key' => 'never', 'label' => 'Never changed from the default: drop or hardcode', 'wrap' => true],
                ['key' => 'same', 'label' => 'Always the same choice: make it the default', 'wrap' => true],
            ])
            ->rows($rows)
            ->searchable('Find a block or an option');
    }
}
