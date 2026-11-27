<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * The text that names a record wherever one is listed (search hits, activity rows, relation
 * labels): its type's titleField, or else the first field of type "string" that is not hidden
 * and holds no personal data, read in a locale. A record without such text is named by its id.
 * Personal data never becomes a title by default (since 0.78), since titles are stored in the
 * activity log and shown in lists: a field marked "personal": true, or one that holds an e-mail
 * address (of type or "format" "email", with an "email" rule, or named email, mail, e_mail, email_address,
 * emailAddress or ending in _email/Email, in any case).
 * A type that wants one as its title names it as titleField.
 *
 * @since 0.77
 */
final class RecordTitle
{
    /** @param ?string $locale the locale to read a translatable title in; null: the type's default locale */
    public static function of(DynamicRecord $record, ?string $locale = null): string
    {
        $type = $record->getType();
        $field = self::field($type);
        if ($field !== null) {
            $value = $record->localized($locale ?? $type->locales->default)->get($field);
            // A declared titleField may be a number field; the default rule reads strings only.
            if ($type->titleField !== null && (is_int($value) || is_float($value))) {
                $value = (string) $value;
            }
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return (string) $record->getId();
    }

    /**
     * The field that names the type's records: its titleField, else the first field of type
     * "string" that is not hidden and holds no personal data; null: none (records are named by
     * their id).
     *
     * @since 0.93.0
     */
    public static function field(TypeDefinition $type): ?string
    {
        return $type->titleField ?? self::defaultField($type);
    }

    /** The first field of type "string" that is not hidden and holds no personal data, or null. */
    private static function defaultField(TypeDefinition $type): ?string
    {
        foreach ($type->fields as $name => $def) {
            if ($def->type === 'string' && ($def->config['hidden'] ?? false) !== true && !self::personal((string) $name, $def)) {
                return $name;
            }
        }

        return null;
    }

    private static function personal(string $name, TypeFieldDefinition $field): bool
    {
        // By what the field is first: marked personal, an e-mail type or format, an email rule.
        if (($field->config['personal'] ?? false) === true || $field->type === 'email' || ($field->config['format'] ?? null) === 'email') {
            return true;
        }
        foreach ([...$field->validate, ...(array) ($field->config['validate'] ?? [])] as $rule) {
            if (is_string($rule) && strtolower(strtok($rule, ':') ?: $rule) === 'email') {
                return true;
            }
        }

        // Then by name, any case: email, mail, e_mail, email_address, emailAddress, contact_email, contactEmail.
        $plain = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $name));
        if (in_array($plain, ['email', 'emailaddress', 'mail'], true)) {
            return true;
        }

        return preg_match('/[_-]e?[_-]?mail(?:[_-]?address)?$/i', $name) === 1 || preg_match('/[a-z0-9]Email(?:Address)?$/', $name) === 1;
    }
}
