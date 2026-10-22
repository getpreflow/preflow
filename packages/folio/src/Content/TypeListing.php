<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

final readonly class TypeListing
{
    /**
     * @param array<string, mixed>|null $frontend the type's "frontend" block, if it has one
     * @param array<string, mixed>      $list     the type's "list" block (admin list config)
     * @param bool                      $publishing working copy + published snapshot ("publishing"; default: frontend types and singletons)
     * @param string|null              $users    the user population this type holds accounts of ("users"), if it is a user type
     * @param bool                     $transfer whether export, import, pull and backups carry its records (user types: false unless "transfer": true)
     * @param string                   $section  sidebar section ("section"): a built-in one (pages, content, library, settings) or a new one
     * @param int                      $order    position within the section ("order"; lower first)
     * @param string                   $icon     sidebar icon ("icon", a ui-* sprite id); empty: ui-settings for singletons, ui-file otherwise
     * @param bool                     $nav      whether the sidebar lists it ("nav")
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?array $frontend = null,
        public array $list = [],
        public bool $singleton = false,
        public bool $publishing = false,
        public ?string $users = null,
        public bool $transfer = true,
        public string $section = 'content',
        public int $order = 100,
        public string $icon = '',
        public bool $nav = true,
    ) {}
}
