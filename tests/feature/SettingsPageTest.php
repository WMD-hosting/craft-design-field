<?php
declare(strict_types=1);

namespace wmd\designfield\tests\feature;

use CraftCms\Cms\Cms;
use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Matrix;
use CraftCms\Cms\FieldLayout\FieldLayoutTab;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\Plugin\Testing\PluginTestCase;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Section\Data\SectionSiteSettings;
use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\User\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use wmd\designfield\fields\Design;
use wmd\designfield\services\Groups;
use wmd\designfield\services\Usage;

/**
 * The settings page (Settings → Plugins → Design Field), built the way the Ui page
 * receives it: the Configuration tab and the Usage tab. Nothing on it saves.
 */
final class SettingsPageTest extends PluginTestCase
{
    private const string URL = '/admin/settings/plugins/design-field';

    /** @var array<string,mixed> What the config file holds in a test */
    public static array $config = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::$config = [
            'groups' => [
                'tone' => ['label' => 'Tone', 'options' => ['light' => ['label' => 'Light', 'value' => 'bg-white'], 'dark' => ['label' => 'Dark', 'value' => 'bg-black']], 'default' => 'light'],
                'spacing' => ['label' => 'Spacing', 'options' => ['tight' => 'py-4', 'normal' => 'py-8', 'loose' => 'py-16'], 'default' => 'normal'],
            ],
            'profiles' => ['blockCta' => ['tone', 'spacing'], 'blockTeam' => ['tone']],
        ];

        $this->app->instance(Groups::class, new class() extends Groups {
            public function config(): array
            {
                return SettingsPageTest::$config + parent::config();
            }
        });

