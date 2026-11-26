<?php
declare(strict_types=1);

namespace wmd\designfield\tests\feature;

use CraftCms\Cms\Cms;
use CraftCms\Cms\Cp\Alerts;
use CraftCms\Cms\Database\Table;
use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\FieldContext;
use CraftCms\Cms\FieldLayout\FieldLayoutTab;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\Mcp\Capabilities\Elements as ElementTools;
use CraftCms\Cms\Plugin\Testing\PluginTestCase;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Section\Data\SectionSiteSettings;
use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Ui\Controls\Textarea;
use CraftCms\Cms\User\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Mcp\Exception\ToolCallException;
use wmd\designfield\fields\Design;
use wmd\designfield\models\DesignValue;
use wmd\designfield\services\Groups;
use wmd\designfield\services\Health;
use wmd\designfield\variables\DesignFieldVariable;

use function CraftCms\Cms\renderString;

/**
 * A configuration that does not build (a profile naming a group that is gone, a config
 * file with a syntax error) must not take the site down: pages keep rendering without
 * design classes, stored values stay as they are, and the control panel says why.
 */
final class ConfigFallbackTest extends PluginTestCase
{
    private const array GOOD = [
        'groups' => [
            'tone' => ['label' => 'Tone', 'options' => ['light' => 'bg-white', 'dark' => 'bg-black'], 'default' => 'light'],
            'spacing' => ['label' => 'Spacing', 'options' => ['tight' => 'py-4', 'normal' => 'py-8'], 'default' => 'normal'],
            'gap' => ['label' => 'Gap', 'options' => ['0' => 'gap-0', '4' => 'gap-4'], 'default' => '4'],
        ],
        'profiles' => ['blockCta' => ['tone', 'spacing', 'gap']],
    ];

    /** The same file after an edit that names a group it does not define. */
    private const array BROKEN = ['profiles' => ['blockCta' => ['tone', 'notAGroup']]] + self::GOOD;

    /** @var array<string,mixed> What the config file holds in a test */
    public static array $config = [];

    /** How often the configuration was read to build a registry */
    public static int $reads = 0;

