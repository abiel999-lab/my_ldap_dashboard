<?php

namespace App\Services\Operations;

use App\Services\Operations\ImportObjectClassService;

use App\Models\Operations\ImportTemplate;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportTemplateGeneratorService
{
    public function generate(ImportTemplate $template): array
    {
        $format = strtolower((string) $template->file_format);

        $content = match ($format) {
            'csv' => $this->generateCsv($template),
            'ldif' => $this->generateLdif($template),
            'json' => $this->generateJson($template),
            default => throw new \InvalidArgumentException('Unsupported template format: '.$format),
        };

        $extension = match ($format) {
            'csv' => 'csv',
            'ldif' => 'ldif',
            'json' => 'json',
            default => 'txt',
        };

        $safeName = Str::slug($template->name ?: 'import-template');
        $filename = $safeName.'_'.$template->template_purpose.'_'.$template->entry_type.'.'.$extension;
        $path = 'import-templates/'.$filename;

        Storage::disk('local')->put($path, $content);

        $privatePath = storage_path('app/private/'.$path);
        File::ensureDirectoryExists(dirname($privatePath));
        file_put_contents($privatePath, $content);

        $appPath = storage_path('app/'.$path);
        File::ensureDirectoryExists(dirname($appPath));
        file_put_contents($appPath, $content);

        $template->forceFill([
            'output_disk' => 'local',
            'output_path' => $path,
            'output_filename' => $filename,
            'output_size_bytes' => strlen($content),
            'output_hash' => hash('sha256', $content),
            'status' => 'generated',
            'message' => 'Template generated successfully.',
            'metadata' => array_merge($template->metadata ?? [], [
                'generated_at' => now()->toDateTimeString(),
                'format' => $format,
                'purpose' => $template->template_purpose,
                'entry_type' => $template->entry_type,
            ]),
        ])->save();

        return [
            'ok' => true,
            'message' => 'Template generated successfully.',
            'path' => $path,
            'filename' => $filename,
        ];
    }

    private function generateCsv(ImportTemplate $template): string
    {
        $purpose = strtolower((string) $template->template_purpose);
        $headers = $this->csvHeaders($template);

        $rows = [];
        $rows[] = $this->csvLine($headers);

        $sampleRows = max(1, min((int) $template->sample_rows, 20));

        for ($i = 1; $i <= $sampleRows; $i++) {
            $values = [];

            foreach ($headers as $header) {
                $values[] = $this->sampleValue($template, $header, $i, $purpose);
            }

            $rows[] = $this->csvLine($values);
        }

        return implode("\n", $rows)."\n";
    }

    private function generateLdif(ImportTemplate $template): string
    {
        $purpose = strtolower((string) $template->template_purpose);
        $sampleRows = max(1, min((int) $template->sample_rows, 20));
        $blocks = [];

        for ($i = 1; $i <= $sampleRows; $i++) {
            $dn = $this->sampleDn($template, $i);

            if ($purpose === 'delete') {
                $blocks[] = implode("\n", [
                    'dn: '.$dn,
                    'changetype: delete',
                ]);

                continue;
            }

            if (in_array($purpose, ['update', 'edit', 'modify'], true)) {
                $lines = [
                    'dn: '.$dn,
                    'changetype: modify',
                ];

                foreach ($this->editableAttributes($template) as $attribute) {
                    $lines[] = 'replace: '.$attribute;
                    $lines[] = $attribute.': '.$this->sampleValue($template, $attribute, $i, 'update');
                    $lines[] = '-';
                }

                $blocks[] = implode("\n", $lines);

                continue;
            }

            $lines = [
                'dn: '.$dn,
                'changetype: add',
            ];

            foreach ($this->objectClasses($template) as $objectClass) {
                $lines[] = 'objectClass: '.$objectClass;
            }

            foreach ($this->createAttributes($template) as $attribute) {
                if ($attribute === 'objectClass') {
                    continue;
                }

                $lines[] = $attribute.': '.$this->sampleValue($template, $attribute, $i, 'create');
            }

            $blocks[] = implode("\n", $lines);
        }

        return implode("\n\n", $blocks)."\n";
    }

    private function generateJson(ImportTemplate $template): string
    {
        $purpose = strtolower((string) $template->template_purpose);
        $sampleRows = max(1, min((int) $template->sample_rows, 20));
        $items = [];

        for ($i = 1; $i <= $sampleRows; $i++) {
            $dn = $this->sampleDn($template, $i);

            if ($purpose === 'delete') {
                $items[] = [
                    'action' => 'delete',
                    'dn' => $dn,
                    'identifier' => $this->sampleValue($template, $template->rdn_attribute ?: 'uid', $i, $purpose),
                ];

                continue;
            }

            $attributes = [];

            foreach ($this->createAttributes($template) as $attribute) {
                if ($attribute === 'objectClass') {
                    continue;
                }

                $attributes[$attribute] = $this->sampleValue($template, $attribute, $i, $purpose);
            }

            $items[] = [
                'action' => in_array($purpose, ['update', 'edit', 'modify'], true) ? 'update' : 'create',
                'dn' => $dn,
                'objectClass' => $this->objectClasses($template),
                'attributes' => $attributes,
            ];
        }

        return json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    private function csvHeaders(ImportTemplate $template): array
    {
        $purpose = strtolower((string) $template->template_purpose);

        if ($purpose === 'delete') {
            return ['action', 'dn', $template->rdn_attribute ?: 'uid'];
        }

        if (in_array($purpose, ['update', 'edit', 'modify'], true)) {
            return array_values(array_unique(array_merge(
                ['action', 'dn', $template->rdn_attribute ?: 'uid'],
                $this->editableAttributes($template)
            )));
        }

        return array_values(array_unique(array_merge(
            ['action', 'dn', $template->rdn_attribute ?: 'uid', 'ou', 'objectClass'],
            $this->createAttributes($template)
        )));
    }

    private function createAttributes(ImportTemplate $template): array
    {
        $default = match ($template->entry_type) {
            'group' => ['cn', 'description'],
            'ou' => ['ou', 'description'],
            default => ['cn', 'sn', 'givenName', 'mail', 'description'],
        };

        return array_values(array_unique(array_merge($default, $this->customAttributes($template))));
    }

    private function editableAttributes(ImportTemplate $template): array
    {
        $default = match ($template->entry_type) {
            'group' => ['description'],
            'ou' => ['description'],
            default => ['cn', 'sn', 'givenName', 'mail', 'description'],
        };

        return array_values(array_unique(array_merge($default, $this->customAttributes($template))));
    }

    private function objectClasses(ImportTemplate $template): array
    {
        $classes = $template->object_classes;

        if (is_string($classes)) {
            $classes = $this->splitList($classes);
        }

        if (! is_array($classes) || $classes === []) {
            $classes = match ($template->entry_type) {
                'group' => ['top', 'groupOfNames'],
                'ou' => ['top', 'organizationalUnit'],
                default => app(ImportObjectClassService::class)->defaultUserObjectClasses(),
            };
        }

        return app(ImportObjectClassService::class)->normalizeObjectClasses($classes);
    }

    private function customAttributes(ImportTemplate $template): array
    {
        $attributes = $template->attributes;

        if (is_string($attributes)) {
            $attributes = $this->splitList($attributes);
        }

        if (! is_array($attributes)) {
            return [];
        }

        return collect($attributes)
            ->map(fn ($item): string => trim((string) $item))
            ->filter()
            ->reject(fn (string $item): bool => in_array($item, [
                'action',
                'dn',
                'ou',
                'objectClass',
                $template->rdn_attribute ?: 'uid',
            ], true))
            ->unique()
            ->values()
            ->all();
    }

    private function splitList(string $value): array
    {
        return preg_split('/[\n,;|]+/', $value) ?: [];
    }

    private function sampleDn(ImportTemplate $template, int $index): string
    {
        $rdn = $template->rdn_attribute ?: 'uid';
        $identifier = $this->sampleValue($template, $rdn, $index, $template->template_purpose);
        $baseDn = trim((string) $template->base_dn);
        $ou = trim((string) $template->target_ou);

        if ($ou !== '') {
            return $rdn.'='.$identifier.',ou='.$ou.','.$baseDn;
        }

        return $rdn.'='.$identifier.','.$baseDn;
    }

    private function sampleValue(ImportTemplate $template, string $attribute, int $index, string $purpose): string
    {
        $attribute = trim($attribute);
        $number = str_pad((string) $index, 3, '0', STR_PAD_LEFT);

        if ($attribute === 'action') {
            return match ($purpose) {
                'delete' => 'delete',
                'update', 'edit', 'modify' => 'update',
                default => 'create',
            };
        }

        if ($attribute === 'dn') {
            return $this->sampleDn($template, $index);
        }

        if ($attribute === 'ou') {
            return trim((string) $template->target_ou) ?: 'people';
        }

        if ($attribute === 'objectClass') {
            return app(ImportObjectClassService::class)->csvObjectClassValue(
                $this->objectClasses($template),
                $template->multi_value_separator ?: ';'
            );
        }

        if ($attribute === 'uid') {
            return 'template.user'.$number;
        }

        if ($attribute === 'cn') {
            return 'Template User '.$number;
        }

        if ($attribute === 'sn') {
            return 'User '.$number;
        }

        if ($attribute === 'givenName') {
            return 'Template';
        }

        if ($attribute === 'mail') {
            return 'template.user'.$number.'@petra.ac.id';
        }

        if ($attribute === 'description') {
            return 'Generated from Import Template Maker';
        }

        if (str_contains(strtolower($attribute), 'nrp')) {
            return '1122'.$number;
        }

        if (str_contains(strtolower($attribute), 'affiliation')) {
            return 'student';
        }

        if (str_contains(strtolower($attribute), 'faculty')) {
            return 'Informatics';
        }

        if (str_contains(strtolower($attribute), 'department')) {
            return 'Information Systems';
        }

        return 'sample_'.$attribute.'_'.$number;
    }

    private function csvLine(array $values): string
    {
        return implode(',', array_map(function ($value): string {
            $value = (string) $value;
            $value = str_replace('"', '""', $value);

            if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
                return '"'.$value.'"';
            }

            return $value;
        }, $values));
    }
}
