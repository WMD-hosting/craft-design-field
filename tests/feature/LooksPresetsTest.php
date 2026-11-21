<?php
declare(strict_types=1);

namespace wmd\designfield\tests\feature;

use CraftCms\Cms\Cms;
use CraftCms\Cms\Field\FieldContext;
use CraftCms\Cms\Http\Middleware\RequireAdmin;
use CraftCms\Cms\Http\Middleware\RequireAdminChanges;
use CraftCms\Cms\Plugin\Testing\PluginTestCase;
use CraftCms\Cms\User\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use wmd\designfield\fields\Design;
use wmd\designfield\models\Group;
use wmd\designfield\services\Groups;
use wmd\designfield\services\SettingsFile;

/**
 * The settings page's Looks and Presets tabs: what they save goes to the settings file
 * beside the config file, which the Groups service lays over `design-field.php`.
 */
final class LooksPresetsTest extends PluginTestCase
{
    private const string PAGE = '/admin/settings/plugins/design-field';

    private const string SAVE = '/admin/design-field/settings/';

    /** @var array<string,mixed> What `design-field.php` returns in a test */
    public static array $config = [];

    private string $file;

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
                'tone' => ['label' => 'Tone', 'options' => ['light' => 'bg-white', 'dark' => 'bg-black'], 'default' => 'light'],
                'spacing' => ['label' => 'Spacing', 'options' => ['tight' => 'py-4', 'normal' => 'py-8', 'loose' => 'py-16'], 'default' => 'normal'],
                'border' => ['label' => 'Border', 'input' => 'toggle', 'options' => ['off' => '', 'on' => 'border'], 'default' => 'off'],
            ],
            'profiles' => ['blockCta' => ['tone', 'spacing', 'border'], 'blockTeam' => ['tone']],
        ];

        $this->file = sys_get_temp_dir() . '/design-field-settings-' . bin2hex(random_bytes(4)) . '/design-field.settings.json';
        $this->app->instance(SettingsFile::class, new SettingsFile($this->file));
        $this->readConfig();

        $this->actingAs(User::query()->findOrFail(1));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->file));

        parent::tearDown();
    }

    // Storage
    // =========================================================================

    public function testWithoutASettingsFileTheConfigFileIsTheWholeConfiguration(): void
    {
        self::assertFileDoesNotExist($this->file);
        self::assertSame([], $this->designGroups()->config()['presets']);
        self::assertSame(Group::INPUT_BUTTONS, $this->designGroups()->getRegistry()->profiles['blockCta']['tone']->input);
    }

    public function testASavedLookWinsOverTheConfigFilesAndItsPresetsApply(): void
    {
        self::$config['inputs'] = ['groups' => ['tone' => ['input' => 'swatches'], 'spacing' => ['input' => 'slider']]];
        File::ensureDirectoryExists(dirname($this->file));
        File::put($this->file, json_encode([
            'inputs' => ['groups' => ['tone' => ['input' => 'select']]],
            'presets' => ['blockCta' => ['Night' => ['tone' => 'dark']]],
        ]));
        $this->readConfig();

        $registry = $this->designGroups()->getRegistry();
        self::assertSame(Group::INPUT_SELECT, $registry->profiles['blockCta']['tone']->input, 'saved on the page');
        self::assertSame(Group::INPUT_SLIDER, $registry->profiles['blockCta']['spacing']->input, 'from the config file, untouched');
        self::assertSame(['blockCta' => ['Night' => ['tone' => 'dark']]], $registry->presets);
    }

    public function testPresetsInTheConfigFileWinAndAFileThatIsNotJsonIsIgnored(): void
    {
        self::$config['presets'] = ['*' => ['Plain' => ['tone' => 'light']]];
        File::ensureDirectoryExists(dirname($this->file));
        File::put($this->file, json_encode(['presets' => ['blockCta' => ['Night' => ['tone' => 'dark']]]]));
        $this->readConfig();
        self::assertSame(['*' => ['Plain' => ['tone' => 'light']]], $this->designGroups()->getRegistry()->presets);

        File::put($this->file, '{ not json');
        $this->readConfig();
        self::assertSame(Group::INPUT_BUTTONS, $this->designGroups()->getRegistry()->profiles['blockCta']['tone']->input);
    }

    // Presets tab
    // =========================================================================

    public function testThePresetsTabSavesItsRowsAndThePanelGetsThePreset(): void
    {
        $props = self::props($this->get(self::PAGE . '?tab=presets'));
        self::assertStringEndsWith('/design-field/settings/presets', $props->submit->url);
        self::assertSame([], $props->ui->values->presets);

        $table = self::field($props, 'presets');
        self::assertSame('craft:table', $table->component);
        self::assertSame(['blockCta', 'blockTeam', '*'], array_column($table->props->columns->profile->options, 'value'));

        $this->post(self::SAVE . 'presets', ['presets' => [
            ['profile' => 'blockCta', 'name' => 'Night', 'choices' => 'tone=dark, border=on'],
            ['profile' => '*', 'name' => 'Plain', 'choices' => 'tone=light'],
            ['profile' => 'blockCta', 'name' => '', 'choices' => 'tone=dark'],
        ]])->assertRedirect();

        self::assertSame(
            ['presets' => ['blockCta' => ['Night' => ['tone' => 'dark', 'border' => 'on']], '*' => ['Plain' => ['tone' => 'light']]]],
            json_decode((string)file_get_contents($this->file), true),
            'a row without a name is not a preset',
        );

        // The next request: the tab shows the rows, and the block's panel has the presets.
        $this->readConfig();
        self::assertSame(
            [['profile' => 'blockCta', 'name' => 'Night', 'choices' => 'tone=dark, border=on'], ['profile' => '*', 'name' => 'Plain', 'choices' => 'tone=light']],
            json_decode(json_encode(self::props($this->get(self::PAGE . '?tab=presets'))->ui->values->presets), true),
        );
        $field = new Design(['handle' => 'design', 'profile' => 'blockCta']);
        $panel = $field->uiControl(new FieldContext('design', $field->normalizeValue([], null)));
        self::assertSame(['Night', 'Plain'], array_column($panel->props([])['presets'], 'label'));

        // Saving no rows takes the presets, and with nothing left the file, away.
        $this->post(self::SAVE . 'presets', ['presets' => []])->assertRedirect();
        self::assertFileDoesNotExist($this->file);
    }

    public function testAPresetThatSetsWhatNoBlockHasIsNotSaved(): void
    {
        foreach ([
            'tone=neon' => 'not one of its options',
            'glow=on' => 'not a design group',
            'tone dark' => 'not a group=key choice',
        ] as $choices => $message) {
            $response = $this->post(self::SAVE . 'presets', ['presets' => [['profile' => 'blockCta', 'name' => 'Odd', 'choices' => $choices]]]);
            $response->assertSessionHasErrors('presets');
            self::assertStringContainsString($message, session('errors')->first('presets'));
        }

        self::assertFileDoesNotExist($this->file);
    }

    public function testPresetsSetInTheConfigFileShowReadOnly(): void
    {
        self::$config['presets'] = ['*' => ['Plain' => ['tone' => 'light']]];
        $this->readConfig();

        $props = self::props($this->get(self::PAGE . '?tab=presets'));

        self::assertObjectNotHasProperty('submit', $props);
        self::assertSame('readOnly', self::field($props, 'presets')->mode);
        self::assertSame('Plain', $props->ui->values->presets[0]->name);
        self::assertContains('presets-in-file', array_column($props->ui->nodes, 'uid'));
        $this->post(self::SAVE . 'presets', ['presets' => []])->assertStatus(409);
    }

    // Looks tab
    // =========================================================================

    public function testTheLooksTabListsEveryOptionWithTheLooksItCanTake(): void
    {
        $props = self::props($this->get(self::PAGE . '?tab=looks'));
        $looks = json_decode(json_encode($props->ui->values->looks), true);

        self::assertStringEndsWith('/design-field/settings/looks', $props->submit->url);
        self::assertSame(['tone', 'border', 'spacing'], array_keys($looks), 'most used first, then by name');
        self::assertSame(['option' => 'Tone (tone)', 'blocks' => '2', 'look' => '', 'section' => ''], $looks['tone']);

        // The styles in use can be swapped: here buttons and the toggle.
        self::assertSame(['buttons' => '', 'toggle' => ''], (array)$props->ui->values->styles);
    }

    public function testALookASwapAndASectionAreSavedAndTheRegistryReadsThem(): void
    {
        // Something the tab does not show is in the file already: a look for one block only.
        File::ensureDirectoryExists(dirname($this->file));
        File::put($this->file, json_encode([
            'inputs' => ['profiles' => ['blockTeam' => ['tone' => ['input' => 'select']]], 'meta' => ['tone' => ['dark' => ['label' => 'Night']]]],
            'presets' => ['*' => ['Plain' => ['tone' => 'light']]],
        ]));

        $this->post(self::SAVE . 'looks', [
            'styles' => ['buttons' => 'select', 'toggle' => ''],
            'looks' => [
                'tone' => ['option' => 'Tone (tone)', 'blocks' => '2', 'look' => 'labels', 'section' => ' Colour '],
                'spacing' => ['look' => '', 'section' => ''],
                'border' => ['look' => 'no-such-look', 'section' => ''],
                'gone' => ['look' => 'select', 'section' => 'Old'],
            ],
        ])->assertRedirect();

        $saved = json_decode((string)file_get_contents($this->file), true);
        self::assertSame(['buttons' => 'select'], $saved['inputs']['styles']);
        self::assertSame(['tone' => ['input' => 'buttons', 'iconsOnly' => false, 'textOnly' => true]], $saved['inputs']['groups'], 'an option that is gone and a look that is none are left out');
        self::assertSame('Colour', $saved['inputs']['sections']['tone']);
        self::assertSame(['blockTeam' => ['tone' => ['input' => 'select', 'iconsOnly' => false]]], $saved['inputs']['profiles'], 'kept as saved');
        self::assertSame(['tone' => ['dark' => ['label' => 'Night']]], $saved['inputs']['meta'], 'kept as saved');
        self::assertSame(['*' => ['Plain' => ['tone' => 'light']]], $saved['presets'], 'the other tab\'s work is kept');

        $this->readConfig();
        $registry = $this->designGroups()->getRegistry();
        self::assertTrue($registry->profiles['blockCta']['tone']->textOnly, 'its own look');
        self::assertSame('Colour', $registry->profiles['blockCta']['tone']->section);
        self::assertSame(Group::INPUT_SELECT, $registry->profiles['blockCta']['spacing']->input, 'swapped with every other buttons option');
        self::assertSame(Group::INPUT_SELECT, $registry->profiles['blockTeam']['tone']->input, 'this block only');
        self::assertSame('Night', $registry->profiles['blockCta']['tone']->options['dark']['label']);

        // The tab shows what was saved.
        $values = self::props($this->get(self::PAGE . '?tab=looks'))->ui->values;
        self::assertSame('labels', $values->looks->tone->look);
        self::assertSame('Colour', $values->looks->tone->section);
        self::assertSame('select', $values->styles->buttons);
    }

    // Who and where
    // =========================================================================

    public function testWhereAdminChangesAreOffTheTabsShowButDoNotSave(): void
    {
        Cms::config()->allowAdminChanges = false;

        foreach (['looks', 'presets'] as $tab) {
            $props = self::props($this->get(self::PAGE . "?tab=$tab"));
            self::assertObjectNotHasProperty('submit', $props, $tab);
            self::assertTrue($props->readOnly, $tab);
            $this->post(self::SAVE . $tab, [$tab => []])->assertForbidden();
        }

        self::assertFileDoesNotExist($this->file);
    }

    public function testOnlyAdminsSave(): void
    {
        foreach (['looks', 'presets'] as $tab) {
            $middleware = Route::gatherRouteMiddleware(Route::getRoutes()->match(Request::create(self::SAVE . $tab, 'POST')));
            self::assertContains(RequireAdmin::class, $middleware, $tab);
            self::assertContains(RequireAdminChanges::class, $middleware, $tab);
        }

        DB::table('users')->where('id', 1)->update(['admin' => false]);
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::query()->findOrFail(1));

        $this->post(self::SAVE . 'presets', ['presets' => [['profile' => '*', 'name' => 'Plain', 'choices' => 'tone=light']]])->assertForbidden();
        self::assertFileDoesNotExist($this->file);
    }

    public function testAConfigurationThatDoesNotBuildSaysSo(): void
    {
        self::$config['profiles']['blockCta'] = ['tone', 'notAGroup'];
        $this->readConfig();

        foreach (['looks', 'presets'] as $tab) {
            self::assertContains("$tab-error", array_column(self::props($this->get(self::PAGE . "?tab=$tab"))->ui->nodes, 'uid'));
        }
    }

    // Helpers
    // =========================================================================

    /** The configuration as a new request reads it: both files are read once per request. */
    private function readConfig(): void
    {
        $this->app->instance(Groups::class, new class() extends Groups {
            public function fileConfig(): array
            {
                return LooksPresetsTest::$config;
            }
        });
    }

    private function designGroups(): Groups
    {
        return $this->app->make(Groups::class);
    }

    /** The Inertia page props of the screen, as the Ui page receives them. */
    private static function props(TestResponse $response): object
    {
        $html = (string)$response->assertOk()->getContent();
        preg_match('#<script data-page="app" type="application/json">(.*?)</script>#s', $html, $m);

        return json_decode($m[1] ?? 'null', false, flags: JSON_THROW_ON_ERROR)->props;
    }

    /** The control of the field at a path. */
    private static function field(object $props, string $path): object
    {
        foreach ($props->ui->nodes as $node) {
            if (isset($node->control->path) && implode('.', (array)$node->control->path) === $path) {
                return $node->control;
            }
        }

        self::fail("No field at $path.");
    }
}
