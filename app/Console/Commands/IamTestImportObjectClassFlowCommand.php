<?php

namespace App\Console\Commands;

use App\Models\Operations\ImportTemplate;
use App\Services\Operations\ImportObjectClassService;
use App\Services\Operations\ImportTemplateGeneratorService;
use Illuminate\Console\Command;

class IamTestImportObjectClassFlowCommand extends Command
{
    protected $signature = 'iam:test-import-objectclass-flow';

    protected $description = 'Test Import Template Maker objectClass generation flow.';

    public function handle(): int
    {
        $objectClassService = app(ImportObjectClassService::class);

        $objectClasses = $objectClassService->normalizeObjectClasses([
            'top',
            'person',
            'organizationalPerson',
            'inetOrgPerson',
            'petraPerson',
        ]);

        $this->info('ObjectClasses: '.implode(', ', $objectClasses));
        $this->info('Required Attributes: '.implode(', ', $objectClassService->requiredAttributesFor($objectClasses)));
        $this->info('Allowed Attributes: '.implode(', ', $objectClassService->allowedAttributesFor($objectClasses)));

        $template = ImportTemplate::query()->firstOrCreate(
            ['name' => 'Petra Person Create CSV Template'],
            [
                'template_purpose' => 'create',
                'entry_type' => 'user',
                'file_format' => 'csv',
                'base_dn' => 'dc=petra,dc=ac,dc=id',
                'target_ou' => 'people',
                'rdn_attribute' => 'uid',
                'object_classes' => $objectClasses,
                'attributes' => ['petraNrp', 'petraAffiliation', 'petraFaculty', 'petraDepartment'],
                'multi_value_separator' => ';',
                'sample_rows' => 3,
                'status' => 'draft',
            ]
        );

        $template->forceFill([
            'object_classes' => $objectClasses,
            'attributes' => ['petraNrp', 'petraAffiliation', 'petraFaculty', 'petraDepartment'],
            'file_format' => 'csv',
            'template_purpose' => 'create',
            'entry_type' => 'user',
            'base_dn' => 'dc=petra,dc=ac,dc=id',
            'target_ou' => 'people',
            'rdn_attribute' => 'uid',
            'multi_value_separator' => ';',
            'sample_rows' => 3,
        ])->save();

        $result = app(ImportTemplateGeneratorService::class)->generate($template);

        $this->info('Generated: '.(($result['ok'] ?? false) ? 'YES' : 'NO'));
        $this->line('Path: '.($result['path'] ?? 'N/A'));

        $path = storage_path('app/private/'.($result['path'] ?? ''));

        if (! is_file($path)) {
            $path = storage_path('app/'.($result['path'] ?? ''));
        }

        if (is_file($path)) {
            $this->newLine();
            $this->info('Preview:');
            $this->line(implode("\n", array_slice(file($path, FILE_IGNORE_NEW_LINES), 0, 8)));
        } else {
            $this->error('Generated file not found.');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
