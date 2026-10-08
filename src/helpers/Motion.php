<?php
/**
 * @link https://wmd.hr/
 * @copyright Copyright (c) WMD d.o.o.
 * @license MIT
 */

namespace wmd\designfield\helpers;

/**
 * The animations a motion tile can play: entrances (one block appears) and transitions
 * (one slide gives way to the next). A choice gets one by naming it (`'motion' => 'cube'`)
 * or by its key matching a preset or an alias (`zoom` plays zoom-in). The tiles only
 * suggest the motion with small CSS keyframes; the site plays the real one.
 *
 * Craft-free.
 *
 * @author WMD
 * @since 1.0.0
 */
class Motion
{
    // Const Properties
    // =========================================================================

    public const ENTRANCE = 'entrance';
    public const TRANSITION = 'transition';

    /**
     * Preset => kind. GroupInput has a keyframe for each.
     */
    public const PRESETS = [
        'fade' => self::ENTRANCE,
        'fade-up' => self::ENTRANCE,
        'fade-down' => self::ENTRANCE,
        'from-left' => self::ENTRANCE,
        'from-right' => self::ENTRANCE,
        'zoom-in' => self::ENTRANCE,
        'zoom-out' => self::ENTRANCE,
        'flip-in' => self::ENTRANCE,
        'blur-in' => self::ENTRANCE,
        'slide' => self::TRANSITION,
        'crossfade' => self::TRANSITION,
        'fade-through' => self::TRANSITION,
        'cube' => self::TRANSITION,
        'coverflow' => self::TRANSITION,
        'flip' => self::TRANSITION,
        'cards' => self::TRANSITION,
        'creative' => self::TRANSITION,
    ];

    /**
     * Common option keys => preset.
     */
    public const ALIASES = [
        'fade-in' => 'fade',
        'slide-up' => 'fade-up',
        'slide-down' => 'fade-down',
        'slide-left' => 'from-right',
        'slide-right' => 'from-left',
        'left' => 'from-left',
        'right' => 'from-right',
        'zoom' => 'zoom-in',
        'scale' => 'zoom-in',
        'blur' => 'blur-in',
        'fade-out-in' => 'fade-through',
        'card-stack' => 'cards',
    ];

    // Public Methods
    // =========================================================================

    /**
     * The preset an option key stands for, if any.
     *
     * @param string $key Option key, kebab or camel case
     * @return string|null
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function match(string $key): ?string
    {
        $key = strtolower((string)preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '-', $key));

        return isset(self::PRESETS[$key]) ? $key : (self::ALIASES[$key] ?? null);
    }

    /**
     * An option's preset: the one it names when that is a preset, else its key's match.
     *
     * @param string|null $named `motion` from the option's config
     * @param string $key
     * @return string|null
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function resolve(?string $named, string $key): ?string
    {
        if ($named !== null) {
            return isset(self::PRESETS[$named]) ? $named : null;
        }

        return self::match($key);
    }

    /**
     * @param string $preset
     * @return string self::ENTRANCE or self::TRANSITION
     *
     * @author WMD
     * @since 1.0.0
     */
    public static function kind(string $preset): string
    {
        return self::PRESETS[$preset] ?? self::ENTRANCE;
    }
}
