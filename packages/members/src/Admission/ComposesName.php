<?php

declare(strict_types=1);

namespace Preflow\Members\Admission;

/**
 * Optional on an Admission: the site collects the name in its own fields (first and last name,
 * a company and a contact) and composes the member's "name" from them. The registration form
 * then has no name field of its own, a posted "name" is ignored, and the stored name is
 * name($input), which validate() also sees as $input['name'].
 */
interface ComposesName
{
    /**
     * @param array<string, mixed> $input the posted fields (name, email and consent normalised, password and next left out)
     * @return string the member's name; an empty one fails registration like a missing name
     */
    public function name(array $input): string;
}