    private ?string $configDir = null;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Control-panel pages are opened over HTTP, which needs a key for the session cookie.
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
    }

    protected function tearDown(): void
    {
        if ($this->configDir !== null) {
            File::deleteDirectory($this->configDir);
        }

        parent::tearDown();
    }

    public function testAValueIsReadWithoutItsOptionsInsteadOfThrowing(): void
    {
        $this->configure(self::BROKEN);
        $field = new Design(['handle' => 'design', 'profile' => 'blockCta']);

        $value = $field->normalizeValue(['tone' => 'dark', 'spacing' => 'tight'], null);

        self::assertInstanceOf(DesignValue::class, $value);
        self::assertSame('', (string)$value, 'no classes, so the template prints its own defaults');
        self::assertSame('', $value->tone->key);
        self::assertFalse($value->has('tone'));
        self::assertSame('fallback', $value->tone->classOr('fallback'));
        self::assertNull($value->rejected());
        self::assertSame([], $value->invalid());
    }

    public function testTheTwigVariableKeepsAnswering(): void
    {
        $this->configure(self::BROKEN);
        $variable = $this->app->make(DesignFieldVariable::class);

        self::assertSame('', (string)$variable->value(['tone' => 'dark'], 'blockCta'));
        self::assertSame('', (string)$variable->of(['tone' => 'dark'], 'blockCta'));
        self::assertSame([], $variable->groups());
    }

    public function testATemplateThatReadsABlocksDesignStillRenders(): void
    {
        $this->configure(self::GOOD);
        $block = $this->createBlock(['tone' => 'dark', 'gap' => '0']);
        $template = '<section class="{{ block.design }} {{ block.design.tone.classOr(\'bg-none\') }}" data-gap="{{ block.design.gap.keyOr(\'4\') }}">'
            . '{{ craft.designField.of(block).has(\'tone\') ? \'styled\' : \'plain\' }}</section>';

        self::assertSame(
            '<section class="bg-black py-8 gap-0 bg-black" data-gap="0">styled</section>',
            renderString($template, ['block' => Entry::find()->id($block->id)->status(null)->one()]),
        );

        // The same page while the configuration does not build: its own defaults, no exception.
        $this->configure(self::BROKEN);

        self::assertSame(
            '<section class=" bg-none" data-gap="4">plain</section>',
            renderString($template, ['block' => Entry::find()->id($block->id)->status(null)->one()]),
        );
    }

    public function testEverythingAFieldIsAskedWithoutAnElementAnswers(): void
    {
        $this->configure(self::BROKEN);
        $field = new Design(['handle' => 'design', 'profile' => 'blockCta']);

        // The schema says the options cannot be written, not why: its readers are not all admins.
        self::assertSame('object', $field->getInputSchema()['type']);
        self::assertStringContainsString('does not build', $field->getInputSchema()['description']);
        self::assertStringNotContainsString('notAGroup', $field->getInputSchema()['description']);
        self::assertSame('design', $field->getContentGqlMutationArgumentType()['name']);
        self::assertSame('dark', $field->normalizeValueFromRequest('{"tone":"dark"}', null)->toArray()['tone']);

        // The field's own settings say what is wrong, and keep a profile that cannot be listed.
        $nodes = $field->settingsUi()->nodes();
        self::assertStringContainsString('notAGroup', json_encode(array_map(static fn($node) => $node->props(), $nodes)));
    }

    public function testTheFailureIsBuiltAndLoggedOnceForARequest(): void
    {
        Log::spy();
        $this->configure(self::BROKEN);
        $field = new Design(['handle' => 'design', 'profile' => 'blockCta']);

        $field->normalizeValue(['tone' => 'dark'], null);
        $field->normalizeValue(['tone' => 'light'], null);
        $this->app->make(DesignFieldVariable::class)->groups();

        self::assertSame(1, self::$reads, 'a page of forty blocks must not build the configuration forty times');
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(static fn(string $message) => str_contains($message, 'Design Field') && str_contains($message, 'notAGroup'));
    }

    public function testTheCheckStillNamesTheError(): void
    {
        $groups = $this->configure(self::BROKEN);

        try {
            $groups->getRegistry();
            self::fail('The strict registry must throw.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('notAGroup', $e->getMessage());
        }

        $findings = $this->app->make(Health::class)->check();
        self::assertSame([Health::ERROR], array_column($findings, 'level'));
        self::assertStringContainsString('notAGroup', $findings[0]['message']);
        self::assertSame('Design profile "blockCta" uses unknown group "notAGroup".', $groups->failure());
    }

    public function testAConfigurationOfTheWrongShapeIsAFindingNotACrash(): void
    {
        // `groups` entries must be arrays: PHP raises a TypeError here, not our own exception.
        $groups = $this->configure(['groups' => ['tone' => 'bg-black'], 'profiles' => ['blockCta' => ['tone']]]);

        self::assertNotNull($groups->failure());
        self::assertSame([Health::ERROR], array_column($this->app->make(Health::class)->check(), 'level'));
        self::assertSame('', (string)(new Design(['handle' => 'design', 'profile' => 'blockCta']))->normalizeValue(['tone' => 'dark'], null));
    }

    public function testASaveDuringTheOutageLeavesTheStoredValueAsItWas(): void
    {
        $this->configure(self::GOOD);
        $block = $this->createBlock(['tone' => 'dark', 'gap' => '0']);
        $stored = $this->storedContent($block->id);
        self::assertStringContainsString('"gap":"0"', str_replace(' ', '', $stored));

        $this->configure(self::BROKEN);

        // An editor changes nothing in the Design field and saves the block.
        $loaded = Entry::find()->id($block->id)->status(null)->one();
        self::assertTrue(Elements::saveElement($loaded), implode(' ', $loaded->errors()->all()));
        self::assertSame($stored, $this->storedContent($block->id));

        // The control panel shows the stored map as text; what it posts back is stored again.
        $field = $loaded->getFieldLayout()->getFieldByHandle('design');
        $control = $field->uiControl(new FieldContext('design', $loaded->getFieldValue('design'), $loaded));
        self::assertInstanceOf(Textarea::class, $control);
        $loaded->setFieldValue('design', $field->normalizeValueFromRequest($control->getValue(), $loaded));
        self::assertTrue(Elements::saveElement($loaded));
        self::assertSame($stored, $this->storedContent($block->id));

        // With the file fixed, the block has the choices it had.
        $this->configure(self::GOOD);
        self::assertEquals(
            ['tone' => 'dark', 'spacing' => 'normal', 'gap' => '0'],
            Entry::find()->id($block->id)->status(null)->one()->getFieldValue('design')->keys(),
        );
    }

    public function testAnAgentReadsTheStoredValueAndCannotResetItByWritingBackWhatItRead(): void
    {
        $this->configure(self::GOOD);
        $block = $this->createBlock(['tone' => 'dark', 'gap' => '0']);
        $stored = $this->storedContent($block->id);

        $this->configure(self::BROKEN);
        $tools = $this->app->make(ElementTools::class);

        // Not `{}`: an agent that sends back an empty map would reset every choice.
        $read = $tools->get('entry', $block->id)['element']['design'];
        self::assertEquals(['tone' => 'dark', 'spacing' => 'normal', 'gap' => '0'], $read);

        try {
            $tools->update('entry', $block->id, fields: ['design' => $read]);
            self::fail('A design value was written while the configuration does not build.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('/design', $e->getMessage());
        }

        self::assertSame($stored, $this->storedContent($block->id));
    }

    public function testTheEditPageOfAnElementStillOpens(): void
    {
        $this->configure(self::GOOD);
        $block = $this->createBlock(['tone' => 'dark', 'gap' => '0']);
        $url = parse_url((string)$block->getCpEditUrl(), PHP_URL_PATH);

        $this->get($url)->assertOk();

        $this->configure(self::BROKEN);

        // The form holds the stored map as text, where the panel would be.
        $html = (string)$this->get($url)->assertOk()->getContent();
        preg_match('#<script data-page="app" type="application/json">(.*?)</script>#s', $html, $m);
        $page = json_decode($m[1] ?? 'null', true, flags: JSON_THROW_ON_ERROR);
        $form = [];
        array_walk_recursive(
            $page,
            static function(mixed $value, string|int $key) use (&$form): void {
                if ($key === 'design' && is_string($value)) {
                    $form[] = json_decode($value, true);
                }
            },
        );
        self::assertEquals([['tone' => 'dark', 'spacing' => 'normal', 'gap' => '0']], $form);
    }

    public function testEveryControlPanelPageSaysWhatIsWrong(): void
    {
        // A handle with markup in it: the alert is HTML, the message is not.
        $this->configure(['profiles' => ['blockCta' => ['tone', '<script>x</script>']]] + self::GOOD);

        $alert = $this->alert();
        self::assertNotNull($alert);
        self::assertStringContainsString('unknown group', $alert);
        self::assertStringContainsString('&lt;script&gt;', $alert);
        self::assertStringNotContainsString('<script>', $alert);
        self::assertStringContainsString('settings/plugins/design-field', $alert);

        // Someone who cannot open the settings page is told whom to ask, not the details.
        DB::table('users')->where('id', 1)->update(['admin' => false]);
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::query()->findOrFail(1), Cms::config()->authGuard);

        $alert = $this->alert();
        self::assertNotNull($alert);
        self::assertStringNotContainsString('unknown group', $alert);
        self::assertStringNotContainsString('settings/plugins/design-field', $alert);
    }

    public function testAConfigurationThatBuildsShowsNoAlert(): void
    {
        $this->configure(self::GOOD);

        self::assertNull($this->alert());
        self::assertNull($this->app->make(Groups::class)->failure());
    }

    public function testAConfigFileWithASyntaxErrorIsAFindingAndThePageStillRenders(): void
    {
        $this->useConfigFiles(['craft/design-field.php' => "<?php\n\nreturn ['groups' => [\n"]);
        $groups = $this->app->make(Groups::class);

        self::assertStringContainsString('config/craft/design-field.php', (string)$groups->failure());
        self::assertSame('', (string)(new Design(['handle' => 'design', 'profile' => 'blockCta']))->normalizeValue(['tone' => 'dark'], null));

        $findings = $this->app->make(Health::class)->check();
        self::assertSame([Health::ERROR], array_column($findings, 'level'));
        self::assertStringContainsString('config/craft/design-field.php', $findings[0]['message']);
    }

    public function testAConfigFileLeftAtTheCraft5PathIsAFinding(): void
    {
        $file = "<?php\n\nreturn ['groups' => ['tone' => ['options' => ['light' => 'bg-white']]], 'profiles' => ['*' => ['tone']]];\n";

        // Only the old file: nothing is configured, and nothing said so.
        $this->useConfigFiles(['design-field.php' => $file]);
        $findings = $this->app->make(Health::class)->check();
        self::assertSame([Health::ERROR], array_column($findings, 'level'));
        self::assertStringContainsString('config/design-field.php', $findings[0]['message']);
        self::assertStringContainsString('config/craft/design-field.php', $findings[0]['message']);
        self::assertSame([], $this->app->make(Groups::class)->getRegistry()->groups, 'the old file is still not read');

        self::assertSame(1, Artisan::call('design-field:check'));
        self::assertStringContainsString('config/design-field.php', Artisan::output());
        self::assertStringContainsString('config/design-field.php', (string)$this->alert());

        // Both files: the new one is read, the old one is a leftover to delete.
        $this->useConfigFiles(['design-field.php' => $file, 'craft/design-field.php' => $file]);
        $findings = array_values(array_filter(
            $this->app->make(Health::class)->check(),
            static fn(array $finding) => str_contains($finding['message'], 'config/design-field.php'),
        ));
        self::assertSame([Health::WARNING], array_column($findings, 'level'));
        self::assertNull($this->alert());

        // Only the new file: nothing to say.
        $this->useConfigFiles(['craft/design-field.php' => $file]);
        self::assertSame([], array_filter(
            $this->app->make(Health::class)->check(),
            static fn(array $finding) => str_contains($finding['message'], 'config/design-field.php'),
        ));
    }

    /**
     * Gives the plugin a configuration the way the tests of the other screens do: the
     * array the config file would return.
     *
     * @param array<string,mixed> $config
     */
    private function configure(array $config): Groups
    {
        self::$config = $config;
        self::$reads = 0;

        $groups = new class() extends Groups {
            public function config(): array
            {
                ConfigFallbackTest::$reads++;

                return ConfigFallbackTest::$config + parent::config();
            }
        };

        $this->app->instance(Groups::class, $groups);
        $this->app->forgetInstance(Health::class);
        $this->signIn();

        return $groups;
    }

    /**
     * Points the app at a config folder holding these files, read by the real service.
     *
     * @param array<string,string> $files Path under `config/` => contents
     */
    private function useConfigFiles(array $files): void
    {
        if ($this->configDir !== null) {
            File::deleteDirectory($this->configDir);
        }

        $this->configDir = sys_get_temp_dir() . '/design-field-config-' . bin2hex(random_bytes(6));

        foreach ($files as $path => $contents) {
            File::ensureDirectoryExists(dirname("$this->configDir/$path"));
            File::put("$this->configDir/$path", $contents);
        }

        $this->app->useConfigPath($this->configDir);
        $this->app->instance(Groups::class, new Groups());
        $this->app->forgetInstance(Health::class);
        $this->signIn();
    }

    private function signIn(): void
    {
        $this->actingAs(User::query()->where('admin', true)->firstOrFail(), Cms::config()->authGuard);
        $this->app->make('request')->setUserResolver(static fn(?string $guard = null) => auth()->guard($guard)->user());
    }

    /**
     * The plugin's control panel alert as the signed-in user gets it, null when there is none.
     */
    private function alert(): ?string
    {
        foreach ($this->app->make(Alerts::class)->get() as $alert) {
            if (str_contains($alert['content'], 'Design Field')) {
                return $alert['content'];
            }
        }

        return null;
    }

    /**
     * The entry's content column: every field value as the database holds it.
     */
    private function storedContent(int $id): string
    {
        return (string)DB::table(Table::ELEMENTS_SITES)->where('elementId', $id)->value('content');
    }

    /**
     * A saved entry of type `blockCta` with a Design field that has no profile setting.
     *
     * @param array<string,string> $design
     */
    private function createBlock(array $design): Entry
    {
        $field = new Design(['name' => 'Design', 'handle' => 'design']);
        self::assertTrue(Fields::saveField($field));

        $type = new EntryType(['name' => 'CTA', 'handle' => 'blockCta']);
        $type->getFieldLayout()->tab('Content', static fn(FieldLayoutTab $tab) => $tab->add(CustomField::make($field)));
        self::assertTrue(EntryTypes::saveEntryType($type));

        $section = new Section([
            'name' => 'Blocks',
            'handle' => 'blocks',
            'type' => SectionType::Channel,
            'siteSettings' => [new SectionSiteSettings(['siteId' => Sites::getPrimarySite()->id])],
            'entryTypes' => [$type],
        ]);
        self::assertTrue(Sections::saveSection($section));

        $block = new Entry();
        $block->sectionId = $section->id;
        $block->typeId = $type->id;
        $block->title = 'Call';
        $block->setFieldValue('design', $design);
        self::assertTrue(Elements::saveElement($block), implode(' ', $block->errors()->all()));

        return $block;
    }
}
