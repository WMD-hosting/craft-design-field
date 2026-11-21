<?php
declare(strict_types=1);

namespace wmd\designfield\tests\feature;

use CraftCms\Cms\Cms;
use CraftCms\Cms\Http\Middleware\RequireAdmin;
use CraftCms\Cms\Http\Middleware\RequireAdminChanges;
use CraftCms\Cms\Plugin\Testing\PluginTestCase;
use CraftCms\Cms\User\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use wmd\designfield\models\Group;
use wmd\designfield\services\Groups;
use wmd\designfield\services\SettingsFile;

/**
 * The Looks tab's labels and icons of one option's choices (`?tab=looks&option=`), and
 * "Try these looks": a block's panel built with the looks the form holds, before a save.
 */
final class LabelsIconsTest extends PluginTestCase
{
    private const string PAGE = '/admin/settings/plugins/design-field?tab=looks';

    private const string REFRESH = '/admin/design-field/looks/ui';

    private const string SAVE = '/admin/design-field/settings/labels';

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
                'tone' => ['label' => 'Tone', 'options' => ['light' => 'bg-white', 'dark' => 'bg-black'], 'default' => 'light', 'optionMeta' => ['light' => ['icon' => 'sun']]],
                'columns' => ['label' => 'Columns', 'options' => [1 => 'grid-cols-1', 2 => 'grid-cols-2', 3 => 'grid-cols-3'], 'default' => 2],
                'border' => ['label' => 'Border', 'input' => 'toggle', 'options' => ['off' => '', 'on' => 'border'], 'default' => 'off'],
            ],
            'profiles' => ['blockCta' => ['tone', 'columns', 'border'], 'blockTeam' => ['tone']],
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

    // Labels and icons
    // =========================================================================

    public function testTheLooksTabOffersEveryOptionAndOpensItsChoices(): void
    {
        $this->save(['inputs' => ['meta' => ['tone' => ['dark' => ['label' => 'Night', 'icon' => 'moon']]]]]);

        // On the tab itself: a picker that opens an option, the ones with other labels marked.
        $picker = self::controls(self::props($this->get(self::PAGE))->ui->nodes)['labelsOption'];
        self::assertSame('design-field:block-picker', $picker->component);
        self::assertSame(['', 'tone', 'border', 'columns'], array_column($picker->props->options, 'value'));
        self::assertSame('Tone (tone) ✎', $picker->props->options[1]->label);
        self::assertStringContainsString('option=tone', $picker->props->options[1]->url);

        $props = self::props($this->get(self::PAGE . '&option=tone'));
        self::assertStringEndsWith('/design-field/settings/labels?option=tone', $props->submit->url);
        self::assertStringEndsWith('/design-field/looks/ui?option=tone', $props->refreshUrl);
        self::assertSame(
            [
                'k_light' => ['key' => 'light', 'configured' => 'Light (sun)', 'label' => '', 'icon' => null],
                'k_dark' => ['key' => 'dark', 'configured' => 'Dark', 'label' => 'Night', 'icon' => 'moon'],
            ],
            json_decode(json_encode($props->ui->values->labels), true),
        );

        $table = self::controls($props->ui->nodes)['labels'];
        self::assertSame('craft:table', $table->component);
        self::assertTrue($table->props->keyed);
        self::assertSame('heading', $table->props->columns->configured->type);

        // An option no block has shows the Looks tab itself.
        self::assertStringEndsWith('/design-field/settings/looks', self::props($this->get(self::PAGE . '&option=gone'))->submit->url);
    }

    public function testASaveReplacesThatOptionsLabelsOnlyAndThePanelShowsThem(): void
    {
        $this->save([
            'inputs' => [
                'groups' => ['tone' => ['input' => 'select']],
                'profiles' => ['blockTeam' => ['tone' => ['input' => 'buttons']]],
                'meta' => ['tone' => ['dark' => ['label' => 'Night'], 'light' => ['label' => 'Day']], 'border' => ['on' => ['label' => 'Framed']]],
            ],
            'presets' => ['*' => ['Plain' => ['tone' => 'light']]],
        ]);

        $this->post(self::SAVE . '?option=tone', [
            'labelsOption' => 'tone',
            'try' => '',
            'labels' => [
                'k_light' => ['key' => 'light', 'configured' => 'Light (sun)', 'label' => '', 'icon' => null],
                'k_dark' => ['key' => 'dark', 'configured' => 'Dark', 'label' => ' Midnight ', 'icon' => 'moon'],
                'k_gone' => ['label' => 'Old', 'icon' => ''],
            ],
        ])->assertRedirectContains('tab=looks&option=tone');

        $saved = json_decode((string)file_get_contents($this->file), true);
        self::assertSame(
            ['tone' => ['dark' => ['label' => 'Midnight', 'icon' => 'moon']], 'border' => ['on' => ['label' => 'Framed']]],
            $saved['inputs']['meta'],
            'the emptied row and a choice the option does not have are gone; the other option\'s labels stay',
        );
        self::assertSame(['tone' => ['input' => 'select', 'iconsOnly' => false]], $saved['inputs']['groups']);
        self::assertSame(['blockTeam' => ['tone' => ['input' => 'buttons', 'iconsOnly' => false]]], $saved['inputs']['profiles']);
        self::assertSame(['*' => ['Plain' => ['tone' => 'light']]], $saved['presets']);

        // The site reads it on every block; the config file's own label is what an empty cell keeps.
        $this->readConfig();
        $tone = $this->app->make(Groups::class)->getRegistry()->profiles['blockTeam']['tone'];
        self::assertSame(['label' => 'Midnight', 'icon' => 'moon'], array_intersect_key($tone->options['dark'], ['label' => 1, 'icon' => 1]));
        self::assertSame(['label' => 'Light', 'icon' => 'sun'], array_intersect_key($tone->options['light'], ['label' => 1, 'icon' => 1]));
    }

    public function testNumericKeysKeepTheirRows(): void
    {
        $rows = json_decode(json_encode(self::props($this->get(self::PAGE . '&option=columns'))->ui->values->labels), true);
        self::assertSame(['k_1', 'k_2', 'k_3'], array_keys($rows));

        $this->post(self::SAVE . '?option=columns', ['labels' => [
            'k_1' => ['label' => 'One', 'icon' => ''],
            'k_2' => ['label' => '', 'icon' => ''],
            'k_3' => ['label' => 'Three', 'icon' => 'table-columns'],
        ]])->assertRedirect();

        $this->readConfig();
        $columns = $this->app->make(Groups::class)->getRegistry()->profiles['blockCta']['columns'];
        self::assertSame(['One', '2', 'Three'], array_column($columns->options, 'label'));
        self::assertSame('table-columns', $columns->options[3]['icon']);
    }

    public function testAnIconThatIsNoIconNameAndALongLabelAreRefusedAndTheRowsComeBack(): void
    {
        $this->post(self::SAVE . '?option=tone', ['labels' => [
            'k_light' => ['label' => str_repeat('x', 61), 'icon' => ''],
            'k_dark' => ['label' => 'Night', 'icon' => '<svg onload=alert(1)>'],
        ]])->assertSessionHasErrors('labels');
        self::assertFileDoesNotExist($this->file, 'nothing of a refused save is written');

        // The screen is built again on the redirect: with the rows as typed.
        $rows = json_decode(json_encode(self::props($this->get(self::PAGE . '&option=tone'))->ui->values->labels), true);
        self::assertSame('Night', $rows['k_dark']['label']);

        $this->post(self::SAVE . '?option=gone', ['labels' => []])->assertNotFound();
        $this->post(self::SAVE, ['labels' => []])->assertNotFound();
    }

    public function testWhereAdminChangesAreOffTheLabelsShowButDoNotSave(): void
    {
        $this->save(['inputs' => ['meta' => ['tone' => ['dark' => ['label' => 'Night']]]]]);
        Cms::config()->allowAdminChanges = false;

        $props = self::props($this->get(self::PAGE . '&option=tone'));
        self::assertObjectNotHasProperty('submit', $props);
        self::assertObjectNotHasProperty('refreshUrl', $props);
        self::assertSame('readOnly', self::controls($props->ui->nodes)['labels']->mode);
        self::assertSame('Night', $props->ui->values->labels->k_dark->label);

        $this->post(self::SAVE . '?option=tone', ['labels' => []])->assertForbidden();
        self::assertFileExists($this->file);
    }

    public function testTheSaveIsForAdminsWhereAdminChangesAreAllowedAndTheRefreshForAdmins(): void
    {
        $save = Route::gatherRouteMiddleware(Route::getRoutes()->match(Request::create(self::SAVE, 'POST')));
        self::assertContains(RequireAdmin::class, $save);
        self::assertContains(RequireAdminChanges::class, $save);

        $refresh = Route::gatherRouteMiddleware(Route::getRoutes()->match(Request::create(self::REFRESH, 'POST')));
        self::assertContains(RequireAdmin::class, $refresh);
        self::assertNotContains(RequireAdminChanges::class, $refresh, 'it reads only');
    }

    // Try these looks
    // =========================================================================

    public function testTheLooksTabShowsABlockWithTheLooksItsFormHoldsBeforeASave(): void
    {
        $props = self::props($this->get(self::PAGE));
        $controls = self::controls($props->ui->nodes);

        // Nothing is built until a block is picked.
        self::assertStringEndsWith('/design-field/looks/ui', $props->refreshUrl);
        self::assertSame('', $props->ui->values->try);
        self::assertSame(['', 'blockCta', 'blockTeam'], array_column($controls['try']->props->options, 'value'));
        self::assertTrue($controls['try']->reactive);
        self::assertArrayNotHasKey('design', $controls);

        // As the Ui page posts a pick of the block: the whole form, unsaved looks in it.
        $ui = $this->postJson(self::REFRESH, ['scope' => [], 'values' => [
            'styles' => ['buttons' => '', 'toggle' => 'select'],
            'looks' => [
                'tone' => ['option' => 'Tone (tone)', 'blocks' => '2', 'look' => 'select', 'section' => 'Colour'],
                'columns' => ['look' => '', 'section' => ''],
                'border' => ['look' => '', 'section' => ''],
            ],
            'try' => 'blockCta',
        ]])->assertOk()->json('ui');

        $controls = self::controls(json_decode(json_encode($ui['nodes'])));
        self::assertSame('select', $controls['design.tone']->props->presentation, 'the row\'s look');
        self::assertSame('craft:choice', $controls['design.border']->component, 'the swapped style: a toggle as a dropdown');
        self::assertSame('buttons', $controls['design.columns']->props->presentation, 'untouched');
        self::assertFalse($controls['design']->uis[0]->refreshable, 'a pick in this panel does not ask for it again: the form\'s looks would be lost');

        // The form comes back as typed, not as saved.
        self::assertSame('select', $ui['values']['looks']['tone']['look']);
        self::assertSame('Colour', $ui['values']['looks']['tone']['section']);
        self::assertSame('select', $ui['values']['styles']['toggle']);
        self::assertSame('blockCta', $ui['values']['try']);
        self::assertFileDoesNotExist($this->file, 'nothing was saved');
        self::assertSame(Group::INPUT_BUTTONS, $this->app->make(Groups::class)->getRegistry()->profiles['blockCta']['tone']->input, 'and the request\'s own looks are back');

        // Only the whole tab can be asked for.
        $this->postJson(self::REFRESH, ['scope' => ['design'], 'values' => []])->assertStatus(422);
    }

    public function testLabelsAndIconsCanBeTriedOnABlockThatHasTheOption(): void
    {
        $controls = self::controls(self::props($this->get(self::PAGE . '&option=border'))->ui->nodes);
        self::assertSame(['', 'blockCta'], array_column($controls['try']->props->options, 'value'), 'only blocks with the option');

        $ui = $this->postJson(self::REFRESH . '?option=tone', ['scope' => [], 'values' => [
            'labels' => ['k_light' => ['label' => '', 'icon' => ''], 'k_dark' => ['label' => 'Night', 'icon' => 'moon']],
            'try' => 'blockTeam',
        ]])->assertOk()->json('ui');

        $options = array_column(self::controls(json_decode(json_encode($ui['nodes'])))['design.tone']->props->options, null, 'value');
        self::assertStringContainsString('Night', json_encode($options['dark']));
        self::assertSame('Night', $ui['values']['labels']['k_dark']['label'], 'the rows come back as typed');
        self::assertSame('Light (sun)', $ui['values']['labels']['k_light']['configured']);
        self::assertFileDoesNotExist($this->file);
    }

    // Helpers
    // =========================================================================

    /** @param array<string,mixed> $data The settings file as saved before the request */
    private function save(array $data): void
    {
        File::ensureDirectoryExists(dirname($this->file));
        File::put($this->file, json_encode($data));
        $this->readConfig();
    }

    /** A new request: the config and the settings file are read again. */
    private function readConfig(): void
    {
        $this->app->instance(Groups::class, new class() extends Groups {
            public function fileConfig(): array
            {
                return LabelsIconsTest::$config;
            }
        });
    }

    /** The Inertia page props of the screen, as the Ui page receives them. */
    private static function props(TestResponse $response): object
    {
        $html = (string)$response->assertOk()->getContent();
        preg_match('#<script data-page="app" type="application/json">(.*?)</script>#s', $html, $m);

        return json_decode($m[1] ?? 'null', false, flags: JSON_THROW_ON_ERROR)->props;
    }

    /**
     * Every control of a Ui by its path, a panel's own among them.
     *
     * @param array<int,object> $nodes
     * @return array<string,object>
     */
    private static function controls(array $nodes): array
    {
        $found = [];

        foreach ($nodes as $node) {
            if (isset($node->control->path)) {
                $found[implode('.', (array)$node->control->path)] = $node->control;

                foreach ($node->control->uis ?? [] as $ui) {
                    // A table's rows are Uis too: only a panel's options are wanted here.
                    if ($node->control->component !== 'craft:table') {
                        $found += self::controls($ui->nodes);
                    }
                }
            }

            $found += self::controls($node->children ?? []);
        }

        return $found;
    }
}
