<?php

namespace App\Enums;

/**
 * The kind of payload a message carries.
 */
enum MessageType: string
{
    case Text = 'text';
    case Image = 'image';
    case Gif = 'gif';
    case ViewOnce = 'view_once';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
