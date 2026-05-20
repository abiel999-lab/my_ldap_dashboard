<?php

namespace App\Support\Directory;

class LdapSchemaDefinitionParser
{
    public static function parse(string $schemaType, string $definition): array
    {
        $definition = static::cleanDefinition($definition);

        return match ($schemaType) {
            'attribute_type' => static::parseAttributeType($definition),
            'object_class' => static::parseObjectClass($definition),
            'ldap_syntax' => static::parseLdapSyntax($definition),
            'matching_rule' => static::parseMatchingRule($definition),
            'matching_rule_use' => static::parseMatchingRuleUse($definition),
            default => static::parseGeneric($schemaType, $definition),
        };
    }

    public static function cleanDefinition(string $definition): string
    {
        $definition = trim($definition);

        $definition = preg_replace('/^\{\d+\}/', '', $definition) ?? $definition;

        $definition = preg_replace('/\s+/', ' ', $definition) ?? $definition;

        return trim($definition);
    }

    public static function ldapAttributeToSchemaType(string $attribute): ?string
    {
        return match ($attribute) {
            'attributeTypes' => 'attribute_type',
            'objectClasses' => 'object_class',
            'ldapSyntaxes' => 'ldap_syntax',
            'matchingRules' => 'matching_rule',
            'matchingRuleUse' => 'matching_rule_use',
            default => null,
        };
    }

    public static function schemaTypeToLdapAttribute(string $schemaType): string
    {
        return match ($schemaType) {
            'attribute_type' => 'olcAttributeTypes',
            'object_class' => 'olcObjectClasses',
            'ldap_syntax' => 'olcLdapSyntaxes',
            'matching_rule' => 'olcMatchingRules',
            'matching_rule_use' => 'olcMatchingRuleUse',
            default => 'olcAttributeTypes',
        };
    }

    public static function parseGeneric(string $schemaType, string $definition): array
    {
        $oid = static::oid($definition);
        $names = static::names($definition);

        return [
            'schema_type' => $schemaType,
            'oid' => $oid,
            'names' => $names,
            'primary_name' => $names[0] ?? $oid,
            'display_name' => static::humanName($names[0] ?? $oid),
            'description' => static::quotedAfter('DESC', $definition),
            'raw_definition' => $definition,
            'definition_hash' => sha1($schemaType.'|'.$definition),
        ];
    }

    public static function parseAttributeType(string $definition): array
    {
        $base = static::parseGeneric('attribute_type', $definition);

        return array_merge($base, [
            'kind' => str_contains($definition, ' USAGE directoryOperation')
                || str_contains($definition, ' USAGE dSAOperation')
                || str_contains($definition, ' NO-USER-MODIFICATION')
                    ? 'operational_attribute'
                    : 'user_attribute',
            'superior' => static::tokenAfter('SUP', $definition),
            'syntax_oid' => static::tokenAfter('SYNTAX', $definition),
            'equality_rule' => static::tokenAfter('EQUALITY', $definition),
            'ordering_rule' => static::tokenAfter('ORDERING', $definition),
            'substring_rule' => static::tokenAfter('SUBSTR', $definition),
            'is_single_value' => str_contains($definition, ' SINGLE-VALUE'),
            'is_operational' => str_contains($definition, ' NO-USER-MODIFICATION')
                || str_contains($definition, ' USAGE directoryOperation')
                || str_contains($definition, ' USAGE dSAOperation'),
            'is_obsolete' => str_contains($definition, ' OBSOLETE'),
        ]);
    }

    public static function parseObjectClass(string $definition): array
    {
        $base = static::parseGeneric('object_class', $definition);

        $kind = 'structural';

        if (str_contains($definition, ' AUXILIARY')) {
            $kind = 'auxiliary';
        } elseif (str_contains($definition, ' ABSTRACT')) {
            $kind = 'abstract';
        } elseif (str_contains($definition, ' STRUCTURAL')) {
            $kind = 'structural';
        }

        return array_merge($base, [
            'kind' => $kind,
            'superior' => static::tokenAfter('SUP', $definition),
            'must_attributes' => static::attributeListAfter('MUST', $definition),
            'may_attributes' => static::attributeListAfter('MAY', $definition),
            'is_obsolete' => str_contains($definition, ' OBSOLETE'),
        ]);
    }

    public static function parseLdapSyntax(string $definition): array
    {
        $base = static::parseGeneric('ldap_syntax', $definition);

        return array_merge($base, [
            'kind' => 'syntax',
            'syntax_oid' => $base['oid'] ?? null,
            'syntax_description' => $base['description'] ?? null,
            'is_obsolete' => str_contains($definition, ' OBSOLETE'),
        ]);
    }

    public static function parseMatchingRule(string $definition): array
    {
        $base = static::parseGeneric('matching_rule', $definition);

        return array_merge($base, [
            'kind' => 'matching_rule',
            'syntax_oid' => static::tokenAfter('SYNTAX', $definition),
            'is_obsolete' => str_contains($definition, ' OBSOLETE'),
        ]);
    }

    public static function parseMatchingRuleUse(string $definition): array
    {
        $base = static::parseGeneric('matching_rule_use', $definition);

        return array_merge($base, [
            'kind' => 'matching_rule_use',
            'applies_to_attributes' => static::attributeListAfter('APPLIES', $definition),
            'is_obsolete' => str_contains($definition, ' OBSOLETE'),
        ]);
    }

    public static function oid(string $definition): ?string
    {
        if (preg_match('/^\(\s*([^\s]+)/', $definition, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    public static function names(string $definition): array
    {
        if (preg_match("/NAME\s+\(\s*([^)]+)\s*\)/", $definition, $matches)) {
            return collect(preg_split('/\s+/', trim($matches[1])) ?: [])
                ->map(fn ($value): string => trim($value, " '\""))
                ->filter()
                ->values()
                ->all();
        }

        if (preg_match("/NAME\s+'([^']+)'/", $definition, $matches)) {
            return [trim($matches[1])];
        }

        return [];
    }

    public static function quotedAfter(string $keyword, string $definition): ?string
    {
        if (preg_match('/\b'.preg_quote($keyword, '/')."\s+'([^']*)'/", $definition, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    public static function tokenAfter(string $keyword, string $definition): ?string
    {
        if (preg_match('/\b'.preg_quote($keyword, '/').'\s+([^\s)]+)/', $definition, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    public static function attributeListAfter(string $keyword, string $definition): array
    {
        if (preg_match('/\b'.preg_quote($keyword, '/').'\s+\(\s*([^)]+)\s*\)/', $definition, $matches)) {
            return collect(explode('$', $matches[1]))
                ->map(fn ($value): string => trim($value))
                ->filter()
                ->values()
                ->all();
        }

        if (preg_match('/\b'.preg_quote($keyword, '/').'\s+([^\s)]+)/', $definition, $matches)) {
            return [trim($matches[1])];
        }

        return [];
    }

    public static function humanName(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $value = preg_replace('/(?<!^)[A-Z]/', ' $0', $value) ?? $value;

        return trim(ucwords(str_replace(['_', '-'], ' ', $value)));
    }
}
