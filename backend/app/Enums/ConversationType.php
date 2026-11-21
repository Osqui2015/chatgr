<?php

namespace App\Enums;

/**
 * The scope of a conversation.
 */
enum ConversationType: string
{
    case Global = 'global';
    case Direct = 'direct';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
