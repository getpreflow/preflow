<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * What kind of content a type or a block holds, as an admin tints it: pages, reference data,
 * assets, member accounts, shop items, or live tools. A type declares it as "family" in its
 * schema (its model JSON file, or the admin JSON file of a model class), a block in its #[Block]
 * attribute (preflow/blocks). Nothing here maps a family to a colour or an icon: that is the
 * admin's business (Folio tints its type tiles with it).
 *
 * @since 0.75
 */
enum TypeFamily: string
{
    case Page = 'page';
    case Reference = 'reference';
    case Asset = 'asset';
    case Member = 'member';
    case Shop = 'shop';
    case Live = 'live';
}
