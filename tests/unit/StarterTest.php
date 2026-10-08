<?php
declare(strict_types=1);

namespace wmd\designfield\tests\unit;

use PHPUnit\Framework\TestCase;
use wmd\designfield\helpers\Registry;
use wmd\designfield\helpers\SettingsRows;
use wmd\designfield\helpers\Starter;

final class StarterTest extends TestCase
{
    private function registry(string $framework): Registry
    {
        $rows = Starter::rows($framework);

        return Registry::fromConfig(SettingsRows::toConfig($rows['groupRows'], $rows['optionRows'], $rows['profileRows']), static fn() => []);
    }

    public function testEveryFrameworkBuildsFiveOptionsForAnyBlock(): void
    {
        foreach (Starter::FRAMEWORKS as $framework => $label) {
            $groups = $this->registry($framework)->groupsFor('blockAnything');

            self::assertSame(['tone', 'spacing', 'container', 'alignment', 'motion'], array_keys($groups), $label);
            foreach ($groups as $handle => $group) {
                self::assertSame('auto', $group->default, "$label $handle: a block keeps its own look until an editor picks one");
            }
        }
    }

    public function testTheClassesAreTheFrameworksOwn(): void
    {
        $value = fn(string $framework, string $group, string $key) => $this->registry($framework)->groupsFor('x')[$group]->options[$key]['parts']['value'];

        self::assertSame('container', $value('bootstrap', 'container', 'boxed'));
        self::assertSame('text-center', $value('bootstrap', 'alignment', 'center'));
        self::assertSame('mx-auto max-w-7xl px-4', $value('tailwind', 'container', 'boxed'));
        self::assertSame('df-tone-dark', $value('plain', 'tone', 'dark'));
        self::assertSame('df-enter-fade-up', $value('tailwind', 'motion', 'fade-up'), 'entrances come from the shipped stylesheet in every framework');
    }

    public function testThePlainStylesheetHasEveryClassTheRowsUse(): void
    {
        $css = (string)file_get_contents(dirname(__DIR__, 2) . '/src/web/assets/starter/dist/design-field.css');
        $classes = [];
        foreach (Starter::rows('plain')['optionRows'] as $row) {
            foreach (preg_split('/\s+/', (string)$row['value'], -1, PREG_SPLIT_NO_EMPTY) as $class) {
                $classes[$class] = true;
            }
        }

        foreach (array_keys($classes) as $class) {
            self::assertStringContainsString(".$class", $css, "design-field.css defines .$class");
        }
    }

    public function testAnUnknownFrameworkIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Starter::rows('foundation');
    }
}
