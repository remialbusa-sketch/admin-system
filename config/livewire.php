<?php

// Only the temporary-file-upload block is overridden; Livewire falls back
// to its built-in defaults for every other setting (verified against
// Livewire\Features\SupportFileUploads\FileUploadConfiguration).
return [

    'temporary_file_upload' => [
        // The import wizard accepts full Excel/CSV workbooks; the default
        // Livewire cap (12MB) is too tight for real PDB exports. Kept below
        // the PHP post_max_size / upload_max_filesize headroom (68M/64M).
        'rules' => ['required', 'file', 'max:61440'],
        'disk' => 'local',
        'directory' => 'livewire-tmp',
        'middleware' => null,
        'max_upload_time' => 5,
    ],

];
