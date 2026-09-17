<?php

// Only the temporary-file-upload block is overridden; Livewire falls back
// to its built-in defaults for every other setting (verified against
// Livewire\Features\SupportFileUploads\FileUploadConfiguration).
return [

    'temporary_file_upload' => [
        // The import wizard accepts full Excel/CSV workbooks; the default
        // Livewire cap (12MB) is too tight for real PDB exports. Kept below
        // the PHP post_max_size / upload_max_filesize headroom (68M/64M).
        // Streaming uploads (PUT /import/upload-stream) bypass Livewire's temp
        // file upload entirely, but the subsequent Livewire call
        // analyzeStreamedImport still reads the assembled workbook — that step
        // was OOM-killed at 128M on shared hosting (bare 500 on /livewire/update).
        // The server lifts to 512M per request (see ImportMappingService@analyze
        // and ManagedTable@analyzeStreamedImport), but the Livewire middleware
        // timeout must also allow >5s for a 5 MB workbook on a cold opcache.
        'rules' => ['required', 'file', 'max:256000'],
        'disk' => 'local',
        'directory' => 'livewire-tmp',
        'middleware' => null,
        'max_upload_time' => 60,
    ],

];