        $this->actingAs(User::query()->findOrFail(1));
    }

    public function testAnAdminOpensThePageOnItsConfigurationTab(): void
    {
        $this->createPages();

        $props = self::props($this->get(self::URL));

        self::assertSame('Design Field', $props->title);
        self::assertSame(['Configuration', 'Preview', 'Usage'], array_column($props->subnav, 'label'));
        self::assertSame([true, false, false], array_column($props->subnav, 'selected'));
        self::assertStringEndsWith('settings/plugins/design-field?tab=usage', $props->subnav[2]->url ?? $props->subnav[2]->href);
        self::assertObjectNotHasProperty('submit', $props, 'nothing to save');

        $nodes = self::nodes($props);
        self::assertSame('success', $nodes['configuration-verdict']->props->variant);
        self::assertSame([], $nodes['configuration-findings']->props->rows);
        // The test install has no templates: a note, which is nothing to fix.
        self::assertSame(['Note'], array_column($nodes['template-findings']->props->rows, 'level'));
    }

    public function testItOpensWhereAdminChangesAreOff(): void
    {
        Cms::config()->allowAdminChanges = false;

        self::assertArrayHasKey('configuration-verdict', self::nodes(self::props($this->get(self::URL))));
    }

    public function testOnlyAdminsOpenIt(): void
    {
        DB::table('users')->where('id', 1)->update(['admin' => false]);
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::query()->findOrFail(1));

        $this->get(self::URL)->assertForbidden();
    }

    public function testTheConfigurationTabListsWhatTheCheckFinds(): void
    {
        self::$config['profiles']['blockGone'] = ['tone'];
        self::$config['presets'] = ['blockCta' => ['Loud' => ['tone' => 'neon']]];
        $this->createPages();

        $nodes = self::nodes(self::props($this->get(self::URL)));
        $rows = $nodes['configuration-findings']->props->rows;

        self::assertSame('warning', $nodes['configuration-verdict']->props->variant);
        self::assertStringContainsString('2 findings', $nodes['configuration-verdict']->props->html);
        self::assertSame(['Error', 'Error'], array_column($rows, 'level'));
        self::assertStringContainsString('blockGone', $rows[0]->finding);
        self::assertStringContainsString('neon', $rows[1]->finding);
    }

    public function testAConfigurationThatDoesNotBuildIsTheOneFinding(): void
    {
        self::$config['profiles']['blockCta'] = ['tone', 'notAGroup'];

        $nodes = self::nodes(self::props($this->get(self::URL)));

        self::assertCount(1, $nodes['configuration-findings']->props->rows);
        self::assertStringContainsString('notAGroup', $nodes['configuration-findings']->props->rows[0]->finding);

        // The usage report needs the options: it says so instead of counting.
        self::assertArrayHasKey('usage-error', self::nodes(self::props($this->get(self::URL . '?tab=usage&run=1'))));
    }

    public function testTheUsageTabCountsOnlyWhenAsked(): void
    {
        $this->createPages();

        $props = self::props($this->get(self::URL . '?tab=usage'));
        $nodes = self::nodes($props);

        self::assertSame([false, false, true], array_column($props->subnav, 'selected'));
        self::assertStringContainsString('tab=usage&run=1', html_entity_decode($nodes['usage-run']->props->html));
        self::assertArrayNotHasKey('usage-totals', $nodes);
        self::assertNull(Cache::get(Usage::COUNTS_KEY), 'nothing was counted');
    }

    public function testARunShowsEachBlocksFindingsAndRefreshesMostUsed(): void
    {
        $this->createPages();

        $nodes = self::nodes(self::props($this->get(self::URL . '?tab=usage&run=1')));

        self::assertStringContainsString('<strong>All time</strong>', $nodes['usage-period']->props->html);
        self::assertStringContainsString('run=1&months=6', html_entity_decode($nodes['usage-period']->props->html));
        self::assertStringContainsString('<strong>2</strong> blocks with findings', $nodes['usage-totals']->props->html);

        // Three pages: every CTA is dark, spacing was never touched; every team block is on the default.
        $table = $nodes['usage-blocks']->props;
        $rows = array_column($table->rows, null, 'id');
        self::assertSame(['blockCta', 'blockTeam'], array_keys($rows), 'one row per block');
        self::assertSame('Tone: Dark', $rows['blockCta']->same);
        self::assertSame('Spacing: Normal', $rows['blockCta']->never);
        self::assertSame('Tone: Light', $rows['blockTeam']->never);
        self::assertSame('', $rows['blockTeam']->same);
        self::assertSame('3 (few: a hint)', $rows['blockCta']->entries);
        self::assertStringContainsString('/settings/entry-types/', $rows['blockCta']->block->url);
        self::assertTrue($table->searchable);

        self::assertSame(['dark' => 3], app(Usage::class)->counts()['blockCta']['tone'], 'the counts behind "Most used"');
    }

    public function testAPeriodLimitsTheCountAndLeavesMostUsedAlone(): void
    {
        $this->createPages();
        Cache::put(Usage::COUNTS_KEY, ['blockCta' => ['tone' => ['dark' => 40]]], 60);

        $nodes = self::nodes(self::props($this->get(self::URL . '?tab=usage&run=1&months=6')));

        self::assertStringContainsString('<strong>Last 6 months</strong>', $nodes['usage-period']->props->html);
        self::assertCount(2, $nodes['usage-blocks']->props->rows, 'the pages were saved just now');
        self::assertSame(['dark' => 40], Cache::get(Usage::COUNTS_KEY)['blockCta']['tone'], 'a window would shrink the counts');

        // Any other number of months counts everything.
        self::assertStringContainsString('<strong>All time</strong>', self::nodes(self::props($this->get(self::URL . '?tab=usage&run=1&months=7')))['usage-period']->props->html);
    }

    public function testWithNoEntriesTheReportSaysSo(): void
    {
        self::assertSame('info', self::nodes(self::props($this->get(self::URL . '?tab=usage&run=1')))['usage-none']->props->variant);
    }

    public function testTheFieldSettingsLinkToThePage(): void
    {
        $nodes = (new Design(['handle' => 'design']))->settingsUi()->nodes();
        $link = end($nodes);

        self::assertSame('design-field-settings', $link->uid());
        self::assertStringContainsString('settings/plugins/design-field', $link->props()['html'] ?? $link->props()['content']);
    }

    // Helpers
    // =========================================================================

    /** The Inertia page props of the screen, as the Ui page receives them. */
    private static function props(TestResponse $response): object
    {
        $html = (string)$response->assertOk()->getContent();
        preg_match('#<script data-page="app" type="application/json">(.*?)</script>#s', $html, $m);

        return json_decode($m[1] ?? 'null', false, flags: JSON_THROW_ON_ERROR)->props;
    }

    /** @return array<string,object> The screen's nodes by uid */
    private static function nodes(object $props): array
    {
        $nodes = [];

        foreach ($props->ui->nodes as $node) {
            if (isset($node->uid)) {
                $nodes[$node->uid] = $node;
            }
        }

        return $nodes;
    }

    /**
     * Three pages, each with a dark CTA block and a team block left as it is.
     */
    private function createPages(): void
    {
        $design = new Design(['name' => 'Design', 'handle' => 'design']);
        self::assertTrue(Fields::saveField($design));

        $blockTypes = [];

        foreach (['blockCta', 'blockTeam'] as $handle) {
            $type = new EntryType(['name' => $handle, 'handle' => $handle, 'hasTitleField' => false]);
            $type->getFieldLayout()->tab('Content', static fn(FieldLayoutTab $tab) => $tab->add(CustomField::make($design)));
            self::assertTrue(EntryTypes::saveEntryType($type));
            $blockTypes[] = $type;
        }

        $builder = new Matrix(['name' => 'Page builder', 'handle' => 'pageBuilder']);
        $builder->setEntryTypes($blockTypes);
        self::assertTrue(Fields::saveField($builder));

        $pageType = new EntryType(['name' => 'Page', 'handle' => 'page']);
        $pageType->getFieldLayout()->tab('Content', static fn(FieldLayoutTab $tab) => $tab->add(CustomField::make($builder)));
        self::assertTrue(EntryTypes::saveEntryType($pageType));

        $section = new Section([
            'name' => 'Pages',
            'handle' => 'pages',
            'type' => SectionType::Channel,
            'siteSettings' => [new SectionSiteSettings(['siteId' => Sites::getPrimarySite()->id])],
            'entryTypes' => [$pageType],
        ]);
        self::assertTrue(Sections::saveSection($section));

        foreach (['Home', 'About', 'Contact'] as $title) {
            $page = new Entry();
            $page->sectionId = $section->id;
            $page->typeId = $pageType->id;
            $page->title = $title;
            $page->setFieldValue('pageBuilder', [
                'new1' => ['type' => 'blockCta', 'fields' => ['design' => ['tone' => 'dark']]],
                'new2' => ['type' => 'blockTeam'],
            ]);
            self::assertTrue(Elements::saveElement($page), implode(' ', $page->errors()->all()));
        }
    }
}
