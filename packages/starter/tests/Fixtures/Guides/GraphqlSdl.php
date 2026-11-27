<?php

declare(strict_types=1);

namespace Tests\Fixtures\Guides;

/**
 * A GraphQL schema (SDL text) built from the core's description, as the preflow.dev guide shows
 * it (the guide quotes this file; tests/Api/GuideSamplesTest runs it). Hand the text to the
 * GraphQL library your site picks (webonyx/graphql-php's BuildSchema::build(), for one) and
 * resolve each query with the read class: RecordOutput::toArray($record, new ReadOptions(locale:
 * $args['locale'] ?? null)).
 *
 *     $sdl = GraphqlSdl::build($description->toArray(), ['job', 'company']);
 *
 * Each type becomes an object type with "_id" and its fields; each field's GraphQL type comes from
 * the shape its "value" names, and a relation to a type in the schema becomes that object type
 * (its resolver reads the related record by the id). "required" adds the "!", not where show_when may
 * hide the field (it reads null then). A translatable field is typed by one language's value: resolve it with a locale.
 */
final class GraphqlSdl
{
    /** Output shapes as GraphQL types. Any other shape (structured: a link, a table, blocks) is JSON, a scalar the schema declares. */
    private const array SCALARS = [
        'string' => 'String',
        'rich_text' => 'String',
        'date' => 'String',
        'datetime' => 'String',
        'time' => 'String',
        'number' => 'Float',
        'boolean' => 'Boolean',
        'id' => 'ID',
        'id_list' => '[ID!]',
        'string_list' => '[String!]',
    ];

    /**
     * @param array<string, mixed> $description Description::toArray()
     * @param list<string>         $types       the types the schema serves, by key
     */
    public static function build(array $description, array $types): string
    {
        $objects = [];
        $queries = [];
        $usesJson = false;
        foreach ($types as $key) {
            $name = self::typeName($key);
            $lines = ['  _id: ID!'];
            foreach ($description['types'][$key]['fields'] as $field => $about) {
                $type = self::fieldType($about, $types);
                $usesJson = $usesJson || str_contains($type, 'JSON');
                $lines[] = '  ' . $field . ': ' . $type;
            }
            $objects[] = "type {$name} {\n" . implode("\n", $lines) . "\n}\n";
            $queries[] = "  {$key}(id: ID!, locale: String): {$name}";
            $queries[] = "  {$key}List(page: Int = 1, locale: String): [{$name}!]!";
        }

        return ($usesJson ? "scalar JSON\n\n" : '')
            . implode("\n", $objects)
            . "\ntype Query {\n" . implode("\n", $queries) . "\n}\n";
    }

    /**
     * @param array<string, mixed> $field a field of the description
     * @param list<string>         $types
     */
    private static function fieldType(array $field, array $types): string
    {
        $target = $field['target'] ?? null;
        $type = match (true) {
            is_string($target) && in_array($target, $types, true) && $field['value'] === 'id' => self::typeName($target),
            is_string($target) && in_array($target, $types, true) && $field['value'] === 'id_list' => '[' . self::typeName($target) . '!]',
            default => self::SCALARS[$field['value']] ?? 'JSON',
        };

        return $field['required'] && !(is_array($field['config']) && isset($field['config']['show_when'])) ? $type . '!' : $type;
    }

    /** "company_page" → "CompanyPage". */
    private static function typeName(string $key): string
    {
        return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $key)));
    }
}
